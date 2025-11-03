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
use Illuminate\Support\Str;
use App\Http\Controllers\Controller;
use App\Services\ConversationIdService;
use App\Services\ConversationService;
use App\Services\AudioTranscriptionService;
use App\Services\ImageAnalysisService;
use App\Services\AssistantMessageBuilder;
use App\Services\CanonicalConversationService;
use App\Services\RedisConversationService;
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
        AssistantMessageBuilder $assistantMessages
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
                }
            }

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

            $detectedIntent = $this->detectIntentFromMessage($userInput);
            if ($detectedIntent) {
                $this->storeIntent($conversationId, $detectedIntent);
            }

            $payloadRequest = $this->detectPayloadRequestFromMessage($userInput);
            $this->storePayloadRequest($conversationId, $payloadRequest);

            // Adiciona mensagem do usuário à conversa
            //$this->conversationService->addMessage($conversationId, 'user', $userInput);
            $this->redisConversationService->addMessage($conversationId,'user', $userInput);

            // Gera resposta da AI
            $response = $this->generateAIResponse($conversationId, $kw);
            //ds(['Response AI' => $response]);
            // Adiciona resposta da AI à conversa
            //$this->conversationService->addMessage($conversationId, 'assistant', $response);
            $this->redisConversationService->addMessage($conversationId,'assistant', $response);

            $payload = $this->buildResponsePayload($conversationId, $response);

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
    private function generateAIResponse(string $conversationId, ?string $kw)
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

        // Monta mensagens para o Prism
        $this->cardTool->setKw($kw);
        $this->irInformTool->setKw($kw);

        $payloadRequest = $this->getStoredPayloadRequest($conversationId);
        $requestedFields = $payloadRequest['fields'] ?? [];
        $primaryCardField = $this->determinePrimaryCardField($requestedFields);

        $ticketErrorKey = $this->getConversationCacheKey($conversationId, 'ticket_error');
        $ticketErrorDetailKey = $this->getConversationCacheKey($conversationId, 'ticket_error_detail');
        $ticketError = Cache::get($ticketErrorKey);
        $ticketErrorDetail = Cache::get($ticketErrorDetailKey);

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
                'intentNow' => $this->getStoredIntent($conversationId),
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
            $tools = [];

            $isLoggedIn = $statusLogin === 'usuário logado';
            $intentNow = $this->getStoredIntent($conversationId);

            // Gateamento de ferramentas por intenção para evitar chamadas indevidas
            switch ($intentNow) {
                case 'ticket':
                    if ($storedCpf) {
                        $tools[] = $this->ticketTool;
                    }
                    break;
                case 'card':
                    if ($storedCpf && $isLoggedIn) {
                        $tools[] = $this->cardTool;
                    }
                    break;
                case 'ir':
                    if ($storedCpf && $isLoggedIn) {
                        $tools[] = $this->irInformTool;
                    }
                    break;
                default:
                    // Sem intenção clara: não expõe tools; o assistente pede clarificação
                    break;
            }

            $response = Prism::text()
                ->using(Provider::OpenAI, 'gpt-4.1')
                ->withMessages($messages)
                ->withMaxSteps(3)
                ->withTools($tools)
                ->withProviderOptions([
                    'temperature' => 0.85,
                    'top_p' => 0.9,
                    'frequency_penalty' => 0.3,
                    'presence_penalty' => 0.2,
                ])
                ->asText();

            return $response->text;

        } catch (PrismException $e) {
            Log::error('Text generation failed:', ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Generic error:', ['error' => $e->getMessage()]);
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

    private function buildResponsePayload(string $conversationId, ?string $responseText): array
    {
        $payload = [
            'text' => $responseText ?? '',
            'conversation_id' => $conversationId,
        ];

        $lastToolKey = $this->getConversationCacheKey($conversationId, 'last_tool');
        $lastTool = Cache::get($lastToolKey);
        $shouldShowLogin = $this->shouldShowLoginButton($conversationId);
        $intent = $this->getStoredIntent($conversationId);
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
            if (empty($fieldsToInclude)) {
                foreach ($dataMap as $field => $_) {
                    $raw = $rawByField[$field] ?? null;
                    if (is_array($raw) && !empty($raw)) {
                        $fieldsToInclude[] = $field;
                    }
                }
                $fieldsToInclude = array_values(array_unique($fieldsToInclude));
            }

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
                $requestedFields
            );

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
            }
        } else {
            $messagesForHeuristic = $this->redisConversationService->getMessages($conversationId);

            if ($intent === 'card') {
            $payload['login'] = $shouldShowLogin;

            if ($shouldShowLogin) {
                $text = $payload['text'] ?? '';

                if (
                    $this->messageContradictsLogin($text) ||
                    !$this->messageMentionsLogin($text) ||
                    $this->messageAsksForCpf($text)
                ) {
                    $payload['text'] = $this->buildLoginReminderMessage($conversationId, $requestedFields);
                }
            }
        } elseif ($intent === 'ir' || ($intent === null && $this->looksLikeIrRequest($messagesForHeuristic))) {
            $payload['login'] = $shouldShowLogin;

                if ($shouldShowLogin && $this->messageContradictsLogin($payload['text'] ?? '')) {
                    $payload['text'] = $this->buildIrLoginReminderMessage();
                }
            }
        }

        Cache::forget($lastToolKey);
        Cache::forget($this->getConversationCacheKey($conversationId, 'ticket_error'));
        Cache::forget($this->getConversationCacheKey($conversationId, 'ticket_error_detail'));

        return $this->enforceCpfRequirement($payload, $conversationId, $intent, $requestedFields);
    }

    private function enforceCpfRequirement(array $payload, string $conversationId, ?string $intent, array $requestedFields): array
    {
        if ($intent !== 'card') {
            return $payload;
        }

        if ($this->shouldShowLoginButton($conversationId)) {
            return $payload;
        }

        $storedCpf = $this->getStoredCpf($conversationId);

        if ($storedCpf) {
            return $payload;
        }

        $payload['cpf_required'] = true;

        $text = $payload['text'] ?? '';

        if (!$this->messageAsksForCpf($text)) {
            $primaryField = $this->determinePrimaryCardField($requestedFields);
            $payload['text'] = $this->assistantMessages->requestCpfForField($primaryField);
        }

        return $payload;
    }

    private function shouldShowLoginButton(string $conversationId): bool
    {
        $kw = Cache::get($this->getConversationCacheKey($conversationId, 'kw_value'));
        $kwStatus = Cache::get($this->getConversationCacheKey($conversationId, 'kw_status'));

        return $this->resolveStatusLogin($kw, $kwStatus) !== 'usuário logado';
    }

    private function detectIntentFromMessage(string $message): ?string
    {
        $normalized = mb_strtolower($message, 'UTF-8');

        if (preg_match('/\b(boleto|segunda via|2a via|fatura|pagamento)\b/u', $normalized)) {
            return 'ticket';
        }

        $isIrIntent =
            preg_match('/\b(informes?\s*(?:de)?\s*rendimentos?)\b/u', $normalized) ||
            preg_match('/\b(informe\s*ir|ir\s*20\d{2}|irpf)\b/u', $normalized) ||
            preg_match('/\b(imposto\s*de\s*renda|dirf|comprovante\s*(?:do\s*)?imposto\s*de\s*renda)\b/u', $normalized) ||
            preg_match('/\b(demonstrat(?:ivo|ivo\s*de)\s*pagament(?:o|os))\b/u', $normalized) ||
            preg_match('/\b(?:o|seu|meu)\s*ir\b/u', $normalized);

        if ($isIrIntent) {
            return 'ir';
        }

        $isCardIntent =
            preg_match('/carteir|cart[ãa]o virtual|documento digital/u', $normalized) ||
            preg_match('/\b(planos?|contratos?)\b/u', $normalized) ||
            preg_match('/relat[óo]rio\s*financeir[oa]|ficha\s*financeir[oa]|(?:meu|minha|seu|sua|o|a)?\s*financeir[oa]\b|\bfinanceir[oa]\b/u', $normalized) ||
            preg_match('/co[-\s]?participa[cç][aã]o/u', $normalized) ||
            $this->matchesPaymentsRequest($normalized);

        if ($isCardIntent) {
            return 'card';
        }

        return null;
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
        $fields = $payloadRequest['fields'] ?? [];

        if (empty($fields)) {
            return;
        }

        $contractFilters = $this->normalizeContractFilters($payloadRequest['contract_filters'] ?? []);
        $periodFilters = $payloadRequest['period_filters'] ?? [
            'references' => [],
            'months' => [],
            'years' => [],
        ];

        $periodFilters = [
            'references' => array_values(array_unique($periodFilters['references'] ?? [])),
            'months' => array_values(array_unique($periodFilters['months'] ?? [])),
            'years' => array_values(array_unique($periodFilters['years'] ?? [])),
        ];

        $payload = [
            'fields' => array_values(array_unique($fields)),
            'contract_filters' => $contractFilters,
            'period_filters' => $periodFilters,
        ];

        $cacheKey = $this->getConversationCacheKey($conversationId, 'card_payload_request');
        Cache::put($cacheKey, $payload, 3600);
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
            return $originalText;
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

        return $originalText;
    }

    private function adjustCardText(string $originalText, array $payload, array $requestedFields): string
    {
        $fields = array_values(array_intersect($requestedFields, ['planos', 'fichafinanceira', 'coparticipacao', 'beneficiarios']));

        if (empty($fields)) {
            return $originalText;
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
                    }
                    break;
                case 'beneficiarios':
                    if ($this->isBeneficiariesEmpty($data)) {
                        $messages[] = $this->assistantMessages->cardNotFound('beneficiarios');
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
                    }
                    break;
            }
        }

        $messages = array_values(array_unique(array_filter($messages)));

        if (empty($messages)) {
            return $originalText;
        }

        $lineBreak = (string) config('assistant.line_break', '<br>');
        $joined = implode($lineBreak, $messages);

        return $this->assistantMessages->withFollowUp($joined);
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
            '/\\bmeus?\\s+pagamentos\\b/u',
            '/\\bseus?\\s+pagamentos\\b/u',
            '/\\bconsult(ar|e|o)\\s+(?:os\\s+)?pagamentos\\b/u',
            '/\\bmostrar\\s+(?:os\\s+)?pagamentos\\b/u',
            '/\\bmostre\\s+(?:os\\s+)?pagamentos\\b/u',
            '/\\bver\\s+(?:os\\s+)?pagamentos\\b/u',
            '/\\blistar\\s+(?:os\\s+)?pagamentos\\b/u',
            '/\\bextrato\\s+(?:de\\s+)?pagamentos\\b/u',
            '/\\bhist[óo]rico\\s+(?:de\\s+)?pagamentos\\b/u',
            '/\\bpagamentos\\s+(?:do|da|dos|das)\\b/u',
            '/\\bpagamentos\\b/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $normalized)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeIrRequest(array $conversationMessages): bool
    {
        for ($i = count($conversationMessages) - 1; $i >= 0; $i--) {
            $message = $conversationMessages[$i] ?? null;

            if (!is_array($message) || ($message['role'] ?? '') !== 'user') {
                continue;
            }

            $text = Str::lower($message['content'] ?? '');

            return (bool) (
                preg_match('/\b(irpf|imposto\s*de\s*renda|dirf)\b/u', $text) ||
                preg_match('/\b(informes?\s*(?:de)?\s*rendimentos?)\b/u', $text) ||
                preg_match('/\b(informe\s*ir|ir\s*20\d{2})\b/u', $text) ||
                preg_match('/\b(comprovante\s*(?:do\s*)?imposto\s*de\s*renda)\b/u', $text) ||
                preg_match('/\b(demonstrat(?:ivo|ivo\s*de)\s*pagament(?:o|os))\b/u', $text) ||
                preg_match('/\b(?:o|seu|meu)\s*ir\b/u', $text)
            );
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

        $patterns = [
            'ja fiz login',
            'já fiz login',
            'ja fiz o login',
            'já fiz o login',
            'fiz login',
            'login concluido',
            'login concluído',
            'já realizei o login',
            'ja realizei o login',
            'acabei de fazer login',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, Str::ascii($pattern))) {
                return true;
            }
        }

        return false;
    }

    private function handlePendingRequest(string $conversationId, ?string $kw): ?array
    {
        $pendingKey = $this->getConversationCacheKey($conversationId, 'pending_request');
        $pending = Cache::pull($pendingKey);

        if (!$pending) {
            return null;
        }

        $kw = $kw ?? Cache::get($this->getConversationCacheKey($conversationId, 'kw_value'));
        $cpf = $this->getStoredCpf($conversationId);

        if (!$kw || !$cpf) {
            return null;
        }

        $intent = $pending['intent'] ?? $this->getStoredIntent($conversationId);
        if (!$intent) {
            return null;
        }

        $responseText = null;

        switch ($intent) {
            case 'card':
                $this->cardTool->setConversationId($conversationId);
                $this->cardTool->setKw($kw);
                $responseText = $this->cardTool->__invoke($cpf, $kw);
                break;
            case 'ir':
                $this->irInformTool->setConversationId($conversationId);
                $this->irInformTool->setKw($kw);
                $responseText = $this->irInformTool->__invoke($cpf, null, $kw);
                break;
            case 'ticket':
                $this->ticketTool->setConversationId($conversationId);
                $responseText = $this->ticketTool->__invoke($cpf);
                break;
            default:
                return null;
        }

        if ($responseText === null) {
            return null;
        }

        $this->redisConversationService->addMessage($conversationId, 'assistant', $responseText);

        $payload = $this->buildResponsePayload($conversationId, $responseText);
        $payload['login'] = false;

        return $payload;
    }

}
