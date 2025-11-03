<?php

namespace App\Services;

use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;

class CanonicalConversationService
{
    private const ALIAS_PHONE_PREFIX = 'conv:alias:wa:';
    private const ALIAS_WEB_PREFIX = 'conv:alias:web:';
    private const CANONICAL_BY_CPF_PREFIX = 'conv:canonical:by_cpf:';
    private $redisAlias;
    private $redisCache;

    public function __construct(
        private RedisConversationService $redisConversations
    ) {
        $this->redisAlias = Redis::connection('conversations');
        $this->redisCache = Redis::connection();
    }

    public function resolveForPhone(string $phone): ?string
    {
        return $this->redisAlias->get(self::ALIAS_PHONE_PREFIX . $phone) ?: null;
    }

    public function resolveForWeb(string $uuid): ?string
    {
        return $this->redisAlias->get(self::ALIAS_WEB_PREFIX . $uuid) ?: null;
    }

    public function ensureCanonicalForCpf(string $cpf): string
    {
        $hash = hash('sha256', preg_replace('/\D/', '', $cpf));
        $key = self::CANONICAL_BY_CPF_PREFIX . $hash;

        $canonical = $this->redisAlias->get($key);
        if ($canonical) {
            return $canonical;
        }

        $canonical = 'cpf:' . $hash;
        $this->redisAlias->set($key, $canonical);

        return $canonical;
    }

    public function linkPhoneToCpf(string $phone, string $cpf): string
    {
        $canonical = $this->ensureCanonicalForCpf($cpf);
        $this->redisAlias->set(self::ALIAS_PHONE_PREFIX . $phone, $canonical);

        $this->mergeConversations($phone, $canonical);
        $this->migrateCacheKeys($phone, $canonical);

        return $canonical;
    }

    public function linkWebToCpf(string $uuid, string $cpf): string
    {
        $canonical = $this->ensureCanonicalForCpf($cpf);
        $this->redisAlias->set(self::ALIAS_WEB_PREFIX . $uuid, $canonical);

        $this->mergeConversations($uuid, $canonical);
        $this->migrateCacheKeys($uuid, $canonical);

        return $canonical;
    }

    public function linkPhoneToCanonical(string $phone, string $canonical): void
    {
        $this->redisAlias->set(self::ALIAS_PHONE_PREFIX . $phone, $canonical);
        $this->mergeConversations($phone, $canonical);
        $this->migrateCacheKeys($phone, $canonical);
    }

    public function linkWebToCanonical(string $uuid, string $canonical): void
    {
        $this->redisAlias->set(self::ALIAS_WEB_PREFIX . $uuid, $canonical);
        $this->mergeConversations($uuid, $canonical);
        $this->migrateCacheKeys($uuid, $canonical);
    }

    private function mergeConversations(string $sourceId, string $targetId): void
    {
        if ($sourceId === $targetId) {
            return;
        }

        $sourceMessages = $this->redisConversations->getMessages($sourceId, 50);
        $targetMessages = $this->redisConversations->getMessages($targetId, 50);

        if (empty($sourceMessages) && empty($targetMessages)) {
            $this->redisConversations->clearConversation($sourceId);
            $this->redisConversations->forgetMetadata($sourceId);
            return;
        }

        $merged = array_merge($targetMessages, $sourceMessages);
        usort($merged, function ($a, $b) {
            return strcmp($a['timestamp'] ?? '', $b['timestamp'] ?? '');
        });

        $unique = [];
        $deduped = [];
        foreach ($merged as $message) {
            if (!is_array($message)) {
                continue;
            }
            $key = ($message['timestamp'] ?? '') . '|' . ($message['role'] ?? '') . '|' . md5((string) ($message['content'] ?? ''));
            if (isset($unique[$key])) {
                continue;
            }
            $unique[$key] = true;
            $deduped[] = $message;
        }

        $merged = $deduped;

        $this->redisConversations->replaceMessages($targetId, $merged);

        $sourceMeta = $this->redisConversations->getMetadata($sourceId);
        $targetMeta = $this->redisConversations->getMetadata($targetId);

        $mergedMeta = $targetMeta;
        foreach ($sourceMeta as $key => $value) {
            if (!array_key_exists($key, $mergedMeta) || $mergedMeta[$key] === null || $mergedMeta[$key] === '') {
                $mergedMeta[$key] = $value;
            }
        }

        if (!empty($mergedMeta)) {
            $this->redisConversations->setMetadata($targetId, $mergedMeta);
        }

        $this->redisConversations->clearConversation($sourceId);
        $this->redisConversations->forgetMetadata($sourceId);
    }

    private function migrateCacheKeys(string $sourceId, string $targetId): void
    {
        if ($sourceId === $targetId) {
            return;
        }

        // Lista de sufixos relevantes utilizados no fluxo do assistente.
        // A migração é feita usando o Cache atual (file/database/redis), para
        // funcionar de forma consistente independente do driver configurado.
        $suffixes = [
            'kw_value', 'kw_status', 'kw_hash',
            'last_cpf', 'intent', 'last_user_text',
            'pending_request', 'card_payload_request',
            'last_tool', 'boletos', 'ticket_error', 'ticket_error_detail',
            'beneficiarios', 'planos', 'fichafinanceira', 'coparticipacao',
            'ir_documentos', 'ir_documento',
        ];

        foreach ($suffixes as $suffix) {
            $oldKey = "conv:{$sourceId}:{$suffix}";
            $newKey = "conv:{$targetId}:{$suffix}";

            $value = \Cache::get($oldKey);
            if ($value === null) {
                continue;
            }

            // Define TTL de forma coerente com a origem do dado.
            // - KW/pending/last_user_text: até o fim do dia
            // - last_cpf: permanente
            // - Demais: 3600s (como nas gravações originais)
            $ttl = 3600; // padrão
            $useForever = false;

            if (in_array($suffix, ['kw_value', 'kw_status', 'kw_hash', 'pending_request', 'last_user_text'], true)) {
                $ttl = now()->endOfDay()->diffInSeconds();
            } elseif ($suffix === 'last_cpf') {
                $useForever = true;
            }

            if ($useForever) {
                \Cache::forever($newKey, $value);
            } else {
                \Cache::put($newKey, $value, $ttl);
            }

            \Cache::forget($oldKey);
        }
    }
}
