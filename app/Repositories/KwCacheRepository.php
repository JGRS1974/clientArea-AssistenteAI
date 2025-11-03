<?php

namespace App\Repositories;

use Illuminate\Support\Facades\Cache;

class KwCacheRepository
{
    public function get(string $phone): ?string
    {
        $key = $this->key($phone, 'kw_value');
        $kw = Cache::get($key);
        if ($kw) {
            Cache::put($key, $kw, 3600);
        }
        return $kw ?: null;
    }

    public function putUntilEndOfDay(string $phone, string $kw): void
    {
        $ttl = now()->endOfDay()->diffInSeconds();
        Cache::put($this->key($phone, 'kw_value'), $kw, $ttl);
        Cache::put($this->key($phone, 'kw_status'), 'valid', $ttl);
        Cache::put($this->key($phone, 'kw_hash'), hash('sha256', $kw), $ttl);
    }

    public function markInvalid(string $phone): void
    {
        Cache::put($this->key($phone, 'kw_status'), 'invalid', 3600);
    }

    public function rememberCpf(string $phone, string $cpf): void
    {
        $normalized = preg_replace('/\D/', '', $cpf);
        if (strlen($normalized) !== 11) {
            return;
        }
        Cache::forever($this->key($phone, 'last_cpf'), $normalized);
    }

    private function key(string $phone, string $suffix): string
    {
        return "conv:{$phone}:{$suffix}";
    }
}
