<?php

namespace App\Http\Controllers\Api;

use Throwable;
use Prism\Prism\Prism;
use App\Tools\CardTool;
use App\Tools\TicketTool;
use App\Tools\IrInformTool;
use Illuminate\Http\Request;
use Prism\Prism\Enums\Provider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Services\ConversationIdService;
use App\Services\ConversationService;
use App\Services\AudioTranscriptionService;
use App\Services\ImageAnalysisService;
use App\Services\AssistantMessageBuilder;
use App\Services\CanonicalConversationService;
use App\Services\RedisConversationService;
use App\Services\IntentClassifierService;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

class AIAssistantMultipleInputController extends Controller
{
    protected $ticketTool;
    protected $cardTool;
    protected $irInformTool;
    protected $conversationIdService;
    protected $conversationService;
    protected $audioTranscriptionService;
    protected $imageAnalysisService;
    protected $redisConversationService;
    protected AssistantMessageBuilder $assistantMessages;
    protected CanonicalConversationService $canonicalConversations;
    protected array $cardFilterDiagnostics = [];
    protected IntentClassifierService $intentClassifier;

    public function __construct(
        TicketTool $ticketTool,
        CardTool $cardTool,
        IrInformTool $irInformTool,
        ConversationIdService $conversationIdService,
        ConversationService $conversationService,
        AudioTranscriptionService $audioTranscriptionService,
        ImageAnalysisService $imageAnalysisService,
        CanonicalConversationService $canonicalConversations,
        RedisConversationService $redisConversationService,
        AssistantMessageBuilder $assistantMessages,
        IntentClassifierService $intentClassifier
    ) {
        $this->ticketTool = $ticketTool;
        $this->cardTool = $cardTool;
        $this->irInformTool = $irInformTool;
        $this->conversationIdService = $conversationIdService;
        $this->conversationService = $conversationService;
        $this->audioTranscriptionService = $audioTranscriptionService;
        $this->imageAnalysisService = $imageAnalysisService;
        $this->canonicalConversations = $canonicalConversations;
        $this->redisConversationService = $redisConversationService;
        $this->assistantMessages = $assistantMessages;
        $this->intentClassifier = $intentClassifier;
    }

