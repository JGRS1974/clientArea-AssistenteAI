<?php

namespace App\Http\Controllers\WhatsApp;

use App\Http\Controllers\Controller;
use App\Repositories\KwCacheRepository;
use App\Services\CanonicalConversationService;
use App\Services\RedisConversationService;
use App\Services\TextToSpeechService;
use App\Services\WhatsApp\EvolutionWebhookNormalizer;
use App\Services\WhatsApp\LoginLinkService;
use App\Services\WhatsApp\MessageChunker;
use App\Services\WhatsApp\WhatsAppMessageFormatter;
use App\Services\WhatsApp\WhatsAppSender;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class WhatsAppWebhookController extends Controller
{
    private EvolutionWebhookNormalizer $normalizer;
    private KwCacheRepository $kwRepo;
    private LoginLinkService $loginLinks;
    private CanonicalConversationService $canonicalConversations;
    private RedisConversationService $redisConversations;
    private TextToSpeechService $tts;
    private MessageChunker $chunker;
    private WhatsAppMessageFormatter $formatter;
    private WhatsAppSender $sender;

    public function __construct(
        EvolutionWebhookNormalizer $normalizer,
        KwCacheRepository $kwRepo,
        LoginLinkService $loginLinks,
        CanonicalConversationService $canonicalConversations,
        RedisConversationService $redisConversations,
        TextToSpeechService $tts,
        MessageChunker $chunker,
        WhatsAppMessageFormatter $formatter,
        WhatsAppSender $sender
    ) {
        $this->normalizer = $normalizer;
        $this->kwRepo = $kwRepo;
        $this->loginLinks = $loginLinks;
        $this->canonicalConversations = $canonicalConversations;
        $this->redisConversations = $redisConversations;
        $this->tts = $tts;
        $this->chunker = $chunker;
        $this->formatter = $formatter;
        $this->sender = $sender;
    }

    /**
     * Webhook de entrada da Evolution API.
     * Normaliza a mensagem e encaminha ao endpoint /api/chat.
     */
    public function incoming(Request $request)
    {

        $webhook = $request->all();

        // -----------------------------
        // Idempotência (dedupe webhook)
        // -----------------------------
        // A Evolution pode reenviar o mesmo evento (mesmo data.key.id) quando há timeout/erro no webhook.
        // Implementação em 2 fases:
        //  - processing: TTL curto (anti "tempestade" durante retries enquanto processa)
        //  - done: TTL longo (não reprocessar após concluir)
        $event = (string) data_get($webhook, 'event', '');
        $dedupeDoneTtl = (int) env('WA_WEBHOOK_DEDUPE_DONE_TTL_SECONDS', 3600);
        $dedupeProcessingTtl = (int) env('WA_WEBHOOK_DEDUPE_PROCESSING_TTL_SECONDS', 300);
        $dedupeEnabled = ($dedupeDoneTtl > 0 || $dedupeProcessingTtl > 0);

        $dedupeKeys = null;
        if ($dedupeEnabled) {
            $messageId = (string) data_get($webhook, 'data.key.id', '');
            $remoteJidAlt = (string) data_get($webhook, 'data.key.remoteJidAlt', '');
            $remoteJid = (string) data_get($webhook, 'data.key.remoteJid', '');
            $chatKey = $remoteJidAlt !== '' ? $remoteJidAlt : $remoteJid;
            $instance = (string) (data_get($webhook, 'instance') ?: env('EVOLUTION_INSTANCE', ''));

            // Só deduplica quando há identificador confiável
            if ($messageId !== '' && $chatKey !== '') {
                $base = 'wa:evo:' . ($instance !== '' ? $instance : 'unknown') . ':' . $chatKey . ':' . $messageId;
                $keyProcessing = $base . ':processing';
                $keyDone = $base . ':done';

                $dedupeKeys = [
                    'processing' => $keyProcessing,
                    'done' => $keyDone,
                    'meta' => [
                        'event' => $event,
                        'instance' => $instance,
                        'chatKey' => $chatKey,
                        'messageId' => $messageId,
                        'processing_ttl' => $dedupeProcessingTtl,
                        'done_ttl' => $dedupeDoneTtl,
                    ],
                ];

                // Se já concluímos esse messageId recentemente, ignore
                if ($dedupeDoneTtl > 0 && Cache::has($keyDone)) {
                    Log::info('Duplicate webhook ignored (done)', $dedupeKeys['meta']);
                    return response()->json(['ok' => true, 'duplicate' => true, 'state' => 'done']);
                }

                // Se alguém já está processando (ou acabamos de marcar), ignore para evitar reentrada
                if ($dedupeProcessingTtl > 0) {
                    if (!Cache::add($keyProcessing, 1, $dedupeProcessingTtl)) {
                        Log::info('Duplicate webhook ignored (processing)', $dedupeKeys['meta']);
                        return response()->json(['ok' => true, 'duplicate' => true, 'state' => 'processing']);
                    }
                }
            }
        }

        $dedupeFinalize = function (string $state = 'done') use (&$dedupeKeys, $dedupeDoneTtl): void {
            if (!is_array($dedupeKeys)) {
                return;
            }
            if ($state === 'done' && isset($dedupeKeys['done']) && is_string($dedupeKeys['done']) && $dedupeDoneTtl > 0) {
                Cache::put($dedupeKeys['done'], 1, $dedupeDoneTtl);
            }
            if (isset($dedupeKeys['processing']) && is_string($dedupeKeys['processing'])) {
                Cache::forget($dedupeKeys['processing']);
            }
        };

        $dedupeReturn = function (array $payload, int $status = 200) use ($dedupeFinalize) {
            // Mesmo em retornos antecipados, marque como "visto" para não reprocessar retries.
            $dedupeFinalize('done');
            return response()->json($payload, $status);
        };

        $onlyUpsert = filter_var(env('WA_WEBHOOK_PROCESS_ONLY_UPSERT', true), FILTER_VALIDATE_BOOLEAN);
        $eventNorm = strtolower(trim($event));
        $isUpsertEvent = ($eventNorm === '' || $eventNorm === 'messages.upsert');
        $isTrackingEvent = $this->isEvolutionTrackingEvent($eventNorm);

        if ($onlyUpsert && $eventNorm !== '' && !$isUpsertEvent && !$isTrackingEvent) {
            Log::info('Webhook ignored by event filter', [
                'event' => $event,
                'messageType' => data_get($webhook, 'data.messageType') ?? ($webhook['messageType'] ?? null),
            ]);
            return $dedupeReturn(['ok' => true, 'ignored_event' => $event]);
        }

        // Eventos de tracking (envio/status) não devem disparar pipeline do /api/chat.
        if ($isTrackingEvent) {
            return $this->handleEvolutionTrackingEvent($webhook, $dedupeReturn);
        }

        // Sanitiza logs para evitar despejar Base64 e payloads gigantes
        $meta = [
            'event' => $webhook['event'] ?? null,
            'sender' => $webhook['sender'] ?? null,
            'messageType' => data_get($webhook, 'data.messageType') ?? ($webhook['messageType'] ?? null),
            'hasAudioB64' => (bool) data_get($webhook, 'data.message.audioMessage.base64'),
            'hasImageB64' => (bool) data_get($webhook, 'data.message.imageMessage.base64'),
            'hasRootB64' => isset($webhook['base64']) && is_string($webhook['base64']) && $webhook['base64'] !== '',
            'hasMsgLevelB64' => (bool) data_get($webhook, 'data.message.base64'),
            'audioUrl' => data_get($webhook, 'data.message.audioMessage.url'),
            'audioMime' => data_get($webhook, 'data.message.audioMessage.mimetype'),
        ];
        Log::info('Evolution webhook recebido', $meta);
        //Log::info('weebhook',[$webhook]);
        $normalized = $this->normalizer->normalize($webhook);

        Log::info('Normalized summary', [
            'from_me' => (bool) ($normalized['from_me'] ?? false),
            'text_len' => is_string($normalized['text'] ?? null) ? strlen($normalized['text']) : 0,
            'media' => [
                'type' => $normalized['media']['type'] ?? null,
                'has_base64' => isset($normalized['media']['base64']),
                'base64_len' => isset($normalized['media']['base64']) ? strlen($normalized['media']['base64']) : 0,
                'has_url' => !empty($normalized['media']['url'] ?? null),
                'mimetype' => $normalized['media']['mimetype'] ?? null,
            ],
        ]);

        $instancePhone = preg_replace('/\D/', '', (string) env('WA_INSTANCE_PHONE', ''));
        $remoteJidDigits = preg_replace('/\D/', '', (string) data_get($webhook, 'data.key.remoteJid', ''));
        $remoteJidAltDigits = preg_replace('/\D/', '', (string) data_get($webhook, 'data.key.remoteJidAlt', ''));

        $fromMe = (bool) ($normalized['from_me'] ?? false);

        // Por padrão, não reprocessar mensagens enviadas pela própria instância (anti-loop).
        // Use WA_WEBHOOK_PROCESS_FROMME=true apenas para debugging.
        if ($fromMe && !(bool) env('WA_WEBHOOK_PROCESS_FROMME', false)) {
            Log::info('Webhook skipped: fromMe', [
                'sender' => $webhook['sender'] ?? null,
                'remoteJid' => data_get($webhook, 'data.key.remoteJid'),
                'remoteJidAlt' => data_get($webhook, 'data.key.remoteJidAlt'),
            ]);
            return $dedupeReturn(['ok' => true, 'skipped' => true, 'reason' => 'from_me']);
        }

        $hasMedia = !empty($normalized['media']);
        // Determina se é self chat pelo número da instância
        $phone = (string) ($normalized['phone'] ?? '');
        $recipientDigits = preg_replace('/\D/', '', $phone);
        $isSelfChat = ($instancePhone !== '' && $recipientDigits === $instancePhone);

        // Gate de manutenção (saída antecipada): responda com uma mensagem curta e ignore o processamento.
        try {
            if ($this->isMaintenanceOnForChannel('whatsapp')) {
                // Checar whitelist
                $whitelist = array_values(array_filter(array_map(function ($v) {
                    return preg_replace('/\D/', '', trim((string)$v));
                }, explode(',', (string) env('MAINTENANCE_WHITELIST', '')))));
                $num = preg_replace('/\D/', '', $phone);
                $isWhitelisted = ($num !== '' && in_array($num, $whitelist, true));

                // Responder a si mesmo?
                $respondSelf = $this->envBool('MAINTENANCE_RESPOND_SELF', true);
                if ($isWhitelisted || ($isSelfChat && !$respondSelf)) {
                    // Ignorar resposta de manutenção
                } else {
                    // Tempo de espera por número
                    $cooldown = (int) env('MAINTENANCE_COOLDOWN_SECONDS', 600);
                    $coolKey = 'wa:maint:last:' . ($num ?: 'unknown');
                    if ($cooldown > 0 && \Illuminate\Support\Facades\Cache::get($coolKey)) {
                        return $dedupeReturn(['ok' => true, 'maintenance' => true, 'skipped_by_cooldown' => true]);
                    }

                    $msg = $this->buildMaintenanceMessage('whatsapp');
                    if (is_string($msg) && trim($msg) !== '' && $phone !== '') {
                        $this->sender->sendText($phone, $msg);
                        if ($cooldown > 0) {
                            \Illuminate\Support\Facades\Cache::put($coolKey, 1, $cooldown);
                        }
                    }
                    return $dedupeReturn(['ok' => true, 'maintenance' => true]);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Maintenance gate (whatsapp) failed', ['error' => $e->getMessage()]);
        }

        // Novas flags de controle (Proposta A)
        $allowSelf = (bool) env('WA_INBOUND_ALLOW_SELF', false);
        $allowOthers = (bool) env('WA_INBOUND_ALLOW_OTHERS', true);
        $selfMediaOnly = (bool) env('WA_INBOUND_SELF_MEDIA_ONLY', false);
        $othersMediaOnly = (bool) env('WA_INBOUND_OTHERS_MEDIA_ONLY', false);

        $shouldProcess = false;
        if ($isSelfChat) {
            if ($allowSelf) {
                $shouldProcess = $selfMediaOnly ? $hasMedia : true;
            }
        } else {
            if ($allowOthers) {
                $shouldProcess = $othersMediaOnly ? $hasMedia : true;
            }
        }

        // Anti-loop: ignorar textos que são eco do que acabamos de enviar (cooldown curto).
        // Isso protege quando o webhook marca fromMe errado ou quando a Evolution "espelha" mensagens.
        $cooldown = (int) env('WA_ANTI_LOOP_FROMME_TEXT_COOLDOWN_SECONDS', 0);
        if (!$hasMedia && $cooldown > 0) {
            $textIn = $normalized['text'] ?? null;
            if (is_string($textIn) && trim($textIn) !== '' && $phone !== '') {
                $hashKey = 'wa:last_outgoing_text_hash:' . $phone;
                $lastHash = Cache::get($hashKey);
                $thisHash = md5($textIn);

                // Só aplica se houver algum indício de "eco" (self chat ou remoteJid apontando para a instância)
                $echoHint = $isSelfChat
                    || ($instancePhone !== '' && $remoteJidDigits !== '' && $remoteJidDigits === $instancePhone)
                    || ($instancePhone !== '' && $remoteJidAltDigits !== '' && $remoteJidAltDigits === $instancePhone);

                if ($echoHint && $lastHash && hash_equals($lastHash, $thisHash)) {
                    Log::info('Inbound text skipped by cooldown anti-loop', [
                        'phone' => $phone,
                        'is_self_chat' => $isSelfChat,
                    ]);
                    return $dedupeReturn(['ok' => true, 'skipped' => true, 'reason' => 'text_echo_cooldown']);
                }
            }
        }

        if (!$shouldProcess) {
            Log::info('Inbound gating skipped message', [
                'from_me' => $fromMe,
                'is_self_chat' => $isSelfChat,
                'has_media' => $hasMedia,
                'allow_self' => $allowSelf,
                'allow_others' => $allowOthers,
                'self_media_only' => $selfMediaOnly,
                'others_media_only' => $othersMediaOnly,
            ]);
            return $dedupeReturn(['ok' => true, 'skipped' => true, 'reason' => 'inbound_gating']);
        }

        $conversationId = $this->canonicalConversations->resolveForPhone($phone) ?? $phone;
        if ($phone === '') {
            Log::warning('WhatsApp webhook sem phone', $webhook);
            return $dedupeReturn(['ok' => true, 'skipped' => true, 'reason' => 'missing_phone']);
        }

        $replyWithAudio = (($normalized['media']['type'] ?? null) === 'audio');
        // Suprimir TTS em self chat para reduzir chance de loop
        if ($isSelfChat && (bool) env('WA_SELF_SUPPRESS_TTS', true)) {
            $replyWithAudio = false;
        }
        $kw = $this->kwRepo->get($conversationId);

        // Presença de "processando" (best effort). Evita sensação de travamento sem enviar mensagem.
        if ($replyWithAudio) {
            try {
                $this->sender->sendPresence($phone, 'composing', (int) env('WA_AUDIO_PRESENCE_DELAY_MS', 20000));
            } catch (\Throwable $e) {
                // ignora falha de presença
            }
        }

        // Monta chamada interna ao endpoint /api/chat
        $chatUri = url('/api/chat');
        $chatPayload = [
            'text' => $normalized['text'],
            'conversation_id' => $conversationId,
            'kw' => $kw,
        ];

        $files = [];
        $tmpPath = null;
        if ($normalized['media'] && is_array($normalized['media'])) {
            $m = $normalized['media'];
            try {
                Log::info('Media block', [
                    'type' => $m['type'] ?? null,
                    'mime' => $m['mimetype'] ?? null,
                    'base64_len' => isset($m['base64']) ? strlen($m['base64']) : 0,
                    'has_url' => !empty($m['url'] ?? null),
                ]);
                if (!empty($m['base64'])) {
                    $tmpPath = $this->saveBase64ToTemp($m['base64'], $m['mimetype'] ?? null, $m['type'] ?? null);
                } else {
                    // Evolução do fluxo: processamos apenas Base64 quando mídia é recebida do Evolution API
                    Log::warning('Mídia recebida sem Base64 (ignorada por política atual).', [
                        'type' => $m['type'] ?? null,
                        'has_url' => !empty($m['url']),
                    ]);
                }

                if ($tmpPath && file_exists($tmpPath)) {
                    // Sanitiza mimetype (remove sufixos como "; codecs=opus")
                    $mime = $m['mimetype'] ?? null;
                    if (is_string($mime)) {
                        $mime = trim(strtolower(strtok($mime, ';')));
                    }

                    // Se veio por URL, tenta adicionar extensão coerente para ajudar o fileinfo
                    if (empty($m['base64']) && $mime) {
                        $ext = $this->guessExtensionFromMime($mime);
                        if ($ext && !str_ends_with($tmpPath, '.' . $ext)) {
                            $tmpWithExt = $tmpPath . '.' . $ext;
                            @rename($tmpPath, $tmpWithExt);
                            Log::info('saveBase64ToTemp.add_ext', ['old' => $tmpPath, 'new' => $tmpWithExt, 'mime' => $mime]);
                            $tmpPath = $tmpWithExt;
                        }
                    }

                    // Usa a classe do Laravel para manter o modo de teste e evitar falha de validação "uploaded"
                    $uploaded = new \Illuminate\Http\UploadedFile($tmpPath, basename($tmpPath), $mime, \UPLOAD_ERR_OK, true);
                    Log::info('UploadedFile created', [
                        'field' => (($m['type'] ?? null) === 'audio') ? 'audio' : ((in_array($m['type'] ?? '', ['image','document'], true)) ? 'image' : 'unknown'),
                        'path' => $tmpPath,
                        'mime' => $mime,
                        'size' => @filesize($tmpPath),
                    ]);
                    // diagnóstico adicional: classe do objeto e validade percebida pela camada de upload
                    try {
                        Log::info('UploadedFile class check', [
                            'class' => get_class($uploaded),
                            'is_valid' => method_exists($uploaded, 'isValid') ? $uploaded->isValid() : null,
                            'error' => method_exists($uploaded, 'getError') ? $uploaded->getError() : null,
                        ]);
                    } catch (\Throwable $e) {
                        // ignora falhas de log
                    }
                    if (($m['type'] ?? null) === 'audio') {
                        $files['audio'] = $uploaded;
                    } elseif (in_array($m['type'] ?? '', ['image', 'document'], true)) {
                        $files['image'] = $uploaded; // controller já aceita PDF em 'image'
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Falha ao processar mídia WA', ['error' => $e->getMessage(), 'class' => get_class($e)]);
                if ($tmpPath && file_exists($tmpPath)) {
                    @unlink($tmpPath);
                }
            }
        }

        // Faz dispatch interno para o controlador atual
        $chatResponse = $this->dispatchToChat($chatPayload, $files);
        $payload = $chatResponse['json'] ?? [];

        if ($tmpPath && file_exists($tmpPath)) {
            @unlink($tmpPath);
        }

        // Se precisar de login, gera link e adiciona ao texto
        $loginUrl = null;
        if (($payload['login'] ?? false) === true) {
            $loginUrl = $this->loginLinks->forPhone($phone, 15);
        }

        $cpf = Cache::get("conv:{$conversationId}:last_cpf");
        if (!$cpf) {
            $cpf = $this->redisConversations->getMetadataField($conversationId, 'last_cpf');
        }

        if ($cpf) {
            if ($conversationId === $phone) {
                $canonical = $this->canonicalConversations->linkPhoneToCpf($phone, $cpf);
                $conversationId = $canonical;
            } else {
                $this->canonicalConversations->linkPhoneToCanonical($phone, $conversationId);
            }
        }

        // Converte payload do assistente em mensagens de texto
        $messages = $this->formatter->toTextMessages($payload, $loginUrl);
        $messages = $this->chunker->chunk($messages);

        // Fallback amigável caso o /api/chat não retorne conteúdo útil
        if (empty($messages) && $hasMedia) {
            $messages = [
                'Não consegui processar sua mídia agora. Posso ajudar você em mais alguma coisa? '
            ];
        }

        $baseText = $payload['text'] ?? ($messages[0] ?? '');
        $cleanBaseText = trim(strip_tags(str_ireplace(['<br>', '<br/>', '<br />'], "\n", (string) $baseText)));
        $shouldReplyWithAudio = ($replyWithAudio && $cleanBaseText !== '');

        // Gating de saída (para quem enviar) + whitelist
        $outAllowSelf = (bool) env('WA_OUTBOUND_ALLOW_SELF', false);
        $outAllowOthers = (bool) env('WA_OUTBOUND_ALLOW_OTHERS', true);
        $whitelistStr = (string) env('WA_OUTBOUND_WHITELIST', '');
        $whitelist = array_values(array_filter(array_map(fn($v) => preg_replace('/\D/', '', trim($v)), explode(',', $whitelistStr))));
        $recipientIsSelf = $isSelfChat; // destinatário é o próprio número da instância?

        $canSend = $recipientIsSelf ? $outAllowSelf : $outAllowOthers;
        if ($canSend && !empty($whitelist)) {
            $num = preg_replace('/\D/', '', $phone);
            $canSend = in_array($num, $whitelist, true);
        }
        if (!$canSend) {
            Log::info('Outbound gating prevented send', [
                'to' => $phone,
                'recipient_is_self' => $recipientIsSelf,
                'out_allow_self' => $outAllowSelf,
                'out_allow_others' => $outAllowOthers,
                'whitelist' => $whitelist,
            ]);
            return $dedupeReturn(['ok' => true, 'skipped' => true, 'reason' => 'outbound_gating']);
        }

        // Se a entrada foi áudio, tenta responder somente com áudio (PTT)..
        // Se falhar, pode cair no fluxo de texto como fallback. Porém, em caso de timeout no envio
        // do áudio, tratamos como estado "indeterminado" (o Evolution pode enviar mesmo assim).
        if ($shouldReplyWithAudio) {
            $audio = $this->tts->synthesize($cleanBaseText, $phone);
            if ($audio && !empty($audio['url'])) {
                $pendingTtl = (int) env('WA_OUT_AUDIO_PENDING_TTL_SECONDS', 600);
                $pendingKey = $this->pendingOutAudioKey($phone);
                $followupMessages = $this->filterPostAudioMessages($messages);
                $followupMessages = $this->removeAudioBaseTextFromFollowup($followupMessages, $cleanBaseText);
                $pending = [
                    'phone' => $phone,
                    'created_at' => now()->timestamp,
                    'audio_text' => $cleanBaseText,
                    'followup_messages' => $followupMessages,
                    'followup_sent' => false,
                    'message_id' => null,
                ];
                if ($pendingTtl > 0) {
                    Cache::put($pendingKey, $pending, now()->addSeconds($pendingTtl));
                }

                $result = $this->sender->sendAudioByUrl($phone, $audio['url'], true);
                $sent = (bool) ($result['success'] ?? false);
                $messageId = data_get($result, 'result.key.id') ?: data_get($result, 'result.key.id', null);
                if (is_string($messageId) && $messageId !== '' && $pendingTtl > 0) {
                    Cache::put($this->pendingOutAudioByMsgIdKey($messageId), $phone, now()->addSeconds($pendingTtl));
                    $pending['message_id'] = $messageId;
                    Cache::put($pendingKey, $pending, now()->addSeconds($pendingTtl));
                }
                Log::info('sendWhatsAppAudio result', [
                    'to' => $phone,
                    'success' => $sent,
                    'httpcode' => $result['httpcode'] ?? null,
                    'error_type' => $result['error_type'] ?? null,
                ]);
                if ($sent) {
                    $delayMs = (int) env('WA_AUDIO_FOLLOWUP_DELAY_MS', 2500);
                    if ($delayMs > 0) {
                        usleep(min($delayMs, 15000) * 1000);
                    }

                    $this->sendFollowUpForPendingAudio($phone);
                    return $dedupeReturn(['ok' => true, 'audio_sent' => true, 'followup' => true]);
                }

                // Timeout do cURL: o Evolution pode enviar o áudio mesmo assim.
                // Não dispare fallback de texto imediatamente para evitar "texto antes do áudio".
                if (($result['error_type'] ?? null) === 'timeout') {
                    return $dedupeReturn(['ok' => true, 'audio_pending' => true, 'reason' => 'audio_send_timeout']);
                }

                // Falha real: remove pendência para não disparar follow-up indevido.
                Cache::forget($pendingKey);
            } else {
                Log::info('sendWhatsAppAudio skipped: TTS not available', [
                    'to' => $phone,
                ]);
            }
        }

        // TTL para anti-loop de eco de texto
        $cooldown = (int) env('WA_ANTI_LOOP_FROMME_TEXT_COOLDOWN_SECONDS', 0);

        foreach ($messages as $msg) {
            if (is_string($msg) && str_starts_with($msg, '__BUTTON__|')) {
                [$title, $url] = $this->parseButtonInstruction($msg);
                if ($title !== null && $url !== null) {
                    //$buttons = [[
                    //    'type' => 'url',
                    //    'title' => $title,
                    //    'url' => $url,
                    //]];
                    $buttons = [[
                        'title' => 'Clique para abrir o PDF:',
                        'url' => $url
                    ]];
                    $this->sender->sendButton($phone, 'Clique para abrir o PDF:', $buttons);
                    usleep(200000);
                    continue;
                }
            }

            $this->sender->sendText($phone, $msg);
            // Registra hash de saída para anti-loop (somente texto)
            if ($cooldown > 0 && is_string($msg) && trim($msg) !== '') {
                $hashKey = 'wa:last_outgoing_text_hash:' . ($phone ?: 'unknown');
                Cache::put($hashKey, md5($msg), $cooldown);
            }
            usleep(200000);
        }

        $dedupeFinalize('done');
        return response()->json(['ok' => true]);
    }

    private function dispatchToChat(array $data, array $files = []): array
    {
        // Cria uma Request fake para utilizar o pipeline do Laravel internamente
        $appUrl = config('app.url') ?: (env('APP_URL') ?: 'http://loalhost');
        $parts = parse_url($appUrl) ?: [];
        $scheme = $parts['scheme'] ?? 'http';
        $hostOnly = $parts['host'] ?? '127.0.0.1';
        $port = $parts['port'] ?? (($scheme === 'https') ? 443 : 8000);
        $hostHeader = $hostOnly . (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80) ? (':' . $port) : '');

        $server = [
            'CONTENT_TYPE' => 'multipart/form-data',
            // Headers para forçar resposta JSON (evitar 302 de validação)
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
            // Canal de origem (para o classificador de intenção)
            'HTTP_X_CHANNEL' => 'whatsapp',
            // Contexto de host/esquema para geração correta de URLs internas
            'HTTP_HOST' => $hostHeader,
            'SERVER_NAME' => $hostOnly,
            'SERVER_PORT' => (string) $port,
            'REQUEST_SCHEME' => $scheme,
            'HTTPS' => $scheme === 'https' ? 'on' : 'off',
        ];
        Log::info('Dispatch to /api/chat', [
            'has_audio_file' => isset($files['audio']),
            'has_image_file' => isset($files['image']),
            'audio_info' => isset($files['audio']) ? [
                'clientMime' => $files['audio']->getClientMimeType(),
                'size' => $files['audio']->getSize(),
                'originalName' => $files['audio']->getClientOriginalName(),
            ] : null,
            'image_info' => isset($files['image']) ? [
                'clientMime' => $files['image']->getClientMimeType(),
                'size' => $files['image']->getSize(),
                'originalName' => $files['image']->getClientOriginalName(),
            ] : null,
        ]);
        $symfony = Request::create('/api/chat', 'POST', $data, [], $files, $server);
        $response = app()->handle($symfony);

        $content = $response->getContent();
        $json = null;
        try {
            $json = json_decode($content, true);
        } catch (\Throwable $e) {
            $json = null;
        }

        $status = $response->getStatusCode();
        if ($status >= 400 || $json === null) {
            Log::warning('Chat dispatch issue', [
                'status' => $status,
                'content_head' => is_string($content) ? mb_substr($content, 0, 512) : null,
                'hint' => 'Se 400 e has_media, provavelmente não gerou UploadedFile',
            ]);
        }

        return ['status' => $status, 'json' => $json];
    }

    private function downloadToTemp(string $url): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'wa_');
        $ch = curl_init($url);
        $fp = fopen($tmp, 'wb');
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_exec($ch);
        curl_close($ch);
        fclose($fp);
        return $tmp;
    }

    private function saveBase64ToTemp(string $base64, ?string $mimetype = null, ?string $kind = null): string
    {
        Log::info('saveBase64ToTemp.start', [
            'kind' => $kind,
            'mimetype' => $mimetype,
            'incoming_len' => strlen($base64),
            'has_header_prefix' => str_contains($base64, ';base64,'),
        ]);
        // Remove prefixo data:...;base64, se existir
        if (str_contains($base64, ',')) {
            [$maybeHeader, $maybeData] = explode(',', $base64, 2);
            if (str_contains($maybeHeader, ';base64')) {
                $base64 = $maybeData;
            }
        }

        $binary = base64_decode($base64, true);
        if ($binary === false) {
            throw new \RuntimeException('Base64 inválido');
        }

        Log::info('saveBase64ToTemp.decoded', [
            'binary_len' => strlen($binary),
            'limit' => ($kind === 'audio') ? 30 * 1024 * 1024 : 8 * 1024 * 1024,
        ]);

        // Limites defensivos
        $maxBytes = ($kind === 'audio') ? 30 * 1024 * 1024 : 8 * 1024 * 1024;
        if (strlen($binary) > $maxBytes) {
            throw new \RuntimeException('Mídia acima do limite permitido');
        }

        $ext = $this->guessExtensionFromMime($mimetype) ?? 'bin';
        $tmp = tempnam(sys_get_temp_dir(), 'wa_');
        $tmpWithExt = $tmp . '.' . $ext;

        file_put_contents($tmpWithExt, $binary, LOCK_EX);
        Log::info('saveBase64ToTemp.written', [
            'path' => $tmpWithExt,
            'ext' => $ext,
            'filesize' => @filesize($tmpWithExt),
        ]);
        return $tmpWithExt;
    }

    private function guessExtensionFromMime(?string $mime): ?string
    {
        if (!$mime) return null;
        $mime = trim(strtolower(strtok($mime, ';'))); // remove sufixos
        switch ($mime) {
            case 'audio/mpeg':
            case 'audio/mp3':
                return 'mp3';
            case 'audio/ogg':
                return 'ogg';
            case 'audio/webm':
                return 'webm';
            case 'audio/wav':
            case 'audio/x-wav':
            case 'audio/wave':
                return 'wav';
            case 'audio/m4a':
            case 'audio/x-m4a':
                return 'm4a';
            case 'audio/aac':
                return 'aac';
            case 'image/jpeg':
            case 'image/jpg':
                return 'jpg';
            case 'image/png':
                return 'png';
            case 'application/pdf':
                return 'pdf';
            default:
                return null;
        }
    }

    private function parseButtonInstruction(string $message): array
    {
        $parts = explode('|', $message, 3);
        if (count($parts) === 3 && $parts[0] === '__BUTTON__') {
            return [$parts[1] ?: null, $parts[2] ?: null];
        }

        return [null, null];
    }

    // ==========================================
    // Evolution tracking helpers (outgoing áudio)
    // ==========================================
    private function isEvolutionTrackingEvent(string $eventNorm): bool
    {
        if ($eventNorm === '') {
            return false;
        }

        // Nomes comuns no webhook (v1) e equivalentes do v2 (varia por provider).
        if ($eventNorm === 'messages.update' || $eventNorm === 'message.update' || $eventNorm === 'messages_update') {
            return true;
        }
        if ($eventNorm === 'send.message' || $eventNorm === 'send.messages' || $eventNorm === 'send_message' || $eventNorm === 'send_messages' || $eventNorm === 'sendmessage') {
            return true;
        }

        return str_contains($eventNorm, 'messages.update')
            || str_contains($eventNorm, 'messages_update')
            || str_contains($eventNorm, 'send.message')
            || str_contains($eventNorm, 'send_message');
    }

    private function pendingOutAudioKey(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?: 'unknown';
        return 'wa:out_audio_pending:' . $digits;
    }

    private function pendingOutAudioByMsgIdKey(string $messageId): string
    {
        return 'wa:out_audio_pending_by_msgid:' . $messageId;
    }

    private function extractDigitsFromRemoteJid(string $remoteJid): string
    {
        return preg_replace('/\D/', '', $remoteJid) ?: '';
    }

    private function isWebhookAudioMessage(array $webhook): bool
    {
        $type = strtolower(trim((string) (data_get($webhook, 'data.messageType')
            ?? data_get($webhook, 'messageType')
            ?? data_get($webhook, 'type')
            ?? '')));

        if ($type !== '' && str_contains($type, 'audio')) {
            return true;
        }

        return (bool) (data_get($webhook, 'data.message.audioMessage')
            ?? data_get($webhook, 'message.audioMessage')
            ?? data_get($webhook, 'data.message.audio')
            ?? data_get($webhook, 'message.audio'));
    }

    private function extractMessageStatusFromTrackingWebhook(array $webhook): ?string
    {
        $candidates = [
            data_get($webhook, 'data.update.status'),
            data_get($webhook, 'data.update.messageStatus'),
            data_get($webhook, 'data.status'),
            data_get($webhook, 'status'),
        ];

        foreach ($candidates as $v) {
            if (is_string($v) && trim($v) !== '') {
                return strtoupper(trim($v));
            }
        }

        $update = data_get($webhook, 'data.update');
        if (is_array($update)) {
            foreach (['status', 'messageStatus'] as $k) {
                if (isset($update[$k]) && is_string($update[$k]) && trim($update[$k]) !== '') {
                    return strtoupper(trim($update[$k]));
                }
            }
        }

        return null;
    }

    private function findMessageListFromEvolutionResult(array $result): array
    {
        $root = $result['result'] ?? null;
        if (!is_array($root)) {
            return [];
        }

        foreach (['response', 'messages', 'data', 'result'] as $k) {
            if (isset($root[$k]) && is_array($root[$k])) {
                return $root[$k];
            }
        }

        $isList = array_keys($root) === range(0, count($root) - 1);
        return $isList ? $root : [];
    }

    private function confirmOutgoingAudioMessageId(string $remoteJid, string $messageId): bool
    {
        if ($remoteJid === '' || $messageId === '') {
            return false;
        }

        try {
            $res = $this->sender->findMessages($remoteJid, 10);
            if (!(bool) ($res['success'] ?? false)) {
                return false;
            }
            $list = $this->findMessageListFromEvolutionResult($res);
            foreach ($list as $msg) {
                if (!is_array($msg)) {
                    continue;
                }
                $id = (string) (data_get($msg, 'key.id') ?? '');
                $fromMe = (bool) (data_get($msg, 'key.fromMe') ?? false);
                if ($id !== $messageId || !$fromMe) {
                    continue;
                }

                $type = strtolower((string) (data_get($msg, 'messageType') ?? ''));
                $hasAudio = (bool) (data_get($msg, 'message.audioMessage') ?? data_get($msg, 'message.audio'));
                if ($hasAudio || ($type !== '' && str_contains($type, 'audio'))) {
                    return true;
                }
            }
        } catch (\Throwable $e) {
            return false;
        }

        return false;
    }

    private function handleEvolutionTrackingEvent(array $webhook, \Closure $dedupeReturn)
    {
        $fromMe = (bool) (data_get($webhook, 'data.key.fromMe')
            ?? data_get($webhook, 'key.fromMe')
            ?? data_get($webhook, 'data.message.key.fromMe')
            ?? data_get($webhook, 'data.update.key.fromMe')
            ?? false);

        if (!$fromMe) {
            return $dedupeReturn(['ok' => true, 'tracking' => true, 'ignored' => true, 'reason' => 'not_from_me']);
        }

        $remoteJid = (string) (data_get($webhook, 'data.key.remoteJid')
            ?? data_get($webhook, 'key.remoteJid')
            ?? data_get($webhook, 'data.message.key.remoteJid')
            ?? data_get($webhook, 'data.update.key.remoteJid')
            ?? '');

        $phone = $this->extractDigitsFromRemoteJid($remoteJid);
        if ($phone === '') {
            return $dedupeReturn(['ok' => true, 'tracking' => true, 'ignored' => true, 'reason' => 'missing_remoteJid']);
        }

        $eventNorm = strtolower(trim((string) (data_get($webhook, 'event') ?? ($webhook['event'] ?? ''))));

        $pendingTtl = (int) env('WA_OUT_AUDIO_PENDING_TTL_SECONDS', 600);
        $messageId = (string) (data_get($webhook, 'data.key.id') ?? data_get($webhook, 'key.id') ?? '');
        if ($messageId !== '' && $pendingTtl > 0) {
            $mappedPhone = Cache::get($this->pendingOutAudioByMsgIdKey($messageId));
            if (is_string($mappedPhone) && $mappedPhone !== '') {
                $phone = $mappedPhone;
            }
        }

        $pendingKey = $this->pendingOutAudioKey($phone);
        $pending = Cache::get($pendingKey);
        if (!is_array($pending)) {
            return $dedupeReturn(['ok' => true, 'tracking' => true, 'ignored' => true, 'reason' => 'no_pending_audio']);
        }

        $webhookSaysAudio = $this->isWebhookAudioMessage($webhook);
        $status = $this->extractMessageStatusFromTrackingWebhook($webhook);
        $isAck = ($status !== null && $status !== 'PENDING');

        $pendingMsgId = isset($pending['message_id']) && is_string($pending['message_id']) ? $pending['message_id'] : null;
        $messageMatchesPending = ($pendingMsgId !== null && $pendingMsgId !== '' && $messageId !== '' && $pendingMsgId === $messageId);

        $shouldTrigger = false;
        if ($webhookSaysAudio) {
            $shouldTrigger = true;
        } elseif ($messageMatchesPending && ($isAck || str_contains($eventNorm, 'messages'))) {
            $shouldTrigger = true;
        } elseif (($pendingMsgId === null || $pendingMsgId === '') && $messageId !== '' && $isAck) {
            // Caso clássico: timeout no sendWhatsAppAudio e só recebemos updates sem payload de áudio.
            if ($this->confirmOutgoingAudioMessageId($remoteJid, $messageId)) {
                $shouldTrigger = true;
                $pending['message_id'] = $messageId;
                if ($pendingTtl > 0) {
                    Cache::put($pendingKey, $pending, now()->addSeconds($pendingTtl));
                    Cache::put($this->pendingOutAudioByMsgIdKey($messageId), $phone, now()->addSeconds($pendingTtl));
                }
            }
        }

        if (!$shouldTrigger) {
            return $dedupeReturn([
                'ok' => true,
                'tracking' => true,
                'ignored' => true,
                'reason' => 'not_confirmed',
                'status' => $status,
                'message_id' => $messageId !== '' ? $messageId : null,
            ]);
        }

        if ($messageId !== '' && $pendingTtl > 0) {
            Cache::put($this->pendingOutAudioByMsgIdKey($messageId), $phone, now()->addSeconds($pendingTtl));
        }

        $delayMs = (int) env('WA_AUDIO_FOLLOWUP_DELAY_MS', 2500);
        if ($delayMs > 0) {
            usleep(min($delayMs, 15000) * 1000);
        }

        $sent = $this->sendFollowUpForPendingAudio($phone);
        return $dedupeReturn([
            'ok' => true,
            'tracking' => true,
            'followup_sent' => $sent,
            'phone' => $phone,
            'message_id' => $messageId !== '' ? $messageId : null,
        ]);
    }

    private function filterPostAudioMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $msg) {
            if (!is_string($msg)) {
                continue;
            }
            $text = trim($msg);
            if ($text === '') {
                continue;
            }

            if (str_starts_with($text, '__BUTTON__|')) {
                $out[] = $text;
                continue;
            }

            // URLs e instruções relacionadas a PDF (acionáveis)
            if (preg_match('~https?://~i', $text)) {
                $out[] = $text;
                continue;
            }
            if (stripos($text, 'pdf') !== false && (stripos($text, 'clique') !== false || stripos($text, 'link') !== false)) {
                $out[] = $text;
                continue;
            }

            // Linha digitável / boleto (heurística)
            if (preg_match('/\d{5}\.\d{5}\s+\d{5}\.\d{6}\s+\d{5}\.\d{6}\s+\d\s+\d{11,14}/', $text)) {
                $out[] = $text;
                continue;
            }
            if (preg_match('/^\d[\d\s\.\-]{30,}$/', $text)) {
                $out[] = $text;
                continue;
            }

            // Dados curtos e acionáveis do boleto
            if (stripos($text, 'venc') !== false || stripos($text, 'valor') !== false || stripos($text, 'linha digit') !== false) {
                $out[] = $text;
                continue;
            }
        }

        // Remove duplicatas preservando ordem
        $seen = [];
        $unique = [];
        foreach ($out as $t) {
            $k = md5($t);
            if (isset($seen[$k])) continue;
            $seen[$k] = true;
            $unique[] = $t;
        }

        return $unique;
    }

    private function normalizeTextForDedupe(string $text): string
    {
        $t = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $text);
        $t = strip_tags($t);
        $t = html_entity_decode($t, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $t = preg_replace('/\s+/', ' ', $t ?? '');
        $t = trim((string) ($t ?? ''));
        $t = mb_strtolower($t, 'UTF-8');
        $t = rtrim($t, " \t\n\r\0\x0B.");
        return $t;
    }

    private function removeAudioBaseTextFromFollowup(array $followupMessages, string $audioBaseText): array
    {
        $audioNorm = $this->normalizeTextForDedupe($audioBaseText);
        if ($audioNorm === '') {
            return $followupMessages;
        }

        $out = [];
        foreach ($followupMessages as $msg) {
            if (!is_string($msg)) {
                continue;
            }
            $msgNorm = $this->normalizeTextForDedupe($msg);
            if ($msgNorm !== '' && $msgNorm === $audioNorm) {
                continue;
            }
            $out[] = $msg;
        }

        return $out;
    }

    private function sendFollowUpForPendingAudio(string $phone): bool
    {
        $pendingKey = $this->pendingOutAudioKey($phone);
        $pending = Cache::get($pendingKey);
        if (!is_array($pending)) {
            return false;
        }

        $createdAt = (int) ($pending['created_at'] ?? 0);
        $doneKey = 'wa:out_audio_followup_done:' . preg_replace('/\D/', '', $phone) . ':' . ($createdAt ?: '0');
        if (Cache::has($doneKey)) {
            return false;
        }

        $followup = $pending['followup_messages'] ?? [];
        if (!is_array($followup) || empty($followup)) {
            // Nada a enviar: considere concluído e remova pendência.
            Cache::put($doneKey, 1, now()->addSeconds((int) env('WA_OUT_AUDIO_PENDING_TTL_SECONDS', 600)));
            Cache::forget($pendingKey);
            return false;
        }

        $cooldown = (int) env('WA_ANTI_LOOP_FROMME_TEXT_COOLDOWN_SECONDS', 0);
        foreach ($followup as $msg) {
            if (is_string($msg) && str_starts_with($msg, '__BUTTON__|')) {
                [$title, $url] = $this->parseButtonInstruction($msg);
                if ($title !== null && $url !== null) {
                    $buttons = [[
                        'title' => 'Clique para abrir o PDF:',
                        'url' => $url,
                    ]];
                    $this->sender->sendButton($phone, 'Clique para abrir o PDF:', $buttons);
                    usleep(200000);
                    continue;
                }
            }

            $this->sender->sendText($phone, (string) $msg);

            if ($cooldown > 0 && is_string($msg) && trim($msg) !== '') {
                $hashKey = 'wa:last_outgoing_text_hash:' . ($phone ?: 'unknown');
                Cache::put($hashKey, md5($msg), $cooldown);
            }
            usleep(200000);
        }

        Cache::put($doneKey, 1, now()->addSeconds((int) env('WA_OUT_AUDIO_PENDING_TTL_SECONDS', 600)));
        Cache::forget($pendingKey);
        return true;
    }

    // ============================
    // Maintenance helpers (WhatsApp)
    // ============================
    private function envBool(string $key, bool $default = false): bool
    {
        $val = env($key);
        if ($val === null) return $default;
        $str = strtolower(trim((string) $val));
        return in_array($str, ['1', 'true', 'on', 'yes'], true);
    }

    private function isMaintenanceOnForChannel(string $channel): bool
    {
        if (!$this->envBool('MAINTENANCE_ON', false)) {
            return false;
        }
        $channelsStr = strtolower((string) env('MAINTENANCE_CHANNELS', 'all'));
        $channels = array_values(array_filter(array_map('trim', explode(',', $channelsStr))));
        if (empty($channels) || in_array('all', $channels, true)) {
            return true;
        }
        return in_array(strtolower($channel), $channels, true);
    }

    private function buildMaintenanceMessage(string $channel): string
    {
        $tz = (string) (env('MAINTENANCE_TZ', config('app.timezone') ?: 'UTC'));
        $greet = $this->makeGreeting($tz);
        $variant = strtolower((string) env('MAINTENANCE_VARIANT', 'default'));
        $until = trim((string) env('MAINTENANCE_UNTIL', ''));
        $statusUrl = trim((string) env('MAINTENANCE_STATUS_URL', ''));

        switch ($variant) {
            case 'planned':
                $msg = "Olá, {$greet}! Estamos em manutenção programada";
                if ($until !== '') {
                    $msg .= " até {$until}";
                }
                $msg .= ". Assim que normalizar, você poderá solicitar novamente. Obrigado pela compreensão.";
                return $msg;
            case 'incident':
                $base = "Olá, {$greet}! Identificamos uma instabilidade e estamos trabalhando na correção. Tente novamente em alguns minutos. Agradecemos a paciência.";
                if ($statusUrl !== '') {
                    $base .= "\n" . $statusUrl;
                }
                return $base;
            case 'degraded':
                return "Olá, {$greet}! Estamos com instabilidade. Algumas consultas podem não funcionar agora. Por favor, tente mais tarde.";
            default:
                return "Olá, {$greet}! No momento não é possível processar sua solicitação devido a manutenções no sistema. Por favor, tente mais tarde. Obrigado.";
        }
    }

    private function makeGreeting(string $tz): string
    {
        try {
            $h = (int) now($tz)->format('G'); // 0-23
        } catch (\Throwable $e) {
            $h = (int) now()->format('G');
        }
        if ($h >= 18 || $h < 5) return 'boa noite';
        if ($h <= 11) return 'bom dia';
        return 'boa tarde';
    }
}
