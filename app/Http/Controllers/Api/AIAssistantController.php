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
use App\Services\RedisConversationService;
use Prism\Prism\Exceptions\PrismException;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;

class AIAssistantController extends Controller
{
    protected $ticketTool;
    protected $cardTool;
    protected $conversationIdService;
    protected $redisConversationService;

    public function __construct(TicketTool $ticketTool, CardTool $cardTool, ConversationIdService $conversationIdService, RedisConversationService $redisConversationService)
    {
        $this->ticketTool = $ticketTool;
        $this->cardTool = $cardTool;
        $this->conversationIdService = $conversationIdService;
        $this->redisConversationService = $redisConversationService;
    }

    public function chat(Request $request)
    {
        //$request->validate([
        //    'cpf' => 'required|string',
        //]);

        $text = $request->input('text');

        $kw = request()->header('kw', null);
        $this->cardTool->setKw($kw);

        $conversationId = $this->conversationIdService->setConversationId($request);

        $addMessage = $this->redisConversationService->addMessage($conversationId,'user', $text);

        $getMessages = $this->redisConversationService->getMessages($conversationId);

        $messages = [ new SystemMessage(view('prompts.assistant-prompt', ['kw' => $kw])->render())];

        foreach ($getMessages as $key => $message) {
            if ($message['role'] == 'user'){
                $messages[] = new UserMessage($message['content']);
            } else {
                $messages[] = new AssistantMessage($message['content']);
            }
        }

        try{
            $response = Prism::text()
                ->using(Provider::OpenAI, 'gpt-5-mini')
                ->withMessages($messages)
                ->withMaxSteps(3)
                ->withTools([
                    $this->ticketTool,
                    $this->cardTool
                ])
                ->asText();

            $addMessage = $this->redisConversationService->addMessage($conversationId,'assistant', $response->text);
            //ds('Response AI', $response->text);
            return response()->json([
                'text' => $response->text,
                'conversation_id' => $conversationId
            ]);
        } catch (PrismException $e) {
            Log::error('Text generation failed:', ['error' => $e->getMessage()]);
        } catch (Throwable $e) {
            Log::error('Generic error:', ['error' => $e->getMessage()]);
        }
    }
}
