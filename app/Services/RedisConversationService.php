<?php

// app/Services/RedisConversationService.php
namespace App\Services;

use Illuminate\Support\Facades\Redis;

class RedisConversationService
{
    //private const CONVERSATION_TTL = 3600; // 1 hour
    //private const CONTEXT_TTL = 1800; // 30 minutes
    private $maxMessages;

    public function __construct(private $conversationRedis = null) {
        $this->maxMessages = env('MAX_MESSAGES_REDIS', 30);
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
        $this->conversationRedis->ltrim($key, 0, $this->maxMessages - 1);

        // Define TTL
        //$this->conversationRedis->expire($key, self::CONVERSATION_TTL);
    }

    public function replaceMessages(string $sessionId, array $messages): void
    {
        $key = "messages:{$sessionId}";
        $this->conversationRedis->del($key);

        $limited = array_slice($messages, -$this->maxMessages);

        foreach (array_reverse($limited) as $message) {
            if (!is_array($message)) {
                continue;
            }
            $this->conversationRedis->lpush($key, json_encode($message));
        }
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

    public function setMetadata(string $sessionId, array $metadata): void
    {
        $metaKey = "messages:{$sessionId}:meta";

        if (empty($metadata)) {
            return;
        }

        $this->conversationRedis->hmset($metaKey, $metadata);
    }

    public function setMetadataField(string $sessionId, string $field, mixed $value): void
    {
        $metaKey = "messages:{$sessionId}:meta";
        $this->conversationRedis->hset($metaKey, $field, $value);
    }

    public function getMetadata(string $sessionId): array
    {
        $metaKey = "messages:{$sessionId}:meta";
        $data = $this->conversationRedis->hgetall($metaKey);

        return is_array($data) ? $data : [];
    }

    public function getMetadataField(string $sessionId, string $field, mixed $default = null): mixed
    {
        $metaKey = "messages:{$sessionId}:meta";
        $value = $this->conversationRedis->hget($metaKey, $field);

        return $value !== null ? $value : $default;
    }

    public function forgetMetadata(string $sessionId, array $fields = []): void
    {
        $metaKey = "messages:{$sessionId}:meta";

        if (empty($fields)) {
            $this->conversationRedis->del($metaKey);
            return;
        }

        $this->conversationRedis->hdel($metaKey, ...$fields);
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

    public function getMaxMessages(): int
    {
        return $this->maxMessages;
    }
}
