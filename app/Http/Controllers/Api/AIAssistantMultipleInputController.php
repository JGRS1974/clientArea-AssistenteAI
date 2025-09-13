<?php

namespace App\Http\Controllers\Api;

use Throwable;
use Prism\Prism\Prism;
use App\Tools\CardTool;
use App\Tools\TicketTool;
use Illuminate\Http\Request;
use Prism\Prism\Enums\Provider;
use Illuminate\Support\Facades\Log;
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

        //Verifica se foi enviada a chave de acesso no sistema após login
        $kw = request()->header('kw', null);

        try {
            // Processa a entrada do usuário
            $userInput = $this->processUserInput($request);
            if (!$userInput) {
                return response()->json(['error' => 'Falha ao processar entrada'], 400);
            }
            //ds(['Input' => $userInput]);

            // Adiciona mensagem do usuário à conversa
            //$this->conversationService->addMessage($conversationId, 'user', $userInput);
            $this->redisConversationService->addMessage($conversationId,'user', $userInput);

            // Gera resposta da AI
            $response = $this->generateAIResponse($conversationId, $kw);
            //ds(['Response AI' => $response]);
            // Adiciona resposta da AI à conversa
            //$this->conversationService->addMessage($conversationId, 'assistant', $response);
            $this->redisConversationService->addMessage($conversationId,'assistant', $response);

            return response()->json([
                'text' => $response,
                'conversation_id' => $conversationId
            ]);

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

       $statusLogin = isset($kw) ? 'usuário logado' : 'usuário nao logado';

        // Monta mensagens para o Prism
        $messages = [
            new SystemMessage(view('prompts.assistant-prompt', ['kw' => $kw, 'statusLogin' => $statusLogin])->render())
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
                    'temperature' => 0.3,
                ])
                ->asText();

            return $response->text;

        } catch (PrismException $e) {
            Log::error('Text generation failed:', ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Generic error:', ['error' => $e->getMessage()]);
        }
    }

    public function getCPFOfMessage($userMessage){

        $regex = '/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/';

        if (preg_match($regex, $userMessage, $matches)) {
            $cpf = preg_replace('/\D/', '', $matches[0]);
        } else {
            $cpf = null;
        }
    }
}
