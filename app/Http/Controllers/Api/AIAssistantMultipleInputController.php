<?php

namespace App\Http\Controllers\Api;

use Throwable;
use Prism\Prism\Prism;
use App\Tools\CardTool;
use App\Tools\TicketTool;
use Illuminate\Http\Request;
use Prism\Prism\Enums\Provider;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
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
    protected $conversationIdService;
    protected $conversationService;
    protected $audioTranscriptionService;
    protected $imageAnalysisService;
    protected $redisConversationService;

    public function __construct(
        TicketTool $ticketTool,
        CardTool $cardTool,
        ConversationIdService $conversationIdService,
        ConversationService $conversationService,
        AudioTranscriptionService $audioTranscriptionService,
        ImageAnalysisService $imageAnalysisService,
        RedisConversationService $redisConversationService
    ) {
        $this->ticketTool = $ticketTool;
        $this->cardTool = $cardTool;
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
        $kw = $request->header('kw', null);

        $this->syncKwStatusWithHeader($conversationId, $kw);
        $this->ticketTool->setConversationId($conversationId);
        $this->cardTool->setConversationId($conversationId);
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
                'ticketError' => $ticketError,
                'ticketErrorDetail' => $ticketErrorDetail,
            ])->render())
        ];

        foreach ($conversationMessages as $message) {
            if ($message['role'] === 'user') {
                $messages[] = new UserMessage($message['content']);
            } else {
                $messages[] = new AssistantMessage($message['content']);
            }
        }

        try{
            $response = Prism::text()
                ->using(Provider::OpenAI, 'gpt-4.1')
                ->withMessages($messages)
                ->withMaxSteps(3)
                ->withTools([
                    $this->ticketTool,
                    $this->cardTool
                ])
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
            Cache::put($hashKey, $currentHash, 3600);
            Cache::put($valueKey, $kw, 3600);
            Cache::forget($statusKey);
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

        if ($lastTool === 'ticket') {
            $ticketsKey = $this->getConversationCacheKey($conversationId, 'boletos');
            $tickets = Cache::get($ticketsKey);
            if (is_array($tickets)) {
                $payload['boletos'] = $tickets;
            }
            Cache::forget($ticketsKey);
        } elseif ($lastTool === 'card') {
            $beneficiariesKey = $this->getConversationCacheKey($conversationId, 'beneficiarios');
            $beneficiaries = Cache::get($beneficiariesKey);
            if (is_array($beneficiaries)) {
                $payload['beneficiarios'] = $beneficiaries;
            }
            Cache::forget($beneficiariesKey);
        }

        Cache::forget($lastToolKey);
        Cache::forget($this->getConversationCacheKey($conversationId, 'ticket_error'));
        Cache::forget($this->getConversationCacheKey($conversationId, 'ticket_error_detail'));

        return $payload;
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
