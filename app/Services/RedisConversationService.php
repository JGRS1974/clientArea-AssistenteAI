<?php

// app/Services/RedisConversationService.php
namespace App\Services;

use Illuminate\Support\Facades\Redis;

class RedisConversationService
{
    //private const CONVERSATION_TTL = 3600; // 1 hour
    //private const CONTEXT_TTL = 1800; // 30 minutes
    private const MAX_MESSAGES = 50;

    public function __construct(private $conversationRedis = null) {
        $this->conversationRedis = Redis::connection('conversations');
    }

    /**
     * Adiciona uma mensagem à conversa
     */
    public function addMessage(string $sessionId, string $role, string $content): void
    {
        $message = [
            'role' => $role,
            'content' => $content,
            'timestamp' => now()->toISOString(),
        ];

        $key = "messages:{$sessionId}";

        // Adiciona a mensagem à lista
        $this->conversationRedis->lpush($key, json_encode($message));

        // Mantém apenas as últimas X mensagens
        $this->conversationRedis->ltrim($key, 0, self::MAX_MESSAGES - 1);

        // Define TTL
        //$this->conversationRedis->expire($key, self::CONVERSATION_TTL);
    }

    /**
     * Recupera o histórico de mensagens
     */
    public function getMessages(string $sessionId, int $limit = 20): array
    {
        $key = "messages:{$sessionId}";
        $messages = $this->conversationRedis->lrange($key, 0, $limit - 1);

        return array_map(function ($message) {
            return json_decode($message, true);
        }, array_reverse($messages)); // Reverse para ordem cronológica
    }


    /**
     * Limpa a conversa
     */
    public function clearConversation(string $sessionId): void
    {
        $this->conversationRedis->del("messages:{$sessionId}");
    }

    /**
     * Verifica se a sessão existe
     */
    public function sessionExists(string $sessionId): bool
    {
        return $this->conversationRedis->exists("messages:{$sessionId}") > 0;
    }

    /**
     * Recupera estatísticas da conversa
     */
    public function getConversationStats(string $sessionId): array
    {
        $messages = $this->getMessages($sessionId);

        return [
            'session_id' => $sessionId,
            'message_count' => count($messages),
            'last_activity' => $context['last_activity'] ?? null,
            'current_action' => $context['current_action'] ?? null,
            'awaiting_data' => $context['awaiting_data'] ?? null,
            'has_client_info' => !empty($context['client_info']),
        ];
    }
}
