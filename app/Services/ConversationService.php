<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Carbon\Carbon;

class ConversationService
{
    private $ttl = 86400; // 24 horas
    private const MAX_MESSAGES = 50;

    /**
     * Adiciona mensagem à conversa
     */
    public function addMessage(string $conversationId, string $role, string $content): bool
    {
        $key = "conversations:messages:{$conversationId}";

        $message = [
            'role' => $role,
            'content' => $content,
            'timestamp' => Carbon::now()->toISOString(),
        ];

        Redis::rpush($key, json_encode($message));

        Redis::ltrim($key, 0, self::MAX_MESSAGES - 1);

        //Redis::expire($key, $this->ttl);

        return true;
    }

    /**
     * Obtém todas as mensagens da conversa
     */
    public function getMessages(string $conversationId): array
    {
        $key = "conversations:messages:{$conversationId}";
        $messages = Redis::lrange($key, 0, -1);

        return array_map(function($message) {
            return json_decode($message, true);
        }, array_reverse($messages));
    }

    /**
     * Verifica se uma conversa existe
     */
    public function conversationExists(string $conversationId): bool
    {
        $key = "conversations:messages:{$conversationId}";
        return Redis::exists($key) > 0;
    }

    /**
     * Limpa todas as mensagens da conversa
     */
    public function clearConversation(string $conversationId): bool
    {
        $key = "conversations:messages:{$conversationId}";
        Redis::del($key);
        return true;
    }

    /**
     * Obtém apenas as últimas N mensagens
     */
    public function getLastMessages(string $conversationId, int $count = 10): array
    {
        $key = "conversations:messages:{$conversationId}";
        $messages = Redis::lrange($key, -$count, -1);

        return array_map(function($message) {
            return json_decode($message, true);
        }, array_reverse($messages));
    }

    /**
     * Conta o número total de mensagens
     */
    public function getMessageCount(string $conversationId): int
    {
        $key = "conversations:messages:{$conversationId}";
        return Redis::llen($key);
    }

    /**
     * Adiciona cpf no Redis
     */
    public function setCPF(string $conversationId, string $cpf)
    {
        $key = "conversations:cpf:{$conversationId}";

        // só seta se a chave ainda não existir
        if (!Redis::exists($key)) {
            Redis::set($key, json_encode([
                'cpf' => $cpf,
                'timestamp' => Carbon::now()->toISOString(),
            ]));
            Redis::expire($key, $this->ttl);
        }

        return true;
    }

    /**
     * Recuperar cpf do Redis
     */
    public function getCPF(string $conversationId)
    {
        $key = "conversations:cpf:{$conversationId}";
        $value = Redis::get($key);

        return $value ? json_decode($value, true) : null;
    }
}
