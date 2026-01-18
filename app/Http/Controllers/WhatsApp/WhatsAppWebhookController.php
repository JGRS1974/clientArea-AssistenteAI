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
    public function __construct(
        private EvolutionWebhookNormalizer $normalizer,
        private KwCacheRepository $kwRepo,
        private LoginLinkService $loginLinks,
        private CanonicalConversationService $canonicalConversations,
        private RedisConversationService $redisConversations,
        private TextToSpeechService $tts,
        private MessageChunker $chunker,
        private WhatsAppMessageFormatter $formatter,
        private WhatsAppSender $sender,
    ) {
    }

    /**
     * Webhook de entrada da Evolution API.
     * Normaliza a mensagem e encaminha ao endpoint /api/chat.
     */
    public function incoming(Request $request)
    {

        $webhook = $request->all();
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

        $fromMe = (bool) ($normalized['from_me'] ?? false);
        $hasMedia = !empty($normalized['media']);
        // Determina se é self chat pelo número da instância
        $phone = (string) ($normalized['phone'] ?? '');
        $instancePhone = preg_replace('/\D/', '', (string) env('WA_INSTANCE_PHONE', ''));
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
                        return response()->json(['ok' => true, 'maintenance' => true, 'skipped_by_cooldown' => true]);
                    }

                    $msg = $this->buildMaintenanceMessage('whatsapp');
                    if (is_string($msg) && trim($msg) !== '' && $phone !== '') {
                        $this->sender->sendText($phone, $msg);
                        if ($cooldown > 0) {
                            \Illuminate\Support\Facades\Cache::put($coolKey, 1, $cooldown);
                        }
                    }
                    return response()->json(['ok' => true, 'maintenance' => true]);
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

        // Anti-loop: ignorar textos de self chat repetidos enviados recentemente (cooldown)
        $cooldown = (int) env('WA_ANTI_LOOP_FROMME_TEXT_COOLDOWN_SECONDS', 0);
        if ($isSelfChat && !$hasMedia && $cooldown > 0) {
            $textIn = $normalized['text'] ?? null;
            if (is_string($textIn) && $textIn !== '') {
                $phoneKey = $phone ?: 'unknown';
                $hashKey = 'wa:last_outgoing_text_hash:' . $phoneKey;
                $lastHash = Cache::get($hashKey);
                $thisHash = md5($textIn);
                if ($lastHash && hash_equals($lastHash, $thisHash)) {
                    Log::info('Inbound self-chat text skipped by cooldown anti-loop', [
                        'phone' => $phoneKey,
                    ]);
                    return response()->json(['ok' => true]);
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
            return response()->json(['ok' => true]);
        }

        $conversationId = $this->canonicalConversations->resolveForPhone($phone) ?? $phone;
        if ($phone === '') {
            Log::warning('WhatsApp webhook sem phone', $webhook);
            return response()->json(['ok' => true]);
        }

        $replyWithAudio = (($normalized['media']['type'] ?? null) === 'audio');
        // Suprimir TTS em self chat para reduzir chance de loop
        if ($isSelfChat && (bool) env('WA_SELF_SUPPRESS_TTS', true)) {
            $replyWithAudio = false;
        }
        $kw = $this->kwRepo->get($conversationId);

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
            return response()->json(['ok' => true]);
        }

        // Se a entrada foi áudio, tenta responder somente com áudio (PTT).
        // Se falhar (ex.: erro no TTS ou no envio), cai no fluxo de texto como fallback.
        if ($shouldReplyWithAudio) {
            $audio = $this->tts->synthesize($cleanBaseText, $phone);
            if ($audio && !empty($audio['url'])) {
                $result = $this->sender->sendAudioByUrl($phone, $audio['url'], true);
                $sent = (bool) ($result['success'] ?? false);
                Log::info('sendWhatsAppAudio result', [
                    'to' => $phone,
                    'success' => $sent,
                    'httpcode' => $result['httpcode'] ?? null,
                ]);
                if ($sent) {
                    return response()->json(['ok' => true, 'audio_only' => true]);
                }
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

        return match ($mime) {
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/webm' => 'webm',
            'audio/wav', 'audio/x-wav', 'audio/wave' => 'wav',
            'audio/m4a', 'audio/x-m4a' => 'm4a',
            'audio/aac' => 'aac',

            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'application/pdf' => 'pdf',

            default => null,
        };
    }

    private function parseButtonInstruction(string $message): array
    {
        $parts = explode('|', $message, 3);
        if (count($parts) === 3 && $parts[0] === '__BUTTON__') {
            return [$parts[1] ?: null, $parts[2] ?: null];
        }

        return [null, null];
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