    public function chat(Request $request)
    {
        // Maintenance gate (early): returns short message and skips AI/tools
        try {
            if ($this->isMaintenanceOnForChannel('web')) {
                $tz = (string) (env('MAINTENANCE_TZ', config('app.timezone') ?: 'UTC'));
                $text = $this->buildMaintenanceMessage('web', $tz);
                $resp = [
                    'text' => $text,
                    'maintenance' => true,
                    'variant' => strtolower((string) env('MAINTENANCE_VARIANT', 'default')),
                    'until' => trim((string) env('MAINTENANCE_UNTIL', '')) ?: null,
                    'status_url' => trim((string) env('MAINTENANCE_STATUS_URL', '')) ?: null,
                ];
                return response()->json($resp);
            }
        } catch (\Throwable $e) {
            // If maintenance helpers fail, log and continue normal flow
            Log::warning('Maintenance gate (web) failed', ['error' => $e->getMessage()]);
        }
        Log::info('Enviado pelo usuário',['texto' => $request->text, 'conversation_id' => $request->conversation_id]);
        // Diagnóstico de entrada do /api/chat
        Log::info('Chat entry files', [
            'accept' => $request->header('accept'),
            'has_audio' => $request->hasFile('audio'),
            'audio_mime' => $request->hasFile('audio') ? $request->file('audio')->getClientMimeType() : null,
            'audio_size' => $request->hasFile('audio') ? $request->file('audio')->getSize() : null,
            'has_image' => $request->hasFile('image'),
        ]);

        // Diagnóstico detalhado do arquivo recebido (classe, validade, erros)
        try {
            $af = $request->hasFile('audio') ? $request->file('audio') : null;
            $aiu = null;
            $apath = null;
            $avalid = null;
            $aerr = null;
            if ($af) {
                $apath = method_exists($af, 'getPathname') ? $af->getPathname() : null;
                $avalid = method_exists($af, 'isValid') ? $af->isValid() : null;
                $aerr = method_exists($af, 'getError') ? $af->getError() : null;
                if (is_string($apath) && $apath !== '') {
                    $aiu = function_exists('is_uploaded_file') ? @is_uploaded_file($apath) : null;
                }
            }
            Log::info('Chat file diagnostics', [
                'audio_class' => $af ? get_class($af) : null,
                'audio_path' => $apath,
                'audio_is_valid' => $avalid,
                'audio_error' => $aerr,
                'audio_is_uploaded_file' => $aiu,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Chat file diagnostics failed', ['error' => $e->getMessage()]);
        }

        $request->validate([
            'text' => 'nullable|string|max:4000',
            'audio' => 'nullable|file|mimes:mp3,wav,m4a,ogg,webm,flac,aac|max:25600', // 25MB
            'image' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB
        ]);

        // Deve ter pelo menos um tipo de entrada
        if (!$request->input('text') && !$request->hasFile('audio') && !$request->hasFile('image')) {
            return response()->json(['error' => 'Deve fornecer texto, áudio ou imagem'], 400);
        }

        $conversationIdOriginal = $this->conversationIdService->setConversationId($request);
        $conversationId = $this->canonicalConversations->resolveForWeb($conversationIdOriginal) ?? $conversationIdOriginal;
        if ($conversationId !== $conversationIdOriginal) {
            $this->canonicalConversations->linkWebToCanonical($conversationIdOriginal, $conversationId);
        }
        $kw = $request->kw;
        if (!$kw) {
            $kw = Cache::get($this->getConversationCacheKey($conversationId, 'kw_value'));
        }
        //Log::info('kw enviado payload ' . $kw);
        $this->syncKwStatusWithHeader($conversationId, $kw);
        $this->ticketTool->setConversationId($conversationId);
        $this->cardTool->setConversationId($conversationId);
        $this->irInformTool->setConversationId($conversationId);
        $this->resetConversationToolState($conversationId);

        //Verifica se foi enviada a chave de acesso no sistema após login
        // já obtido anteriormente

        try {
            // Processa a entrada do usuário
            $userInput = $this->processUserInput($request);
            if (!$userInput) {
                return response()->json(['error' => 'Falha ao processar entrada'], 400);
            }
            //ds(['Input' => $userInput]);

            $detectedCpf = $this->extractCpf($userInput);
            if ($detectedCpf) {
                $this->storeLastCpf($conversationId, $detectedCpf);
                $canonical = $this->canonicalConversations->linkWebToCpf($conversationIdOriginal, $detectedCpf);
                if ($canonical !== $conversationId) {
                    $conversationId = $canonical;
                    $this->ticketTool->setConversationId($conversationId);
                    $this->cardTool->setConversationId($conversationId);
                    $this->irInformTool->setConversationId($conversationId);
                    // Re-sincroniza KW imediatamente no ID canônico para evitar pedido de login no mesmo request
                    $this->syncKwStatusWithHeader($conversationId, $kw);
                }
            }
            // Sinaliza se este turno contém arquivo/imagem e se extraímos CPF
            $isImageTurnFlag = $request->hasFile('image');
            $fileKind = null;
            if ($isImageTurnFlag) {
                try {
                    $ext = strtolower((string) $request->file('image')->getClientOriginalExtension());
                    $fileKind = $ext === 'pdf' ? 'pdf' : 'image';
                } catch (\Throwable $e) {
                    $fileKind = 'image';
                }
            }
            Cache::put($this->getConversationCacheKey($conversationId, 'is_file_turn'), (bool) $isImageTurnFlag, 600);
            Cache::put($this->getConversationCacheKey($conversationId, 'file_kind'), $fileKind, 600);
            Cache::put($this->getConversationCacheKey($conversationId, 'cpf_extracted_this_turn'), (bool) $detectedCpf, 600);
            // Marca erro de análise de imagem neste turno, se aplicável
            $imageErrorThisTurn = str_contains($userInput, '[Erro na análise da imagem]');
            Cache::put($this->getConversationCacheKey($conversationId, 'image_error_this_turn'), $imageErrorThisTurn, 600);

            $isLoginConfirmation = $this->looksLikeLoginConfirmation($userInput);

            if (!$isLoginConfirmation) {
                Cache::put(
                    $this->getConversationCacheKey($conversationId, 'last_user_text'),
                    $userInput,
                    now()->endOfDay()
                );
            }

            if ($isLoginConfirmation) {
                $this->redisConversationService->addMessage($conversationId, 'user', $userInput);
                $pendingPayload = $this->handlePendingRequest($conversationId, $kw);
                if ($pendingPayload !== null) {
                    return response()->json($pendingPayload);
                }
            }

            // Classificação de intenção pelo sub-agente
            $channel = $request->header('X-Channel', 'web');
            // Histórico curto (antes de registrar a nova mensagem)
            $history = $this->redisConversationService->getMessages($conversationId);
            // Ordena defensivamente por timestamp ascendente (cronológico)
            usort($history, function ($a, $b) {
                $ta = $a['timestamp'] ?? '';
                $tb = $b['timestamp'] ?? '';
                return strcmp((string)$ta, (string)$tb);
            });
            // Coleta últimas 5 mensagens do usuário (mais novas primeiro)
            $recentUser = [];
            for ($i = count($history) - 1; $i >= 0 && count($recentUser) < 5; $i--) {
                $m = $history[$i] ?? null;
                if (is_array($m) && ($m['role'] ?? '') === 'user') {
                    $content = trim((string) ($m['content'] ?? ''));
                    if ($content !== '') {
                        $recentUser[] = $content;
                    }
                }
            }
            // Contexto para classificador (apoia mensagens elípticas)
            $prevIntent = $this->getStoredIntent($conversationId);
            $lastToolGate = Cache::get($this->getConversationCacheKey($conversationId, 'last_tool'));
            $lastPayloadReq = $this->getStoredPayloadRequest($conversationId);
            $lastPrimaryField = Cache::get($this->getConversationCacheKey($conversationId, 'last_card_primary_field'));
            $clfContext = [
                'previous_intent' => $prevIntent,
                'last_tool' => $lastToolGate,
                'last_card_primary_field' => $lastPrimaryField,
                'last_requested_fields' => $lastPayloadReq['fields'] ?? [],
                'recent_user_messages' => $recentUser,
            ];
            $classification = $this->intentClassifier->classify($userInput, $history, $channel, $clfContext);
            $intentProposed = $classification['intent'] ?? 'unknown';
            $intentConfidence = (float) ($classification['confidence'] ?? 0.0);
            $commitThreshold = (float) env('INTENT_COMMIT_THRESHOLD', 0.7);

            // Observabilidade
            Log::info('Intent.classified', [
                'conv' => $conversationId,
                'channel' => $channel,
                'intent' => $intentProposed,
                'confidence' => $intentConfidence,
                'threshold' => $commitThreshold,
            ]);
            $this->redisConversationService->setMetadataField($conversationId, 'intent_proposed', $intentProposed);
            $this->redisConversationService->setMetadataField($conversationId, 'intent_confidence', $intentConfidence);

            // Persistência condicional
            if ($intentProposed !== 'unknown' && $intentConfidence >= $commitThreshold) {
                $this->storeIntent($conversationId, $intentProposed);
            }

            $payloadRequest = $this->detectPayloadRequestFromMessage($userInput);
            $this->storePayloadRequest($conversationId, $payloadRequest);

            // Adiciona mensagem do usuário à conversa
            //$this->conversationService->addMessage($conversationId, 'user', $userInput);
            $this->redisConversationService->addMessage($conversationId,'user', $userInput);

            // Seleção de ferramentas com base na intenção do turno
            $intentForTurn = $intentProposed; // usa a intenção proposta neste turno
            $isImageTurn = $request->hasFile('image');
            $storedCpf = $this->getStoredCpf($conversationId);
            $cpfFromThisTurn = (bool) Cache::get($this->getConversationCacheKey($conversationId, 'cpf_extracted_this_turn'));
            // Ajuste fino: reaproveita intenção persistida quando o classificador não consegue
            if ($intentForTurn === 'unknown') {
                $storedIntentForTurn = $this->getStoredIntent($conversationId);
                if (!$isImageTurn && $detectedCpf && is_string($storedIntentForTurn) && $storedIntentForTurn !== '') {
                    $intentForTurn = $storedIntentForTurn;
                } elseif ($isImageTurn && $cpfFromThisTurn && $storedCpf && is_string($storedIntentForTurn) && $storedIntentForTurn !== '') {
                    $intentForTurn = $storedIntentForTurn;
                }
            }
            $kwStatusKey = $this->getConversationCacheKey($conversationId, 'kw_status');
            $kwStatus = Cache::get($kwStatusKey);
            $statusLogin = $this->resolveStatusLogin($kw, $kwStatus);

            $tools = [];
            $isLoggedIn = $statusLogin === 'usuário logado';
            // Fonte do CPF para logs/diagnóstico
            if ($isImageTurn) {
                $cpfSource = $cpfFromThisTurn ? 'file' : 'none';
            } else {
                $cpfSource = $detectedCpf ? 'text' : ($storedCpf ? 'session' : 'none');
            }
            $blockedReason = null;
            switch ($intentForTurn) {
                case 'ticket':
                    // Em turno com arquivo sem CPF no próprio arquivo, não executar
                    if ($isImageTurn && !$cpfFromThisTurn) {
                        $blockedReason = 'file_without_cpf';
                    } elseif ($storedCpf) {
                        $tools[] = $this->ticketTool;
                    }
                    break;
                case 'card':
                    if ($isImageTurn && !$cpfFromThisTurn) {
                        $blockedReason = 'file_without_cpf';
                    } elseif ($storedCpf && $isLoggedIn) {
                        $tools[] = $this->cardTool;
                    }
                    break;
                case 'ir':
                    if ($isImageTurn && !$cpfFromThisTurn) {
                        $blockedReason = 'file_without_cpf';
                    } elseif ($storedCpf && $isLoggedIn) {
                        $tools[] = $this->irInformTool;
                    }
                    break;
                default:
                    // unknown: não expõe tools
                    break;
            }

            // Telemetria: arquivo com CPF mas sem intenção explícita no turno
            if ($blockedReason === null && $isImageTurn && $cpfFromThisTurn && $intentForTurn === 'unknown') {
                $blockedReason = 'file_cpf_no_intent';
            }

            Log::info('Tools.gating', [
                'conv' => $conversationId,
                'intent' => $intentForTurn,
                'has_cpf' => (bool) $storedCpf,
                'is_logged_in' => $isLoggedIn,
                'is_file_turn' => (bool) $isImageTurn,
                'cpf_source' => $cpfSource,
                'blocked_reason' => $blockedReason,
                'tools' => array_map(fn($t) => is_object($t) ? get_class($t) : (string) $t, $tools),
            ]);

            // Gera resposta da AI (com tools já decididas)
            $response = $this->generateAIResponse($conversationId, $kw, $tools, $intentForTurn);
            //ds(['Response AI' => $response]);
            // Adiciona resposta da AI à conversa somente se houver texto
            if (is_string($response) && trim($response) !== '') {
                //$this->conversationService->addMessage($conversationId, 'assistant', $response);
                $this->redisConversationService->addMessage($conversationId,'assistant', $response);
            } else {
                Log::info('Assistant.message.skip_null', [
                    'conv' => $conversationId,
                    'reason' => 'empty_or_null_response'
                ]);
            }

            // Fallback: garantir execução das tools quando aplicável
            // Observações:
            // - Em turnos com imagem/pdf SEM CPF extraído do próprio arquivo, não executar fallback de tools sensíveis.
            // - Em mensagens elípticas (intentProposed 'unknown') sem CPF informado neste turno, não executar fallback.
            // - Se o usuário informou CPF neste turno (texto) mesmo com intentProposed 'unknown', executar fallback usando a intenção persistida.
            try {
                $cpfFromThisTurn = (bool) Cache::get($this->getConversationCacheKey($conversationId, 'cpf_extracted_this_turn'));
                if ($isImageTurn) {
                    if (!$cpfFromThisTurn) {
                        Log::info('Tools.fallback.skip_file_without_cpf', [
                            'conv' => $conversationId,
                        ]);
                    } elseif ($intentProposed === 'unknown') {
                        // Se havia pedido de boleto pendente e o CPF veio via arquivo, execute a consulta de boletos
                        $pending = Cache::get($this->getConversationCacheKey($conversationId, 'pending_request'));
                        $pendingIntent = is_array($pending) ? ($pending['intent'] ?? null) : null;
                        if (!$pendingIntent) {
                            $pendingIntent = $this->getStoredIntent($conversationId);
                        }
                        if ($pendingIntent === 'ticket') {
                            $storedCpf = $this->getStoredCpf($conversationId);
                            if ($storedCpf && strlen($storedCpf) === 11) {
                                $this->ticketTool->setConversationId($conversationId);
                                $this->ticketTool->__invoke($storedCpf);
                                Log::info('Pending.run.ticket.file_turn', ['conv' => $conversationId]);
                            } else {
                                Log::info('Pending.skip_reason', ['conv' => $conversationId, 'reason' => 'no_cpf_after_file_turn_for_ticket']);
                            }
                        } else {
                            Log::info('Tools.fallback.skip_file_unknown_intent', [
                                'conv' => $conversationId,
                            ]);
                        }
                    } else {
                        // Intenção explícita em turno de arquivo: executar fallback normal
                        $currentIntent = $this->getStoredIntent($conversationId);
                        if ($currentIntent === 'ticket') {
                            $storedCpf = $this->getStoredCpf($conversationId);
                            $lastToolKey = $this->getConversationCacheKey($conversationId, 'last_tool');
                            $lastTool = Cache::get($lastToolKey);
                            Log::info('Ticket.fallback.check', [
                                'conv' => $conversationId,
                                'has_cpf' => (bool) $storedCpf,
                                'last_tool' => $lastTool,
                            ]);
                            if ($storedCpf && strlen($storedCpf) === 11 && $lastTool !== 'ticket') {
                                $this->ticketTool->setConversationId($conversationId);
                                $this->ticketTool->__invoke($storedCpf);
                                Log::info('Ticket.fallback.invoked', ['conv' => $conversationId]);
                            }
                        } elseif ($currentIntent === 'card') {
                            $storedCpf = $this->getStoredCpf($conversationId);
                            $lastToolKey = $this->getConversationCacheKey($conversationId, 'last_tool');
                            $lastTool = Cache::get($lastToolKey);
                            $loginOk = !$this->shouldShowLoginButton($conversationId, $kw);
                            Log::info('Card.fallback.check', [
                                'conv' => $conversationId,
                                'has_cpf' => (bool) $storedCpf,
                                'login_ok' => $loginOk,
                                'last_tool' => $lastTool,
                            ]);
                            if ($storedCpf && strlen($storedCpf) === 11 && $loginOk && $lastTool !== 'card') {
                                $this->cardTool->setConversationId($conversationId);
                                $this->cardTool->setKw($kw);
                                $this->cardTool->__invoke($storedCpf, $kw);
                                Log::info('Card.fallback.invoked', ['conv' => $conversationId]);
                            }
                        } elseif ($currentIntent === 'ir') {
                            $storedCpf = $this->getStoredCpf($conversationId);
                            $lastToolKey = $this->getConversationCacheKey($conversationId, 'last_tool');
                            $lastTool = Cache::get($lastToolKey);
                            $loginOk = !$this->shouldShowLoginButton($conversationId, $kw);
                            Log::info('Ir.fallback.check', [
                                'conv' => $conversationId,
                                'has_cpf' => (bool) $storedCpf,
                                'login_ok' => $loginOk,
                                'last_tool' => $lastTool,
                            ]);
                            if ($storedCpf && strlen($storedCpf) === 11 && $loginOk && $lastTool !== 'ir') {
                                $this->irInformTool->setConversationId($conversationId);
                                $this->irInformTool->setKw($kw);
                                $this->irInformTool->__invoke($storedCpf, null, $kw);
                                Log::info('Ir.fallback.invoked', ['conv' => $conversationId]);
                            }
                        }
                    }
                } else {
                    if ($intentProposed === 'unknown' && !$cpfFromThisTurn) {
                        Log::info('Tools.fallback.skip_elliptical_unknown', [
                            'conv' => $conversationId,
                        ]);
                    } else {
                        if ($intentProposed === 'unknown' && $cpfFromThisTurn) {
                            Log::info('Tools.fallback.cpf_slot_turn', [
                                'conv' => $conversationId,
                            ]);
                        }
                        $currentIntent = $this->getStoredIntent($conversationId);
                        if ($currentIntent === 'ticket') {
                            $storedCpf = $this->getStoredCpf($conversationId);
                            $lastToolKey = $this->getConversationCacheKey($conversationId, 'last_tool');
                            $lastTool = Cache::get($lastToolKey);
                            Log::info('Ticket.fallback.check', [
                                'conv' => $conversationId,
                                'has_cpf' => (bool) $storedCpf,
                                'last_tool' => $lastTool,
                            ]);
                            if ($storedCpf && strlen($storedCpf) === 11 && $lastTool !== 'ticket') {
                                $this->ticketTool->setConversationId($conversationId);
                                // Executa a tool para popular conv:{id}:boletos e last_tool='ticket'
                                $this->ticketTool->__invoke($storedCpf);
                                Log::info('Ticket.fallback.invoked', ['conv' => $conversationId]);
                            }
                        } elseif ($currentIntent === 'card') {
                            $storedCpf = $this->getStoredCpf($conversationId);
                            $lastToolKey = $this->getConversationCacheKey($conversationId, 'last_tool');
                            $lastTool = Cache::get($lastToolKey);
                            $loginOk = !$this->shouldShowLoginButton($conversationId, $kw);
                            Log::info('Card.fallback.check', [
                                'conv' => $conversationId,
                                'has_cpf' => (bool) $storedCpf,
                                'login_ok' => $loginOk,
                                'last_tool' => $lastTool,
                            ]);
                            if ($storedCpf && strlen($storedCpf) === 11 && $loginOk && $lastTool !== 'card') {
                                $this->cardTool->setConversationId($conversationId);
                                $this->cardTool->setKw($kw);
                                $this->cardTool->__invoke($storedCpf, $kw);
                                Log::info('Card.fallback.invoked', ['conv' => $conversationId]);
                            }
                        } elseif ($currentIntent === 'ir') {
                            $storedCpf = $this->getStoredCpf($conversationId);
                            $lastToolKey = $this->getConversationCacheKey($conversationId, 'last_tool');
                            $lastTool = Cache::get($lastToolKey);
                            $loginOk = !$this->shouldShowLoginButton($conversationId, $kw);
                            Log::info('Ir.fallback.check', [
                                'conv' => $conversationId,
                                'has_cpf' => (bool) $storedCpf,
                                'login_ok' => $loginOk,
                                'last_tool' => $lastTool,
                            ]);
                            if ($storedCpf && strlen($storedCpf) === 11 && $loginOk && $lastTool !== 'ir') {
                                $this->irInformTool->setConversationId($conversationId);
                                $this->irInformTool->setKw($kw);
                                $this->irInformTool->__invoke($storedCpf, null, $kw);
                                Log::info('Ir.fallback.invoked', ['conv' => $conversationId]);
                            }
                        }
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('Ticket fallback execution failed', ['error' => $e->getMessage()]);
            }

            $payload = $this->buildResponsePayload($conversationId, $response, $kw);

            return response()->json($payload);

        } catch (PrismException $e) {
            Log::error('AI generation failed:', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Falha na geração de resposta'], 500);
        } catch (Throwable $e) {
            Log::error('Generic error:', ['error' => $e->getMessage()]);
            return response()->json(['error' => 'Erro interno do servidor'], 500);
        }
    }

    // ============================
    // Helper modo manuntenção (Web)
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

    private function buildMaintenanceMessage(string $channel, string $tz): string
    {
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
            $h = (int) now($tz)->format('G');
        } catch (\Throwable $e) {
            $h = (int) now()->format('G');
        }
        if ($h >= 18 || $h < 5) return 'boa noite';
        if ($h <= 11) return 'bom dia';
        return 'boa tarde';
    }

    /**
     * Processa diferentes tipos de entrada do usuário
     */
    private function processUserInput(Request $request): ?string
    {
        $inputs = [];

        // Processa texto
        if ($request->input('text')) {
            $inputs[] = $request->input('text');
        }

        // Processa áudio
        if ($request->hasFile('audio')) {
            try {
                $transcription = $this->audioTranscriptionService->transcribe($request->file('audio'));
                $inputs[] = "[Áudio transcrito]: " . $transcription;
            } catch (\Exception $e) {
                Log::error('Audio transcription failed:', ['error' => $e->getMessage()]);
                $inputs[] = "[Erro na transcrição do áudio]";
            }
        }

        // Processa imagem
        if ($request->hasFile('image')) {
            try {
                $imageAnalysis = $this->imageAnalysisService->analyzeImage($request->file('image'));
                $inputs[] = "[Imagem analisada]: " . $imageAnalysis;
            } catch (\Exception $e) {
                Log::error('Image analysis failed:', ['error' => $e->getMessage()]);
                $inputs[] = "[Erro na análise da imagem]";
            }
        }

        return !empty($inputs) ? implode("\n\n", $inputs) : null;
    }

    /**
     * Gera resposta da AI usando Prism
     */
    private function generateAIResponse(string $conversationId, ?string $kw, array $tools, ?string $intentForTurn)
    {
        // Obtém mensagens da conversa
        //$conversationMessages = $this->conversationService->getMessages($conversationId);
        $conversationMessages = $this->redisConversationService->getMessages($conversationId);

        $isFirstAssistantTurn = !$this->hasAssistantTurn($conversationMessages) ? 'true' : 'false';

        $storedCpf = $this->getStoredCpf($conversationId);

        $kwStatusKey = $this->getConversationCacheKey($conversationId, 'kw_status');
        $kwStatus = Cache::get($kwStatusKey);

        $statusLogin = $this->resolveStatusLogin($kw, $kwStatus);
        //Log::info('statusLogin ' . $statusLogin . ' - kw ' . $kw . ' - kw_status ' . $kwStatus);

        // Monta mensagens para o Prism (ordem cronológica)
        $this->cardTool->setKw($kw);
        $this->irInformTool->setKw($kw);

        $payloadRequest = $this->getStoredPayloadRequest($conversationId);
        $requestedFields = $payloadRequest['fields'] ?? [];
        $primaryCardField = $this->determinePrimaryCardField($requestedFields);

        $ticketErrorKey = $this->getConversationCacheKey($conversationId, 'ticket_error');
        $ticketErrorDetailKey = $this->getConversationCacheKey($conversationId, 'ticket_error_detail');
        $ticketError = Cache::get($ticketErrorKey);
        $ticketErrorDetail = Cache::get($ticketErrorDetailKey);

        // Garante ordem cronológica das mensagens do histórico
        usort($conversationMessages, function ($a, $b) {
            $ta = $a['timestamp'] ?? '';
            $tb = $b['timestamp'] ?? '';
            return strcmp((string)$ta, (string)$tb);
        });

        $messages = [
            new SystemMessage(view('prompts.assistant-prompt', [
                'kw' => $kw,
                'statusLogin' => $statusLogin,
                'isFirstAssistantTurn' => $isFirstAssistantTurn,
                'kwStatus' => $kwStatus,
                'hasStoredCpf' => $storedCpf ? 'true' : 'false',
                'cardRequestedFields' => $requestedFields,
                'primaryCardField' => $primaryCardField,
                'ticketError' => $ticketError,
                'ticketErrorDetail' => $ticketErrorDetail,
                'intentNow' => $intentForTurn ?? $this->getStoredIntent($conversationId),
                'lastTool' => Cache::get($this->getConversationCacheKey($conversationId, 'last_tool')),
                // Flags de turno com arquivo
                'isFileTurn' => (bool) Cache::get($this->getConversationCacheKey($conversationId, 'is_file_turn')),
                'fileKind' => Cache::get($this->getConversationCacheKey($conversationId, 'file_kind')),
                'cpfExtractedThisTurn' => (bool) Cache::get($this->getConversationCacheKey($conversationId, 'cpf_extracted_this_turn')),
                'imageErrorThisTurn' => (bool) Cache::get($this->getConversationCacheKey($conversationId, 'image_error_this_turn')),
            ])->render())
        ];
        //Log::info('PROMPT' , $messages);
        foreach ($conversationMessages as $message) {
            if ($message['role'] === 'user') {
                $messages[] = new UserMessage($message['content']);
            } else {
                $messages[] = new AssistantMessage($message['content']);
            }
        }

        try{
            // Rate limit short-circuit (global ou por conversa)
            $globalBackoffKey = 'prism:rate_limited';
            $convBackoffKey = $this->getConversationCacheKey($conversationId, 'prism_rate_limited');
            if (Cache::get($globalBackoffKey) || Cache::get($convBackoffKey)) {
                Log::warning('Prism.skip.rate_limited', [
                    'conv' => $conversationId,
                ]);
                return 'Estou com instabilidade no momento. Já continuo assim que possível.';
            }

            Log::info('Prism.start', [
                'conv' => $conversationId,
                'intent_in_prompt' => $intentForTurn,
                'kw_status' => $kwStatus,
                'has_cpf' => (bool) $storedCpf,
                'tools_count' => count($tools),
            ]);
            $response = Prism::text()
                ->using(Provider::OpenAI, 'gpt-4.1')
                ->withMessages($messages)
                ->withMaxSteps(3)
                ->withTools($tools)
                ->withProviderOptions([
                    'temperature' => 0.5,
                    'top_p' => 0.8,
                    'frequency_penalty' => 0.2,
                    'presence_penalty' => 0.1,
                ])
                ->asText();
            Log::info('Prism.reply', [
                'conv' => $conversationId,
                'tools_count' => count($tools),
                'preview' => is_string($response->text ?? null) ? mb_substr($response->text, 0, 160) : null,
            ]);
            return $response->text;

        } catch (PrismException $e) {
            Log::error('Text generation failed:', ['error' => $e->getMessage()]);
            $msg = (string) $e->getMessage();
            $seconds = (int) env('PRISM_RATE_LIMIT_BACKOFF_SECONDS', 12);
            if ($seconds < 1) { $seconds = 12; }
            if (stripos($msg, 'rate limit') !== false) {
                $globalBackoffKey = 'prism:rate_limited';
                $convBackoffKey = $this->getConversationCacheKey($conversationId, 'prism_rate_limited');
                Cache::put($globalBackoffKey, true, now()->addSeconds($seconds));
                Cache::put($convBackoffKey, true, now()->addSeconds($seconds));
                Log::warning('Prism.rate_limited', [
                    'conv' => $conversationId,
                    'backoff_seconds' => $seconds,
                ]);
            }
            return 'Estou com instabilidade no momento. Já continuo assim que possível.';
        } catch (Throwable $e) {
            Log::error('Generic error:', ['error' => $e->getMessage()]);
            return 'Estou com instabilidade no momento. Já continuo assim que possível.';
        }
    }

    private function resolveStatusLogin(?string $kw, ?string $kwStatus): string
    {
        if (empty($kw)) {
            return 'usuário não logado';
        }

        if (($kwStatus ?? null) === 'invalid') {
            return 'usuário não logado';
        }

        return 'usuário logado';
    }

    private function syncKwStatusWithHeader(string $conversationId, ?string $kw): void
    {
        $statusKey = $this->getConversationCacheKey($conversationId, 'kw_status');
        $hashKey = $this->getConversationCacheKey($conversationId, 'kw_hash');
        $valueKey = $this->getConversationCacheKey($conversationId, 'kw_value');

        if ($kw) {
            $currentHash = hash('sha256', $kw);
            $storedHash = Cache::get($hashKey);

            Cache::put($hashKey, $currentHash, 3600);
            Cache::put($valueKey, $kw, 3600);

            if ($storedHash !== $currentHash) {
                Cache::forget($statusKey);
            } else {
                Cache::put($statusKey, Cache::get($statusKey), 3600);
            }

            return;
        }

        $storedValue = Cache::get($valueKey);
        $storedHash = Cache::get($hashKey);

        if ($storedValue) {
            Cache::put($valueKey, $storedValue, 3600);
        }

        if ($storedHash) {
            Cache::put($hashKey, $storedHash, 3600);
        }

        if ($storedValue || $storedHash) {
            Cache::put($statusKey, Cache::get($statusKey), 3600);
            return;
        }

        Cache::forget($hashKey);
        Cache::forget($valueKey);
        Cache::forget($statusKey);
    }

    private function buildResponsePayload(string $conversationId, ?string $responseText, ?string $kwInline = null): array
    {
        $payload = [
            'text' => $responseText ?? '',
            'conversation_id' => $conversationId,
        ];

        $lastToolKey = $this->getConversationCacheKey($conversationId, 'last_tool');
        $lastTool = Cache::get($lastToolKey);
        $shouldShowLogin = $this->shouldShowLoginButton($conversationId, $kwInline);
        $intent = $this->getStoredIntent($conversationId);
        $storedCpfNow = $this->getStoredCpf($conversationId);
        Log::info('Payload.build.start', [
            'conv' => $conversationId,
            'intent' => $intent,
            'last_tool' => $lastTool,
            'login_required' => $shouldShowLogin,
            'has_cpf' => (bool) $storedCpfNow,
        ]);
        // Mensagem determinística quando houve erro de análise de imagem no turno
        $imageErrorThisTurn = (bool) Cache::pull($this->getConversationCacheKey($conversationId, 'image_error_this_turn'));
        if ($imageErrorThisTurn) {
            // Só sobrepõe a resposta se nenhuma tool rodou efetivamente
            if (!in_array($lastTool, ['ticket','card','ir'], true)) {
                $payload['text'] = 'Não consegui processar o arquivo enviado. Por favor, informe o CPF (11 dígitos) e me diga o que deseja ver (boletos, carteirinha, planos, pagamentos, IR ou coparticipação).';
                Log::info('Image.user_notice.failed', ['conv' => $conversationId]);
            }
        }
        $isFileTurnPayload = (bool) Cache::get($this->getConversationCacheKey($conversationId, 'is_file_turn'));
        $cpfFromThisTurnPayload = (bool) Cache::get($this->getConversationCacheKey($conversationId, 'cpf_extracted_this_turn'));
        if ($intent === 'ticket' && $lastTool !== 'ticket' && $storedCpfNow && $isFileTurnPayload && $cpfFromThisTurnPayload) {
            $payload['text'] = 'Recebi seu CPF e estou consultando seus boletos. Aguarde um instante, por favor.';
            Log::info('Payload.ticket.defer_file_turn', [
                'conv' => $conversationId,
                'reason' => 'cpf_from_file_pending_tool',
            ]);
        }

        $payloadRequest = $this->getStoredPayloadRequest($conversationId);
        $requestedFields = array_values(array_unique(array_filter($payloadRequest['fields'] ?? [])));
        $contractFilters = $payloadRequest['contract_filters'] ?? [];
        $periodFilters = $payloadRequest['period_filters'] ?? [];

        $pendingKey = $this->getConversationCacheKey($conversationId, 'pending_request');
        if ($shouldShowLogin) {
            $pending = [
                'intent' => $intent,
                'fields' => $requestedFields,
                'contract_filters' => $contractFilters,
                'period_filters' => $periodFilters,
                'user_text' => Cache::get($this->getConversationCacheKey($conversationId, 'last_user_text')),
            ];
            // Se a intenção persistida estiver nula, tenta a proposta do classificador (com confiança suficiente)
            if (!$pending['intent']) {
                $proposed = $this->redisConversationService->getMetadataField($conversationId, 'intent_proposed');
                $confidence = (float) ($this->redisConversationService->getMetadataField($conversationId, 'intent_confidence') ?? 0.0);
                $threshold = (float) env('INTENT_COMMIT_THRESHOLD', 0.7);
                if (is_string($proposed) && $proposed !== '' && $confidence >= $threshold) {
                    $pending['intent'] = $proposed;
                }
            }
            Cache::put($pendingKey, $pending, now()->endOfDay());
        } else {
            Cache::forget($pendingKey);
        }

        $this->assistantMessages->setConversation($conversationId, $intent);
        $this->cardFilterDiagnostics = [];

        if ($lastTool === 'ticket') {
            $ticketsKey = $this->getConversationCacheKey($conversationId, 'boletos');
            $tickets = Cache::get($ticketsKey);
            if (is_array($tickets)) {
                $payload['boletos'] = $tickets;
                $payload['text'] = $this->adjustTicketText($payload['text'], $tickets);
                Log::info('Payload.ticket.attached', [
                    'conv' => $conversationId,
                    'attached_count' => count($tickets),
                ]);
            }
            Cache::forget($ticketsKey);
        } elseif ($lastTool === 'card') {
            $payload['login'] = $shouldShowLogin;
            $originalText = $payload['text'];

            $dataMap = [
                'beneficiarios' => 'beneficiarios',
                'planos' => 'planos',
                'fichafinanceira' => 'fichafinanceira',
                'coparticipacao' => 'coparticipacao',
            ];

            // 1) Carrega os dados crus do cache (antes de esquecer) para decidir o que incluir
            $rawByField = [];
            foreach ($dataMap as $field => $suffix) {
                $cacheKey = $this->getConversationCacheKey($conversationId, $suffix);
                $rawByField[$field] = $cacheKey ? Cache::get($cacheKey) : null;
            }

            // 2) Determina os campos a incluir
            $fieldsToInclude = $requestedFields;
            $reason = 'requested_fields';
            if (empty($fieldsToInclude)) {
                // Se há filtros de contrato, foca em 'planos'
                if ($this->hasMeaningfulContractFilters($contractFilters)) {
                    $fieldsToInclude = ['planos'];
                    $reason = 'by_contract_filters';
                }
            }
            if (empty($fieldsToInclude)) {
                // Se há filtros de período, foca em ficha financeira (ou último subcampo card relevante)
                $hasPeriod = !empty($periodFilters['references'] ?? []) || !empty($periodFilters['months'] ?? []) || !empty($periodFilters['years'] ?? []);
                if ($hasPeriod) {
                    $lastPrimary = $this->getLastCardPrimaryField($conversationId);
                    if (in_array($lastPrimary, ['fichafinanceira', 'coparticipacao'], true)) {
                        $fieldsToInclude = [$lastPrimary];
                    } else {
                        $fieldsToInclude = ['fichafinanceira'];
                    }
                    $reason = 'by_period_filters';
                }
            }
            if (empty($fieldsToInclude)) {
                $lastSubfields = $this->getLastCardSubfields($conversationId);
                if (!empty($lastSubfields)) {
                    $fieldsToInclude = $lastSubfields;
                    $reason = 'by_last_subfields';
                }
            }
            if (empty($fieldsToInclude)) {
                // fallback antigo: incluir blocos com dados
                foreach ($dataMap as $field => $_) {
                    $raw = $rawByField[$field] ?? null;
                    if (is_array($raw) && !empty($raw)) {
                        $fieldsToInclude[] = $field;
                    }
                }
                $fieldsToInclude = array_values(array_unique($fieldsToInclude));
                $reason = 'by_available_data';
            }
            Log::info('Card.fields.decided', ['conv' => $conversationId, 'fields' => $fieldsToInclude, 'reason' => $reason]);

            // 3) Processa cada campo e popula o payload
            foreach ($dataMap as $field => $suffix) {
                $cacheKey = $this->getConversationCacheKey($conversationId, $suffix);
                $rawData = $rawByField[$field] ?? null;

                // Limpa o cache após leitura
                if ($cacheKey) {
                    Cache::forget($cacheKey);
                }

                if (!in_array($field, $fieldsToInclude, true)) {
                    continue;
                }

                $data = is_array($rawData) ? $rawData : [];

                if (in_array($field, ['planos', 'fichafinanceira', 'coparticipacao'], true) && $this->hasMeaningfulContractFilters($contractFilters)) {
                    $data = $this->filterCardDataByContractFilters($data, $contractFilters, $field);
                }

                if (in_array($field, ['fichafinanceira', 'coparticipacao'], true)) {
                    $data = $this->filterCardDataByPeriod($data, $periodFilters, $field);
                }

                $payload[$field] = array_values($data);
            }

            $payload['text'] = $this->adjustCardText(
                $originalText,
                $payload,
                $fieldsToInclude
            );

            // Atualiza memória do último subcampo principal e subcampos
            $this->setLastCardPrimaryField($conversationId, $this->determinePrimaryCardField($fieldsToInclude));
            $this->setLastCardSubfields($conversationId, $fieldsToInclude);
            $this->clearStoredPayloadRequest($conversationId);
        } elseif ($lastTool === 'ir') {
            $payload['login'] = $shouldShowLogin;

            $listaKey = $this->getConversationCacheKey($conversationId, 'ir_documentos');
            $lista = $listaKey ? Cache::get($listaKey) : null;
            Log::info('lista ir assistant', $lista);
            if ($listaKey) {
                Cache::forget($listaKey);
            }

            if (is_array($lista)) {
                $payload['ir'] = [
                    'quantidade' => $lista['quantidade'] ?? 0,
                    'documentos' => $lista['documentos'] ?? [],
                ];
                // Adiciona follow-up amigável após execução bem-sucedida da tool de IR
                $original = $payload['text'] ?? '';
                $sanitized = $this->stripFollowUp($original);
                $payload['text'] = $this->assistantMessages->withFollowUp($sanitized);
            }
        } else {
            // Reforço determinístico: para intenção 'ticket' sem CPF e sem execução da tool, peça CPF e não afirme "localizei".
            if ($intent === 'ticket') {
                if (!$storedCpfNow) {
                    $payload['cpf_required'] = true;
                    // Decide saudação apenas se este for de fato o primeiro turno do assistente
                    $msgs = $this->redisConversationService->getMessages($conversationId);
                    $assistantTurns = 0;
                    foreach ($msgs as $m) {
                        if (($m['role'] ?? null) === 'assistant') {
                            $type = $m['metadata']['type'] ?? null;
                            if (!$type || !in_array($type, ['assistant_error', 'image_response'], true)) {
                                $assistantTurns++;
                            }
                        }
                    }
                    $tz = (string) (env('MAINTENANCE_TZ', config('app.timezone') ?: 'UTC'));
                    $greet = $this->makeGreeting($tz);
                    $base = 'Para consultar seus boletos, preciso do seu CPF (somente números), por favor.';
                    $payload['text'] = ($assistantTurns <= 1 ? ('Olá, ' . $greet . '! ') : '') . $base;
                    Log::info('Payload.ticket.enforce_cpf', ['conv' => $conversationId, 'assistant_turns' => $assistantTurns]);
                    // Registra intenção pendente para ticket, aguardando CPF
                    $pendingKey = $this->getConversationCacheKey($conversationId, 'pending_request');
                    $pending = [
                        'intent' => 'ticket',
                        'fields' => [],
                        'contract_filters' => [],
                        'period_filters' => [],
                        'user_text' => Cache::get($this->getConversationCacheKey($conversationId, 'last_user_text')),
                    ];
                    Cache::put($pendingKey, $pending, now()->endOfDay());
                }
            }

            $messagesForHeuristic = $this->redisConversationService->getMessages($conversationId);
            $assistantCount = 0;
            foreach ($messagesForHeuristic as $m) {
                if (($m['role'] ?? null) === 'assistant') {
                    $type = $m['metadata']['type'] ?? null;
                    if (!$type || !in_array($type, ['assistant_error', 'image_response'], true)) {
                        $assistantCount++;
                    }
                }
            }

            if ($intent === 'card') {
                $payload['login'] = $shouldShowLogin;

                if ($shouldShowLogin) {
                    // Sempre padroniza a mensagem de login para card (carteirinha/planos/pagamentos/coparticipação)
                    $payload['text'] = $this->buildLoginReminderMessage($conversationId, $requestedFields);
                }
            } elseif ($intent === 'ir') {
                $payload['login'] = $shouldShowLogin;

                if ($shouldShowLogin) {
                    // Sempre padroniza a mensagem de login para IR
                    $payload['text'] = $this->buildIrLoginReminderMessage();
                }
            }
        }

        // Deterministic ticket error messaging (optional via env)
        try {
            if ($this->envBool('ASSISTANT_TICKET_ERROR_DETERMINISTIC', true)) {
                $hasTickets = !empty($payload['boletos']);
                $ticketError = Cache::get($this->getConversationCacheKey($conversationId, 'ticket_error'));
                $ticketErrorDetail = Cache::get($this->getConversationCacheKey($conversationId, 'ticket_error_detail'));

                if (!$hasTickets && is_string($ticketError) && $ticketError !== '') {
                    $msg = null;
                    if ($ticketError === 'cpf_invalid') {
                        $raw = Lang::get('assistant.ticket.errors.cpf_invalid');
                        $msg = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;
                    } elseif ($ticketError === 'pin_invalid') {
                        $raw = Lang::get('assistant.ticket.errors.validation_failed');
                        $msg = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;
                    } elseif ($ticketError === 'technical_error') {
                        $raw = Lang::get('assistant.ticket.errors.technical');
                        $msg = is_array($raw) ? (string) ($raw[0] ?? '') : (string) $raw;
                    } elseif ($ticketError === 'boleto_indisponivel') {
                        $msg = $this->assistantMessages->ticketExpired();
                    }

                    if (is_string($msg) && trim($msg) !== '') {
                        $payload['text'] = $this->assistantMessages->withFollowUp($msg);
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::warning('Deterministic ticket error messaging failed', ['error' => $e->getMessage()]);
        }

        Cache::forget($lastToolKey);
        Cache::forget($this->getConversationCacheKey($conversationId, 'ticket_error'));
        Cache::forget($this->getConversationCacheKey($conversationId, 'ticket_error_detail'));

        // Sanitização final: evitar afirmações sem dados anexados
        try {
            $finalText = (string) ($payload['text'] ?? '');
            $hasTicketData = !empty($payload['boletos']);
            $hasAnyCardData = !empty($payload['beneficiarios']) || !empty($payload['planos']) || !empty($payload['fichafinanceira']) || !empty($payload['coparticipacao']);

            if (!$hasTicketData && $this->textClaimsTicketResult($finalText)) {
                Log::info('Payload.ticket.mismatch_between_text_and_data', ['conv' => $conversationId]);
                $payload['text'] = $this->assistantMessages->withFollowUp('Posso ajudar com: boletos, carteirinha, planos, pagamentos, IR ou coparticipação. Qual você quer ver?');
            }
            if (!$hasAnyCardData && $this->textClaimsCardResult($finalText)) {
                Log::info('Payload.card.mismatch_between_text_and_data', ['conv' => $conversationId]);
                $payload['text'] = $this->assistantMessages->withFollowUp('Posso ajudar com: carteirinha, planos, pagamentos ou coparticipação. O que você deseja ver?');
            }
        } catch (\Throwable $e) {
            Log::warning('Payload.sanitizer.failed', ['error' => $e->getMessage()]);
        }

        return $this->enforceCpfRequirement($payload, $conversationId, $intent, $requestedFields, $kwInline);
    }

    private function textClaimsTicketResult(string $text): bool
    {
        $t = Str::lower(Str::ascii(strip_tags($text)));
        if ($t === '') return false;
        $claims = (str_contains($t, 'localiz') || str_contains($t, 'encontrei') || str_contains($t, 'exibi') || str_contains($t, 'mostr'));
        $domain = (str_contains($t, 'boleto') || str_contains($t, 'cobran'));
        return $claims && $domain;
    }

    private function textClaimsCardResult(string $text): bool
    {
        $t = Str::lower(Str::ascii(strip_tags($text)));
        if ($t === '') return false;
        $domainWords = ['plano', 'planos', 'carteir', 'benefici', 'coparticip', 'pagament'];
        $domain = false;
        foreach ($domainWords as $w) {
            if (str_contains($t, $w)) { $domain = true; break; }
        }
        $claims = (str_contains($t, 'localiz') || str_contains($t, 'encontrei') || str_contains($t, 'exibi') || str_contains($t, 'mostr'));
        return $domain && $claims;
    }

    private function enforceCpfRequirement(array $payload, string $conversationId, ?string $intent, array $requestedFields, ?string $kwInline = null): array
    {
        if ($intent !== 'card') {
            return $payload;
        }

        if ($this->shouldShowLoginButton($conversationId, $kwInline)) {
            return $payload;
        }

        $storedCpf = $this->getStoredCpf($conversationId);

        if ($storedCpf) {
            return $payload;
        }

        $payload['cpf_required'] = true;

        // Não solicitar CPF explicitamente por texto após login;
        // O front/fluxo envia a mensagem com CPF automaticamente.

        return $payload;
    }

    private function shouldShowLoginButton(string $conversationId, ?string $kwInline = null): bool
    {
        $kw = $kwInline ?: Cache::get($this->getConversationCacheKey($conversationId, 'kw_value'));
        $kwStatus = Cache::get($this->getConversationCacheKey($conversationId, 'kw_status'));
        return $this->resolveStatusLogin($kw, $kwStatus) !== 'usuário logado';
    }



    private function detectPayloadRequestFromMessage(string $message): array
    {
        $normalized = mb_strtolower($message, 'UTF-8');
        $fields = [];

        $hasCardRequest = (bool) preg_match('/carteir|cart[ãa]o virtual|documento digital/u', $normalized);
        $hasFinanceRequest = (bool) preg_match('/relat[óo]rio\s*financeir[oa]|ficha\s*financeir[oa]|(?:meu|minha|seu|sua|o|a)\s*financeir[oa]|\bfinanceir[oa]\b/u', $normalized);
        $hasCoparticipationRequest = (bool) preg_match('/co[-\s]?participa[cç][aã]o|coparticipa[cç][aã]o|\bcopart\b/u', $normalized);
        $hasPaymentsRequest = $this->matchesPaymentsRequest($normalized);

        if ($hasCardRequest) {
            $fields[] = 'beneficiarios';
        }

        if ($hasFinanceRequest || $hasPaymentsRequest) {
            $fields[] = 'fichafinanceira';
        }

        if ($hasCoparticipationRequest) {
            $fields[] = 'coparticipacao';
        }

        $mentionsPlansPlural = (bool) preg_match('/\bplanos\b/u', $normalized);
        $mentionsContractsPlural = (bool) preg_match('/\bcontratos\b/u', $normalized);
        $mentionsPlanSingular = (bool) preg_match('/\bplano\b/u', $normalized);
        $mentionsContractSingular = (bool) preg_match('/\bcontrato\b/u', $normalized);
        $explicitPlansRequest = (bool) preg_match('/\b(meus?|suas?|seus?|quais|qual|mostrar|mostre|retorne|retorna|retornar|lista|listar|ver|veja|exibir|exiba|consultar|consulte)\s+(?:os\s+|as\s+)?(planos|contratos)\b/u', $normalized);
        $explicitPlanSingular = (bool) preg_match('/\b(meu|seu|qual|mostrar|mostre|ver|veja|consultar|consulte)\s+(?:o\s+|um\s+)?plano\b/u', $normalized);
        $explicitContractSingular = (bool) preg_match('/\b(meu|seu|qual|mostrar|mostre|ver|veja|consultar|consulte)\s+(?:o\s+|um\s+)?contrato\b/u', $normalized);
        $planKeywords = (bool) preg_match('/\b(plano\s*(?:atual|vigente|contratado|ativo|principal))\b/u', $normalized);

        if ($explicitPlansRequest || $mentionsPlansPlural || $mentionsContractsPlural ||
            $mentionsPlanSingular || $mentionsContractSingular ||
            $explicitPlanSingular || $explicitContractSingular ||
            $planKeywords) {
            $fields[] = 'planos';
        }

        $contractFilters = $this->extractContractFilters($message);
        $periodFilters = $this->extractPeriodFilters($message);

        return [
            'fields' => array_values(array_unique($fields)),
            'contract_filters' => $contractFilters,
            'period_filters' => $periodFilters,
        ];
    }

    private function extractContractFilters(string $message): array
    {
        $filters = [
            'plan' => [],
            'entidade' => [],
            'operadora' => [],
            'fantasia' => [],
            'id' => [],
            'numerocontrato' => [],
            'situacao' => [],
        ];

        $filters['plan'] = $this->filterPlanStopwords(
            $this->extractTermsByKeywords($message, ['plano', 'planos'])
        );
        $filters['entidade'] = $this->filterPlanStopwords(
            $this->extractTermsByKeywords($message, ['entidade', 'entidades'])
        );
        $filters['operadora'] = $this->filterPlanStopwords(
            $this->extractTermsByKeywords($message, ['operadora', 'operadoras'])
        );
        $filters['fantasia'] = $this->filterPlanStopwords(
            $this->extractTermsByKeywords($message, ['fantasia', 'nome fantasia', 'operadora fantasia'])
        );

        $filters['id'] = $this->extractContractIdTerms($message);
        $filters['numerocontrato'] = $this->extractNumeroContratoTerms($message);

        // Detecta intenções de situação (ativo/cancelado) no texto completo
        $normMsg = $this->normalizeText($message);
        if ($normMsg !== '') {
            if (preg_match('/\b(ativo|ativos|vigente|vigentes)\b/u', $normMsg)) {
                $filters['situacao'][] = 'ativo';
            }
            if (preg_match('/\b(cancelad[oa]s?|inativ[oa]s?)\b/u', $normMsg)) {
                $filters['situacao'][] = 'cancelado';
            }
        }

        foreach ($filters as $key => $list) {
            $normalized = [];
            foreach ($list as $value) {
                $norm = $this->normalizeText($value);
                if ($norm !== '') {
                    $normalized[$norm] = $norm;
                }
            }
            $filters[$key] = array_values(array_unique(array_values($normalized)));
        }

        return $filters;
    }

    private function hasMeaningfulContractFilters(array $filters): bool
    {
        foreach (['plan', 'entidade', 'operadora', 'fantasia', 'id', 'numerocontrato', 'situacao'] as $key) {
            $values = $filters[$key] ?? [];

            if (empty($values)) {
                continue;
            }

            foreach ($values as $value) {
                $normalized = $this->normalizeText($value);

                if ($normalized !== '') {
                    return true;
                }
            }
        }

        return false;
    }

    private function messageMentionsLogin(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        $normalized = Str::ascii(strip_tags($text));
        $normalized = Str::lower($normalized);

        return str_contains($normalized, 'login') || str_contains($normalized, 'logar') || str_contains($normalized, 'entrar');
    }

    private function filterPlanStopwords(array $terms): array
    {
        $stopwords = [
            'atual', 'atuais', 'vigente', 'vigentes', 'novo', 'novos', 'antigo', 'antigos',
            'meu', 'minha', 'seu', 'sua', 'plano', 'planos', 'contrato', 'contratos', 'um', 'o',
            'este', 'esse', 'essa', 'isso', 'aquele', 'aquela', 'principal', 'ativo', 'ativos',
            // cortesia / agradecimentos (e variações comuns)
            'por favor', 'porfavor', 'favor', 'por gentileza', 'gentileza',
            'obrigado', 'obrigada', 'obg', 'obgd', 'valeu',
            'pf', 'pfv', 'pfvr', 'pff', 'pls', 'plz', 'porfa', 'please',
            'agradeco', 'agradeça', 'agradeca', 'agradeceria',
        ];

        $filtered = [];

        foreach ($terms as $term) {
            $normalized = $this->normalizeText($term);

            if ($normalized === '' || in_array($normalized, $stopwords, true)) {
                continue;
            }

            // Evita termos muito curtos que tendem a ser ruído
            if (mb_strlen($normalized, 'UTF-8') < 2) {
                continue;
            }

            $filtered[] = $term;
        }

        return $filtered;
    }

    private function extractTermsByKeywords(string $message, array $keywords): array
    {
        $terms = [];

        foreach ($keywords as $keyword) {
            $patternKeyword = preg_quote($keyword, '/');
            $pattern = '/\b' . $patternKeyword . '\b(?:\s+(?:do|da|de|dos|das|para|pra|no|na|nos|nas|sobre|dois))*\s+([^.;:!\n]+)/iu';

            if (preg_match_all($pattern, $message, $matches)) {
                foreach ($matches[1] as $segment) {
                    foreach ($this->splitSegmentIntoTerms($segment) as $term) {
                        $terms[] = $term;
                    }
                }
            }
        }

        return $terms;
    }

    private function splitSegmentIntoTerms(string $segment): array
    {
        $segment = trim($segment);

        if ($segment === '') {
            return [];
        }

        // Remove qualquer trecho após marcadores de cortesia (para não vir "por favor" como termo)
        $breaker = '/\b(por\s*favor|porfavor|por\s+gentileza|obrigad(?:o|a)|obg|obgd|valeu|pfv|pfvr|pf|pff|pls|plz|porfa|please|agradec(?:o|a|eria)|agradeceria)\b/iu';
        $preBreak = preg_split($breaker, $segment);
        if (is_array($preBreak) && count($preBreak) > 0) {
            $segment = trim((string) $preBreak[0]);
        }

        $segment = str_replace(['/', ';', '|'], ',', $segment);
        $segment = preg_replace('/\s+(e|ou)\s+/iu', ',', $segment);

        $parts = array_map('trim', explode(',', $segment));
        $result = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $part = preg_replace('/^(do|da|de|dos|das|meu|minha|seu|sua|o|a)\s+/iu', '', $part);
            $part = trim($part);

            if ($part !== '') {
                $result[] = $part;
            }
        }

        return $result;
    }

    private function extractContractIdTerms(string $message): array
    {
        $ids = [];

        if (preg_match_all('/\b\d{2,}_\d{2,}_\d{5,}\b/u', $message, $matches)) {
            $ids = array_merge($ids, $matches[0]);
        }

        $pattern = '/\bid(?:\s+do|\s+da|\s+de|\s+do\s+contrato)?\s+([A-Za-z0-9_\-\.]+)/iu';
        if (preg_match_all($pattern, $message, $matches)) {
            $ids = array_merge($ids, $matches[1]);
        }

        return $ids;
    }

    private function extractNumeroContratoTerms(string $message): array
    {
        $numbers = [];

        $patterns = [
            '/n[úu]mero\s+do\s+contrato\s+(\d+)/iu',
            '/contrato\s+n[ºo]?\s*\.?\s*(\d+)/iu',
            '/contrato\s+(\d{4,})/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match_all($pattern, $message, $matches)) {
                $numbers = array_merge($numbers, $matches[1]);
            }
        }

        return $numbers;
    }

    private function extractPeriodFilters(string $message): array
    {
        $normalized = $this->normalizeText($message);

        $references = [];
        $months = [];
        $years = [];

        if (preg_match_all('/\b(0?[1-9]|1[0-2])[\/\-](\d{4})\b/', $normalized, $dateMatches, PREG_SET_ORDER)) {
            foreach ($dateMatches as $match) {
                $month = str_pad($match[1], 2, '0', STR_PAD_LEFT);
                $year = $match[2];
                $references[] = "{$month}/{$year}";
            }
        }

        $monthsMap = [
            'janeiro' => '01',
            'fevereiro' => '02',
            'marco' => '03',
            'abril' => '04',
            'maio' => '05',
            'junho' => '06',
            'julho' => '07',
            'agosto' => '08',
            'setembro' => '09',
            'outubro' => '10',
            'novembro' => '11',
            'dezembro' => '12',
        ];

        $monthsPattern = '/\b(' . implode('|', array_keys($monthsMap)) . ')\b(?:\s+de)?\s*(\d{4})?/u';
        if (preg_match_all($monthsPattern, $normalized, $monthMatches, PREG_SET_ORDER)) {
            foreach ($monthMatches as $match) {
                $monthKey = $match[1];
                $year = $match[2] ?? null;
                $monthNumber = $monthsMap[$monthKey] ?? null;

                if (!$monthNumber) {
                    continue;
                }

                if ($year) {
                    $references[] = "{$monthNumber}/{$year}";
                } else {
                    $months[] = $monthNumber;
                }
            }
        }

        if (preg_match_all('/\b(?:de|do|da|para|pra|em|entre|ate|até|ano|anos)\s+(20\d{2})\b/', $normalized, $yearMatches, PREG_SET_ORDER)) {
            foreach ($yearMatches as $match) {
                $years[] = $match[1];
            }
        }

        return [
            'references' => array_values(array_unique($references)),
            'months' => array_values(array_unique($months)),
            'years' => array_values(array_unique($years)),
        ];
    }

    private function determinePrimaryCardField(array $fields): string
    {
        $fields = array_values(array_filter($fields, static function ($field) {
            return is_string($field) && $field !== '';
        }));

        return $fields[0] ?? '';
    }

    private function setLastCardPrimaryField(string $conversationId, ?string $field): void
    {
        if (!$field) return;
        $key = $this->getConversationCacheKey($conversationId, 'last_card_primary_field');
        Cache::put($key, $field, 3600);
    }

    private function getLastCardPrimaryField(string $conversationId): ?string
    {
        $key = $this->getConversationCacheKey($conversationId, 'last_card_primary_field');
        $val = Cache::get($key);
        if ($val) Cache::put($key, $val, 3600);
        return $val ?: null;
    }

    private function normalizeContractFilters($filters): array
    {
        $template = [
            'plan' => [],
            'entidade' => [],
            'operadora' => [],
            'fantasia' => [],
            'id' => [],
            'numerocontrato' => [],
            'situacao' => [],
        ];

        if (!is_array($filters)) {
            return $template;
        }

        foreach ($template as $key => $default) {
            $values = $filters[$key] ?? [];
            if (!is_array($values)) {
                $values = [];
            }
            $normalized = [];
            foreach ($values as $value) {
                $norm = $this->normalizeText($value);
                if ($norm !== '') {
                    $normalized[$norm] = $norm;
                }
            }
            $template[$key] = array_values($normalized);
        }

        return $template;
    }

    private function storePayloadRequest(string $conversationId, array $payloadRequest): void
    {
        $fields = array_values(array_unique(array_filter($payloadRequest['fields'] ?? [], function ($field) {
            return is_string($field) && trim($field) !== '';
        })));

        $contractFilters = $this->normalizeContractFilters($payloadRequest['contract_filters'] ?? []);
        $periodFiltersInput = $payloadRequest['period_filters'] ?? [
            'references' => [],
            'months' => [],
            'years' => [],
        ];
        $periodFilters = [
            'references' => array_values(array_unique($periodFiltersInput['references'] ?? [])),
            'months' => array_values(array_unique($periodFiltersInput['months'] ?? [])),
            'years' => array_values(array_unique($periodFiltersInput['years'] ?? [])),
        ];

        $hasFields = !empty($fields);
        $hasContractFilters = $this->hasMeaningfulContractFilters($contractFilters);
        $hasPeriodFilters = !empty($periodFilters['references']) || !empty($periodFilters['months']) || !empty($periodFilters['years']);

        $cacheKey = $this->getConversationCacheKey($conversationId, 'card_payload_request');
        $existingPayload = $this->getStoredPayloadRequest($conversationId);

        if (!$hasFields && !$hasContractFilters && !$hasPeriodFilters) {
            if (!empty($existingPayload)) {
                Cache::put($cacheKey, $existingPayload, 3600);
            }
            $this->getLastCardPrimaryField($conversationId);
            $this->getLastCardSubfields($conversationId);
            return;
        }

        if ($hasFields) {
            $primary = $this->determinePrimaryCardField($fields);
            if ($primary !== '') {
                $this->setLastCardPrimaryField($conversationId, $primary);
            }
            $this->setLastCardSubfields($conversationId, $fields);
        } else {
            $this->getLastCardPrimaryField($conversationId);
            $this->getLastCardSubfields($conversationId);
        }

        $payload = [
            'fields' => $fields,
            'contract_filters' => $contractFilters,
            'period_filters' => $periodFilters,
        ];

        if ($existingPayload === $payload) {
            Cache::put($cacheKey, $existingPayload, 3600);
            return;
        }

        Cache::put($cacheKey, $payload, 3600);
    }

    private function setLastCardSubfields(string $conversationId, array $fields): void
    {
        $clean = array_values(array_filter(array_map(function ($f) { return is_string($f) ? trim($f) : ''; }, $fields)));
        $key = $this->getConversationCacheKey($conversationId, 'last_card_subfields');
        Cache::put($key, $clean, 3600);
    }

    private function getLastCardSubfields(string $conversationId): array
    {
        $key = $this->getConversationCacheKey($conversationId, 'last_card_subfields');
        $val = Cache::get($key);
        if (is_array($val)) {
            Cache::put($key, $val, 3600);
            return $val;
        }
        return [];
    }

    private function getStoredPayloadRequest(string $conversationId): array
    {
        $cacheKey = $this->getConversationCacheKey($conversationId, 'card_payload_request');
        $request = $cacheKey ? Cache::get($cacheKey) : null;

        if (is_array($request)) {
            $request['fields'] = array_values(array_unique($request['fields'] ?? []));
            $request['contract_filters'] = $this->normalizeContractFilters($request['contract_filters'] ?? []);
            $periodFilters = $request['period_filters'] ?? ['references' => [], 'months' => [], 'years' => []];
            $request['period_filters'] = [
                'references' => array_values(array_unique($periodFilters['references'] ?? [])),
                'months' => array_values(array_unique($periodFilters['months'] ?? [])),
                'years' => array_values(array_unique($periodFilters['years'] ?? [])),
            ];

            Cache::put($cacheKey, $request, 3600);
            return $request;
        }

        return [];
    }

    private function clearStoredPayloadRequest(string $conversationId): void
    {
        $cacheKey = $this->getConversationCacheKey($conversationId, 'card_payload_request');

        if ($cacheKey) {
            Cache::forget($cacheKey);
        }
    }

    private function filterCardDataByContractFilters(array $items, array $filters, string $field): array
    {
        $filters = $this->normalizeContractFilters($filters);

        $hasFilters = false;
        foreach ($filters as $list) {
            if (!empty($list)) {
                $hasFilters = true;
                break;
            }
        }

        if (!$hasFilters) {
            return $items;
        }

        $filtered = [];

        foreach ($items as $item) {
            [$contract, $planName] = $this->resolveContractForItem($item, $field);

            if ($this->matchesContractFilters($contract, $planName, $filters)) {
                $filtered[] = $item;
            }
        }

        if ($hasFilters && empty($filtered) && !empty($items)) {
            $this->registerCardFilterMiss($field, $filters);
        }

        return array_values($filtered);
    }

    private function filterCardDataByPeriod(array $items, array $periodFilters, string $field): array
    {
        $references = $periodFilters['references'] ?? [];
        $months = $periodFilters['months'] ?? [];
        $years = $periodFilters['years'] ?? [];

        if (empty($references) && empty($months) && empty($years)) {
            return $items;
        }

        $result = [];

        foreach ($items as $item) {
            if ($field === 'fichafinanceira') {
                $entries = $item['fichafinanceira'] ?? [];
                $filteredEntries = [];

                foreach ($entries as $entry) {
                    if ($this->matchesPeriodFilter($entry, $references, $months, $years)) {
                        $filteredEntries[] = $entry;
                    }
                }

                $item['fichafinanceira'] = array_values($filteredEntries);
                $result[] = $item;
            } elseif ($field === 'coparticipacao') {
                $entries = $item['coparticipacao'] ?? [];
                $filteredEntries = [];

                foreach ($entries as $entry) {
                    if ($this->matchesPeriodFilter($entry, $references, $months, $years)) {
                        $filteredEntries[] = $entry;
                    }
                }

                $item['coparticipacao'] = array_values($filteredEntries);
                $result[] = $item;
            } else {
                $result[] = $item;
            }
        }

        return array_values($result);
    }

    private function resolveContractForItem(array $item, string $field): array
    {
        if ($field === 'planos') {
            $planName = $item['plano'] ?? ($item['contrato']['plano'] ?? null);
            $contract = $item;
            return [$contract, $planName];
        }

        $contract = $item['contrato'] ?? null;
        $planName = $item['plano'] ?? ($contract['plano'] ?? null);

        return [$contract, $planName];
    }

    private function registerCardFilterMiss(string $field, array $filters): void
    {
        $terms = [];

        foreach ($filters as $list) {
            foreach ($list as $value) {
                if ($value === '') {
                    continue;
                }
                $terms[] = $value;
            }
        }

        if (!empty($terms)) {
            $this->cardFilterDiagnostics[$field]['filter_terms'] = array_values(array_unique($terms));
        }
    }

    private function matchesContractFilters(?array $contract, ?string $planName, array $filters): bool
    {
        $normalizedPlan = $this->normalizeText($planName ?? ($contract['plano'] ?? ''));
        $normalizedEntidade = $this->normalizeText($contract['entidade'] ?? '');
        $normalizedOperadora = $this->normalizeText($contract['operadora'] ?? '');
        $normalizedFantasia = $this->normalizeText($contract['operadorafantasia'] ?? '');
        $normalizedId = $this->normalizeText($contract['id'] ?? '');
        $normalizedNumeroContrato = $this->normalizeText(
            isset($contract['numerocontrato']) ? (string) $contract['numerocontrato'] : ''
        );
        $normalizedSituacao = $this->normalizeText($contract['situacao'] ?? '');

        $hasAnyFilter = false;

        foreach ($filters as $type => $values) {
            foreach ($values as $value) {
                if ($value === '') {
                    continue;
                }
                $hasAnyFilter = true;

                switch ($type) {
                    case 'plan':
                        if ($normalizedPlan !== '' && str_contains($normalizedPlan, $value)) {
                            return true;
                        }
                        if ($normalizedEntidade !== '' && str_contains($normalizedEntidade, $value)) {
                            return true;
                        }
                        if ($normalizedOperadora !== '' && str_contains($normalizedOperadora, $value)) {
                            return true;
                        }
                        if ($normalizedFantasia !== '' && str_contains($normalizedFantasia, $value)) {
                            return true;
                        }
                        break;
                    case 'situacao':
                        if ($normalizedSituacao !== '' && str_contains($normalizedSituacao, $value)) {
                            return true;
                        }
                        break;
                    case 'entidade':
                        if ($normalizedEntidade !== '' && str_contains($normalizedEntidade, $value)) {
                            return true;
                        }
                        break;
                    case 'operadora':
                        if (
                            ($normalizedOperadora !== '' && str_contains($normalizedOperadora, $value)) ||
                            ($normalizedFantasia !== '' && str_contains($normalizedFantasia, $value))
                        ) {
                            return true;
                        }
                        break;
                    case 'fantasia':
                        if ($normalizedFantasia !== '' && str_contains($normalizedFantasia, $value)) {
                            return true;
                        }
                        break;
                    case 'id':
                        if ($normalizedId !== '' && str_contains($normalizedId, $value)) {
                            return true;
                        }
                        break;
                    case 'numerocontrato':
                        if ($normalizedNumeroContrato !== '' && str_contains($normalizedNumeroContrato, $value)) {
                            return true;
                        }
                        break;
                }
            }
        }

        return !$hasAnyFilter;
    }

    private function adjustTicketText(string $originalText, array $tickets): string
    {
        if (empty($tickets)) {
            return $this->assistantMessages->withFollowUp(
                $this->assistantMessages->ticketNone()
            );
        }

        $available = 0;
        $unavailable = 0;

        foreach ($tickets as $ticket) {
            $status = $ticket['status'] ?? null;
            if ($status === 'disponivel') {
                $available++;
            } elseif ($status === 'indisponivel') {
                $unavailable++;
            }
        }

        if ($available > 0 && $unavailable === 0) {
            $message = 'Boletos localizados. Copie a linha digitável ou abra o PDF abaixo (link válido por 1 hora).';
            return $this->assistantMessages->withFollowUp($message);
        }

        if ($available > 0 && $unavailable > 0) {
            return $this->assistantMessages->withFollowUp(
                $this->assistantMessages->ticketMixed()
            );
        }

        if ($available === 0 && $unavailable > 0) {
            return $this->assistantMessages->withFollowUp(
                $this->assistantMessages->ticketExpired()
            );
        }

        $sanitized = $this->stripFollowUp($originalText ?? '');
        return $this->assistantMessages->withFollowUp($sanitized !== '' ? $sanitized : 'Consulta concluída.');
    }

    private function adjustCardText(string $originalText, array $payload, array $requestedFields): string
    {
        $fields = array_values(array_intersect($requestedFields, ['planos', 'fichafinanceira', 'coparticipacao', 'beneficiarios']));

        if (empty($fields)) {
            $sanitized = $this->stripFollowUp($originalText ?? '');
            return $sanitized === '' ? $sanitized : $this->assistantMessages->withFollowUp($sanitized);
        }

        $messages = [];

        foreach ($fields as $field) {
            $data = $payload[$field] ?? [];

            switch ($field) {
                case 'planos':
                    if ($this->isPlansEmpty($data)) {
                        $planFilterTerms = $this->cardFilterDiagnostics['planos']['filter_terms'] ?? [];
                        if (!empty($planFilterTerms)) {
                            $messages[] = $this->assistantMessages->cardPlanFilterMissed($planFilterTerms);
                        } else {
                            $messages[] = $this->assistantMessages->cardNotFound('planos');
                        }
                    } else {
                        $messages[] = $this->getCardPositiveMessage('planos');
                    }
                    break;
                case 'beneficiarios':
                    if ($this->isBeneficiariesEmpty($data)) {
                        $messages[] = $this->assistantMessages->cardNotFound('beneficiarios');
                    } else {
                        $messages[] = $this->getCardPositiveMessage('beneficiarios');
                    }
                    break;
                case 'fichafinanceira':
                    $emptyState = $this->analyzeFinancialData($data);
                    if ($emptyState === 'all_empty') {
                        if (!empty($data)) {
                            $messages[] = $this->assistantMessages->cardFinanceNoEntries($data);
                        } else {
                            $messages[] = $this->assistantMessages->cardNotFound('fichafinanceira');
                        }
                    } elseif ($emptyState === 'partial_empty') {
                        $messages[] = $this->assistantMessages->cardPartial('fichafinanceira');
                    } else {
                        $messages[] = $this->getCardPositiveMessage('fichafinanceira');
                    }
                    break;
                case 'coparticipacao':
                    $emptyState = $this->analyzeCoparticipationData($data);
                    if ($emptyState === 'all_empty') {
                        if (!empty($data)) {
                            $messages[] = $this->assistantMessages->cardCoparticipationNoEntries($data);
                        } else {
                            $messages[] = $this->assistantMessages->cardNotFound('coparticipacao');
                        }
                    } elseif ($emptyState === 'partial_empty') {
                        $messages[] = $this->assistantMessages->cardPartial('coparticipacao');
                    } else {
                        $messages[] = $this->getCardPositiveMessage('coparticipacao');
                    }
                    break;
            }
        }

        $messages = array_values(array_unique(array_filter($messages)));

        if (empty($messages)) {
            $sanitized = $this->stripFollowUp($originalText ?? '');
            return $sanitized === '' ? $sanitized : $this->assistantMessages->withFollowUp($sanitized);
        }

        $lineBreak = (string) config('assistant.line_break', '<br>');
        $joined = implode($lineBreak, $messages);

        return $this->assistantMessages->withFollowUp($joined);
    }

    private function getCardPositiveMessage(string $field): string
    {
        return match ($field) {
            'planos' => 'Planos localizados. Veja os detalhes abaixo.',
            'beneficiarios' => 'Carteirinhas localizadas. Veja os detalhes abaixo.',
            'fichafinanceira' => 'Pagamentos localizados. Veja os detalhes abaixo.',
            'coparticipacao' => 'Coparticipações localizadas. Veja os detalhes abaixo.',
            default => 'Consulta atualizada. Veja os detalhes abaixo.',
        };
    }

    private function messageContradictsLogin(string $text): bool
    {
        if ($text === '') {
            return false;
        }

        $normalized = Str::ascii(strip_tags($text));
        $normalized = Str::lower($normalized);
        $keywords = [
            'localizei',
            'localizado',
            'localizados',
            'localizada',
            'localizadas',
            'encontrei',
            'exibi',
            'exibida',
            'exibidas',
            'exibidos',
            'na tela',
            'estao visiveis',
            'estao na tela',
        ];

        foreach ($keywords as $keyword) {
            if (!str_contains($normalized, $keyword)) {
                continue;
            }

            if (preg_match('/\bnao\s+' . preg_quote($keyword, '/') . '\b/', $normalized)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function messageAsksForCpf(?string $text): bool
    {
        if ($text === null || $text === '') {
            return false;
        }

        $normalized = Str::ascii(strip_tags($text));
        $normalized = Str::lower($normalized);

        return str_contains($normalized, 'cpf');
    }

    private function stripGreeting(string $text): string
    {
        if ($text === '') return $text;
        // Remove padrões de saudação no início do texto
        $stripped = preg_replace('/^\s*ol[áa][,!]?\s*(bom\s+dia|boa\s+tarde|boa\s+noite)?[!,.]?\s*/iu', '', $text);
        return is_string($stripped) ? $stripped : $text;
    }

    private function stripFollowUp(string $text): string
    {
        if ($text === '') return $text;
        $normalized = Str::lower(Str::ascii(strip_tags($text)));
        $phrases = [
            'posso ajudar em mais alguma coisa',
            'quer apoio com mais algum assunto',
            'precisa de algo mais',
            'posso ajudar com outra duvida',
            'posso ajudar com outra dúvida',
            'precisa de outra consulta',
            'quer que eu verifique mais alguma informacao',
            'quer que eu verifique mais alguma informação',
            'posso conferir outro dado',
            'posso conferir outro dado para voce',
            'posso conferir outro dado para você',
            'posso ajudar em mais alguma consulta',
        ];

        foreach ($phrases as $p) {
            if (str_contains($normalized, $p)) {
                // Remove a última frase/linha contendo a expressão
                $text = preg_replace('/(<br\s*\/?\s*>\s*)?' . preg_quote($p, '/') . '[?.!]*\s*$/iu', '', $text);
                // Também remove versões sem acentos já normalizadas
                $text = preg_replace('/(<br\s*\/?\s*>\s*)?posso ajudar em mais alguma coisa[?.!]*\s*$/iu', '', $text);
            }
        }

        return trim($text);
    }

    private function buildLoginReminderMessage(string $conversationId, array $requestedFields): string
    {
        $primaryField = $this->determinePrimaryCardField($requestedFields);

        $allowedFields = ['planos', 'fichafinanceira', 'coparticipacao', 'beneficiarios'];
        $labelKey = in_array($primaryField, $allowedFields, true) ? $primaryField : 'beneficiarios';
        $context = [
            'requested_fields' => $requestedFields,
            'label_key' => $labelKey,
        ];

        return $this->assistantMessages->loginRequired($labelKey, $context);
    }

    private function buildIrLoginReminderMessage(): string
    {
        return $this->assistantMessages->loginRequiredIr();
    }

    private function matchesPaymentsRequest(string $normalized): bool
    {
        if (!str_contains($normalized, 'pagament')) {
            return false;
        }

        $patterns = [
            // Plural
            '/\\bmeus?\\s+pagamentos\\b/u',
            '/\\bseus?\\s+pagamentos\\b/u',
            '/\\bconsult(ar|e|o)\\s+(?:os\\s+)?pagamentos\\b/u',
            '/\\bmostrar\\s+(?:os\\s+)?pagamentos\\b/u',
            '/\\bmostre\\s+(?:os\\s+)?pagamentos\\b/u',
            '/\\bver\\s+(?:os\\s+)?pagamentos\\b/u',
            '/\\blistar\\s+(?:os\\s+)?pagamentos\\b/u',
            '/\\bextrato\\s+(?:de\\s+)?pagament(?:o|os)\\b/u',
            '/\\bhist[óo]rico\\s+(?:de\\s+)?pagament(?:o|os)\\b/u',
            '/\\bpagament(?:o|os)\\s+(?:do|da|dos|das)\\b/u',
            '/\\bpagament(?:o|os)\\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }



    private function isPlansEmpty(array $plans): bool
    {
        return empty($plans);
    }

    private function isBeneficiariesEmpty(array $beneficiaries): bool
    {
        return empty($beneficiaries);
    }

    private function analyzeFinancialData(array $financial): string
    {
        if (empty($financial)) {
            return 'all_empty';
        }

        $hasData = false;
        $hasEmpty = false;

        foreach ($financial as $item) {
            $entries = $item['fichafinanceira'] ?? [];
            if (!empty($entries)) {
                $hasData = true;
            } else {
                $hasEmpty = true;
            }
        }

        if ($hasData && $hasEmpty) {
            return 'partial_empty';
        }

        if (!$hasData) {
            return 'all_empty';
        }

        return 'ok';
    }

    private function analyzeCoparticipationData(array $coparticipacao): string
    {
        if (empty($coparticipacao)) {
            return 'all_empty';
        }

        $hasData = false;
        $hasEmpty = false;

        foreach ($coparticipacao as $item) {
            $entries = $item['coparticipacao'] ?? [];
            if (!empty($entries)) {
                $hasData = true;
            } else {
                $hasEmpty = true;
            }
        }

        if ($hasData && $hasEmpty) {
            return 'partial_empty';
        }

        if (!$hasData) {
            return 'all_empty';
        }

        return 'ok';
    }

    private function matchesPeriodFilter(array $entry, array $references, array $months, array $years): bool
    {
        $reference = $entry['referencia'] ?? null;
        $month = null;
        $year = null;
        $fullReference = null;

        if ($reference && preg_match('/(0[1-9]|1[0-2])\/(\d{4})/', $reference, $match)) {
            $month = $match[1];
            $year = $match[2];
            $fullReference = "{$month}/{$year}";
        }

        if ((!$month || !$year) && isset($entry['datavencimento'])) {
            [$month, $year] = $this->extractMonthYearFromDate($entry['datavencimento']);
            if ($month && $year) {
                $fullReference = "{$month}/{$year}";
            }
        }

        if ((!$month || !$year) && isset($entry['datapagamento'])) {
            [$month, $year] = $this->extractMonthYearFromDate($entry['datapagamento']);
            if ($month && $year) {
                $fullReference = "{$month}/{$year}";
            }
        }

        if ((!$month || !$year) && isset($entry['dataevento'])) {
            [$month, $year] = $this->extractMonthYearFromDate($entry['dataevento']);
            if ($month && $year) {
                $fullReference = "{$month}/{$year}";
            }
        }

        if (!empty($references)) {
            return $fullReference !== null && in_array($fullReference, $references, true);
        }

        if (!empty($months)) {
            if (!$month || !in_array($month, $months, true)) {
                return false;
            }
        }

        if (!empty($years)) {
            if (!$year || !in_array($year, $years, true)) {
                return false;
            }
        }

        if (empty($months) && empty($years)) {
            return true;
        }

        return true;
    }

    private function extractMonthYearFromDate(?string $date): array
    {
        if (!$date) {
            return [null, null];
        }

        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return [null, null];
        }

        return [
            date('m', $timestamp),
            date('Y', $timestamp),
        ];
    }

    private function normalizeText(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $normalized = Str::ascii((string) $value);
        $normalized = Str::lower($normalized);
        $normalized = preg_replace('/\s+/', ' ', $normalized ?? '');

        return trim($normalized ?? '');
    }

    private function storeIntent(string $conversationId, string $intent): void
    {
        $cacheKey = $this->getConversationCacheKey($conversationId, 'intent');
        Cache::put($cacheKey, $intent, 3600);

        $this->redisConversationService->setMetadataField($conversationId, 'intent', $intent);
        $this->redisConversationService->setMetadataField($conversationId, 'intent_at', now()->toISOString());
    }

    private function getStoredIntent(string $conversationId): ?string
    {
        $cacheKey = $this->getConversationCacheKey($conversationId, 'intent');
        $intent = Cache::get($cacheKey);

        if ($intent) {
            Cache::put($cacheKey, $intent, 3600);
            return $intent;
        }

        $metaIntent = $this->redisConversationService->getMetadataField($conversationId, 'intent');
        if ($metaIntent) {
            Cache::put($cacheKey, $metaIntent, 3600);
            return $metaIntent;
        }

        return null;
    }

    private function resetConversationToolState(string $conversationId): void
    {
        Cache::forget($this->getConversationCacheKey($conversationId, 'last_tool'));
    }

    private function getConversationCacheKey(string $conversationId, string $suffix): string
    {
        return "conv:{$conversationId}:{$suffix}";
    }

    private function extractCpf(string $text): ?string
    {
        if (preg_match('/\b\d{3}[.\s]?\d{3}[.\s]?\d{3}[-\s]?\d{2}\b/', $text, $matches)) {
            $cpf = preg_replace('/\D/', '', $matches[0]);
            return strlen($cpf) === 11 ? $cpf : null;
        }

        return null;
    }

    private function storeLastCpf(string $conversationId, string $cpf): void
    {
        $normalized = preg_replace('/\D/', '', $cpf);

        if (strlen($normalized) !== 11) {
            return;
        }

        $cacheKey = $this->getConversationCacheKey($conversationId, 'last_cpf');
        Cache::forever($cacheKey, $normalized);

        $this->redisConversationService->setMetadataField($conversationId, 'last_cpf', $normalized);
        $this->redisConversationService->setMetadataField($conversationId, 'last_cpf_at', now()->toISOString());
    }

    private function getStoredCpf(string $conversationId): ?string
    {
        $cacheKey = $this->getConversationCacheKey($conversationId, 'last_cpf');
        $cpf = Cache::get($cacheKey);

        if ($cpf && strlen($cpf) === 11) {
            Cache::forever($cacheKey, $cpf);
            return $cpf;
        }

        $metaCpf = $this->redisConversationService->getMetadataField($conversationId, 'last_cpf');

        if ($metaCpf && strlen($metaCpf) === 11) {
            Cache::forever($cacheKey, $metaCpf);
            return $metaCpf;
        }

        return null;
    }

    private function hasAssistantTurn(array $messages): bool
    {
        foreach ($messages as $message) {
            if (($message['role'] ?? null) !== 'assistant') {
                continue;
            }

            $type = $message['metadata']['type'] ?? null;
            if ($type && in_array($type, ['assistant_error', 'image_response'], true)) {
                continue;
            }
            return true;
        }
        return false;
    }

    private function looksLikeLoginConfirmation(string $text): bool
    {
        $normalized = Str::lower(Str::ascii($text));

        if ($normalized === '') {
            return false;
        }

        $negativePatterns = [
            'nao fiz login',
            'nao fiz o login',
            'nao fiz meu login',
            'nao realizei login',
            'nao loguei',
            'ainda nao loguei',
            'ainda nao fiz login',
            'nao estou logado',
            'nao estou logada',
            'nao entrei na conta',
            'nao acessei a conta',
            'desloguei',
            'deslogado',
            'deslogada',
            'sem login',
        ];

        foreach ($negativePatterns as $negative) {
            if (str_contains($normalized, $negative)) {
                return false;
            }
        }

        $patterns = [
            'ja fiz login',
            'ja fiz o login',
            'ja fiz meu login',
            'fiz login',
            'fiz meu login',
            'login concluido',
            'login concluido',
            'login realizado',
            'login efetuado',
            'login ok',
            'login certo',
            'login deu certo',
            'ja realizei o login',
            'realizei o login',
            'acabei de fazer login',
            'acabei de logar',
            'acabei de entrar na conta',
            'acabei de acessar a conta',
            'acabei de acessar minha conta',
            'ja estou logado',
            'estou logado',
            'ja estou logada',
            'estou logada',
            'ja loguei',
            'loguei',
            'acessei a conta',
            'acessei minha conta',
            'entrei na conta',
            'ja entrei na conta',
            'acesso realizado',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        if (preg_match('/\blogad[ao]\b/u', $normalized)) {
            return !str_contains($normalized, 'deslogad');
        }

        if (preg_match('/\blogue[iu]\b/u', $normalized)) {
            return true;
        }

        return false;
    }

    private function handlePendingRequest(string $conversationId, ?string $kw): ?array
    {
        $pendingKey = $this->getConversationCacheKey($conversationId, 'pending_request');
        $pending = Cache::pull($pendingKey);

        if (!$pending) {
            Log::info('Pending.state', ['conv' => $conversationId, 'has_pending' => false]);
            return null;
        }

        $kw = $kw ?? Cache::get($this->getConversationCacheKey($conversationId, 'kw_value'));
        $cpf = $this->getStoredCpf($conversationId);

        if (!$kw || !$cpf) {
            Log::info('Pending.skip_reason', ['conv' => $conversationId, 'kw_present' => (bool) $kw, 'has_cpf' => (bool) $cpf]);
            return null;
        }

        $intent = $pending['intent'] ?? $this->getStoredIntent($conversationId);
        if (!$intent) {
            // Tenta recuperar a intenção proposta (com confiança suficiente)
            $proposed = $this->redisConversationService->getMetadataField($conversationId, 'intent_proposed');
            $confidence = (float) ($this->redisConversationService->getMetadataField($conversationId, 'intent_confidence') ?? 0.0);
            $threshold = (float) env('INTENT_COMMIT_THRESHOLD', 0.7);
            if (is_string($proposed) && $proposed !== '' && $confidence >= $threshold) {
                $intent = $proposed;
            }
        }
        if (!$intent) {
            Log::info('Pending.skip_reason', ['conv' => $conversationId, 'reason' => 'no_intent']);
            return null;
        }

        $responseText = null;

        switch ($intent) {
            case 'card':
                $this->cardTool->setConversationId($conversationId);
                $this->cardTool->setKw($kw);
                Log::info('Pending.run.card', ['conv' => $conversationId]);
                $responseText = $this->cardTool->__invoke($cpf, $kw);
                break;
            case 'ir':
                $this->irInformTool->setConversationId($conversationId);
                $this->irInformTool->setKw($kw);
                Log::info('Pending.run.ir', ['conv' => $conversationId]);
                $responseText = $this->irInformTool->__invoke($cpf, null, $kw);
                break;
            case 'ticket':
                $this->ticketTool->setConversationId($conversationId);
                Log::info('Pending.run.ticket', ['conv' => $conversationId]);
                $responseText = $this->ticketTool->__invoke($cpf);
                break;
            default:
                Log::info('Pending.skip_reason', ['conv' => $conversationId, 'reason' => 'intent_not_supported', 'intent' => $intent]);
                return null;
        }

        if ($responseText === null) {
            return null;
        }

        $this->redisConversationService->addMessage($conversationId, 'assistant', $responseText);

        $payload = $this->buildResponsePayload($conversationId, $responseText, $kw);
        $payload['login'] = false;

        return $payload;
    }

}
