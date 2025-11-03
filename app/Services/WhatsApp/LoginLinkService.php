<?php

namespace App\Services\WhatsApp;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class LoginLinkService
{
    /**
     * Gera link de login assinado para o número informado.
     */
    public function forPhone(string $phone, int $ttlMinutes = 15): string
    {
        $token = (string) Str::uuid();
        Cache::put($this->cacheKey($token), $phone, now()->addMinutes($ttlMinutes));

        $expiresAt = now()->addMinutes($ttlMinutes);
        $params = ['token' => $token];

        $appUrl = rtrim(config('app.url') ?? '', '/');
        if ($appUrl !== '') {
            $scheme = parse_url($appUrl, PHP_URL_SCHEME) ?: 'https';

            URL::forceRootUrl($appUrl);
            URL::forceScheme($scheme);

            try {
                return URL::temporarySignedRoute(
                    'whatsapp.login.show',
                    $expiresAt,
                    $params
                );
            } finally {
                URL::forceRootUrl(null);
                URL::forceScheme(null);
            }
        }

        return URL::temporarySignedRoute(
            'whatsapp.login.show',
            $expiresAt,
            $params
        );
    }

    public function resolvePhoneByToken(string $token): ?string
    {
        return Cache::get($this->cacheKey($token));
    }

    public function forgetToken(string $token): void
    {
        Cache::forget($this->cacheKey($token));
    }

    private function cacheKey(string $token): string
    {
        return "login:token:{$token}";
    }
}
