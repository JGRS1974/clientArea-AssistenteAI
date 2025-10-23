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

    public function __construct(
        TicketTool $ticketTool,
        CardTool $cardTool,
        IrInformTool $irInformTool,
        ConversationIdService $conversationIdService,
        ConversationService $conversationService,
        AudioTranscriptionService $audioTranscriptionService,
        ImageAnalysisService $imageAnalysisService,
        RedisConversationService $redisConversationService
    ) {
        $this->ticketTool = $ticketTool;
        $this->cardTool = $cardTool;
        $this->irInformTool = $irInformTool;
        $this->conversationIdService = $conversationIdService;
        $this->conversationService = $conversationService;
        $this->audioTranscriptionService = $audioTranscriptionService;
        $this->imageAnalysisService = $imageAnalysisService;
        $this->redisConversationService = $redisConversationService;
    }

    public function chat(Request $request)
    {
        $request->validate([
            'text' => 'nullable|string|max:4000',
            'audio' => 'nullable|file|mimes:mp3,wav,m4a,ogg,webm,flac,aac|max:25600', // 25MB
            'image' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120', // 5MB
        ]);

        // Deve ter pelo menos um tipo de entrada
        if (!$request->input('text') && !$request->hasFile('audio') && !$request->hasFile('image')) {
            return response()->json(['error' => 'Deve fornecer texto, áudio ou imagem'], 400);
        }

        $conversationId = $this->conversationIdService->setConversationId($request);
        $kw = $request->kw;
        Log::info('kw enviado payload ' . $kw);
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

            if ($storedCpf) {
                $tools[] = $this->ticketTool;
                $tools[] = $this->cardTool;
                $tools[] = $this->irInformTool;
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

            $payloadRequest = $this->getStoredPayloadRequest($conversationId);
            $requestedFields = $payloadRequest['fields'] ?? [];
            $contractFilters = $payloadRequest['contract_filters'] ?? [];
            $periodFilters = $payloadRequest['period_filters'] ?? [];

            $requestedFields = array_values(array_unique(array_filter($requestedFields)));
            $originalText = $payload['text'];

            $dataMap = [
                'beneficiarios' => 'beneficiarios',
                'planos' => 'planos',
                'fichafinanceira' => 'fichafinanceira',
                'coparticipacao' => 'coparticipacao',
            ];

            foreach ($dataMap as $field => $suffix) {
                $cacheKey = $this->getConversationCacheKey($conversationId, $suffix);
                $rawData = $cacheKey ? Cache::get($cacheKey) : null;

                if ($cacheKey) {
                    Cache::forget($cacheKey);
                }

                if (!in_array($field, $requestedFields, true)) {
                    continue;
                }

                $data = is_array($rawData) ? $rawData : [];

                if (in_array($field, ['planos', 'fichafinanceira', 'coparticipacao'], true) && $this->hasMeaningfulContractFilters($contractFilters)) {
                    $data = $this->filterCardDataByContractFilters($data, $contractFilters, $field);
                }

                if (in_array($field, ['fichafinanceira', 'coparticipacao'], true)) {
                    $data = $this->filterCardDataByPeriod($data, $periodFilters, $field);
                }

                $data = array_values($data);

                $payload[$field] = $data;
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
                    $payloadRequest = $this->getStoredPayloadRequest($conversationId);
                    $requestedFields = $payloadRequest['fields'] ?? [];

                    if ($this->messageContradictsLogin($payload['text'] ?? '')) {
                        $payload['text'] = $this->buildLoginReminderMessage($requestedFields);
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
            preg_match('/co[-\s]?participa[cç][aã]o/u', $normalized);

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

        if ($hasCardRequest) {
            $fields[] = 'beneficiarios';
        }

        if ($hasFinanceRequest) {
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
        ];

        $filters['plan'] = $this->filterPlanStopwords(
            $this->extractTermsByKeywords($message, ['plano', 'planos'])
        );
        $filters['entidade'] = $this->extractTermsByKeywords($message, ['entidade', 'entidades']);
        $filters['operadora'] = $this->extractTermsByKeywords($message, ['operadora', 'operadoras']);
        $filters['fantasia'] = $this->extractTermsByKeywords($message, ['fantasia', 'nome fantasia', 'operadora fantasia']);

        $filters['id'] = $this->extractContractIdTerms($message);
        $filters['numerocontrato'] = $this->extractNumeroContratoTerms($message);

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
        foreach (['plan', 'entidade', 'operadora', 'fantasia', 'id', 'numerocontrato'] as $key) {
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

    private function filterPlanStopwords(array $terms): array
    {
        $stopwords = [
            'atual', 'atuais', 'vigente', 'vigentes', 'novo', 'novos', 'antigo', 'antigos',
            'meu', 'minha', 'seu', 'sua', 'plano', 'planos', 'contrato', 'contratos', 'um', 'o',
            'este', 'esse', 'essa', 'isso', 'aquele', 'aquela', 'principal', 'ativo', 'ativos'
        ];

        $filtered = [];

        foreach ($terms as $term) {
            $normalized = $this->normalizeText($term);

            if ($normalized === '' || in_array($normalized, $stopwords, true)) {
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
            return "Não encontrei boletos disponíveis no momento.<br>Posso ajudar em mais alguma coisa?";
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
            return "Encontrei boletos em aberto e outros vencidos. Os indisponíveis mostram o motivo na lista.<br>Posso ajudar em mais alguma coisa?";
        }

        if ($available === 0 && $unavailable > 0) {
            return "Não encontrei boletos disponíveis; os registros atuais estão vencidos.<br>Posso ajudar em mais alguma coisa?";
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
                        $messages[] = "Não encontrei planos associados ao seu CPF.<br>Posso ajudar em mais alguma coisa?";
                    }
                    break;
                case 'beneficiarios':
                    if ($this->isBeneficiariesEmpty($data)) {
                        $messages[] = "Não encontrei carteirinhas vinculadas ao seu CPF.<br>Posso ajudar em mais alguma coisa?";
                    }
                    break;
                case 'fichafinanceira':
                    $emptyState = $this->analyzeFinancialData($data);
                    if ($emptyState === 'all_empty') {
                        $messages[] = "Não encontrei informações financeiras para esta consulta.<br>Posso ajudar em mais alguma coisa?";
                    } elseif ($emptyState === 'partial_empty') {
                        $messages[] = "Mostrei os lançamentos disponíveis; alguns planos não possuem registros.<br>Posso ajudar em mais alguma coisa?";
                    }
                    break;
                case 'coparticipacao':
                    $emptyState = $this->analyzeCoparticipationData($data);
                    if ($emptyState === 'all_empty') {
                        $messages[] = "Não encontrei registros de coparticipação para esta consulta.<br>Posso ajudar em mais alguma coisa?";
                    } elseif ($emptyState === 'partial_empty') {
                        $messages[] = "Exibi as coparticipações disponíveis; alguns planos não possuem registros.<br>Posso ajudar em mais alguma coisa?";
                    }
                    break;
            }
        }

        if (empty($messages)) {
            return $originalText;
        }

        return implode(' ', array_unique($messages));
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

    private function buildLoginReminderMessage(array $requestedFields): string
    {
        $primaryField = $this->determinePrimaryCardField($requestedFields);

        $labels = [
            'planos' => 'seus planos',
            'fichafinanceira' => 'seu relatório financeiro',
            'coparticipacao' => 'sua coparticipação',
            'beneficiarios' => 'sua carteirinha',
        ];

        $label = $labels[$primaryField] ?? 'sua carteirinha';

        return "Você precisa estar logado para consultar {$label}.<br>Faça login e me avise, por favor. 🙂";
    }

    private function buildIrLoginReminderMessage(): string
    {
        return "Você precisa estar logado para consultar seu informe de rendimentos.<br>Faça login e me avise, por favor. 🙂";
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
        Cache::put($cacheKey, $normalized, 3600);

        $this->redisConversationService->setMetadataField($conversationId, 'last_cpf', $normalized);
        $this->redisConversationService->setMetadataField($conversationId, 'last_cpf_at', now()->toISOString());
    }

    private function getStoredCpf(string $conversationId): ?string
    {
        $cacheKey = $this->getConversationCacheKey($conversationId, 'last_cpf');
        $cpf = Cache::get($cacheKey);

        if ($cpf && strlen($cpf) === 11) {
            Cache::put($cacheKey, $cpf, 3600);
            return $cpf;
        }

        $metaCpf = $this->redisConversationService->getMetadataField($conversationId, 'last_cpf');

        if ($metaCpf && strlen($metaCpf) === 11) {
            Cache::put($cacheKey, $metaCpf, 3600);
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

}
