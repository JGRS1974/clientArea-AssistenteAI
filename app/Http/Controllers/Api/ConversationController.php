<?php

// app/Http/Controllers/Api/ConversationController.php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RedisConversationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationController extends Controller
{
    public function __construct(
        private RedisConversationService $conversationService
    ) {}

    /**
     * Recupera dados da conversa
     */
    public function getConversation(string $sessionId): JsonResponse
    {
        try {
            $stats = $this->conversationService->getConversationStats($sessionId);
            $context = $this->conversationService->getContext($sessionId);

            return response()->json([
                'success' => true,
                'data' => [
                    'stats' => $stats,
                    'context' => $context
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erro ao recuperar conversa'
            ], 500);
        }
    }

    /**
     * Recupera histórico de mensagens
     */
    public function getHistory(string $sessionId, Request $request): JsonResponse
    {
        try {
            $limit = (int) $request->get('limit', 20);
            $limit = min(max($limit, 1), 100); // Entre 1 e 100

            $messages = $this->conversationService->getMessages($sessionId, $limit);

            return response()->json([
                'success' => true,
                'data' => [
                    'messages' => $messages,
                    'total' => count($messages),
                    'session_id' => $sessionId
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erro ao recuperar histórico'
            ], 500);
        }
    }

    /**
     * Reseta a conversa
     */
    public function resetConversation(string $sessionId): JsonResponse
    {
        try {
            $this->conversationService->clearConversation($sessionId);

            return response()->json([
                'success' => true,
                'message' => 'Conversa resetada com sucesso'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erro ao resetar conversa'
            ], 500);
        }
    }

    /**
     * Deleta a conversa
     */
    public function deleteConversation(string $sessionId): JsonResponse
    {
        try {
            $this->conversationService->clearConversation($sessionId);

            return response()->json([
                'success' => true,
                'message' => 'Conversa deletada com sucesso'
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Erro ao deletar conversa'
            ], 500);
        }
    }
}
