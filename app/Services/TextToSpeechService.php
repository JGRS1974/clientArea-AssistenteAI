<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class TextToSpeechService
{
    public function __construct(private ?string $apiKey = null)
    {
        $this->apiKey = $apiKey ?? env('OPENAI_API_KEY');
    }

    public function synthesize(string $text, string $conversationId, array $context = []): ?array
    {
        $apiKey = $this->apiKey;
        if (!$apiKey) {
            Log::warning('TextToSpeechService: OPENAI_API_KEY ausente');
            return null;
        }

        $prepared = $this->prepareText($text, $context);
        if ($prepared === '') {
            return null;
        }

        $model = config('tts.model', 'gpt-4o-mini-tts');
        $voice = config('tts.voice', 'alloy');
        $format = config('tts.format', 'ogg');

        try {
            $response = Http::withToken($apiKey)
                ->accept('audio/' . $format)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model' => $model,
                    'input' => $prepared,
                    'voice' => $voice,
                    'format' => $format,
                ]);

            if (!$response->successful()) {
                Log::warning('TextToSpeechService falhou', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return null;
            }

            $binary = $response->body();
            if ($binary === '') {
                return null;
            }

            $token = Str::random(40);
            $ttl = now()->addSeconds((int) config('tts.token_ttl', 600));

            Cache::put($this->cacheKey($token), base64_encode($binary), $ttl);

            return [
                'token' => $token,
                'url' => url('/api/audio/tts/' . $token),
                'bytes' => strlen($binary),
            ];
        } catch (\Throwable $exception) {
            Log::error('TextToSpeechService exception', [
                'error' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function prepareText(string $text, array $context = []): string
    {
        $normalized = str_ireplace(['<br>', '<br/>', '<br />'], "\n", $text);
        $normalized = strip_tags($normalized);
        $normalized = html_entity_decode($normalized, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $normalized = preg_replace('/\s+/', ' ', $normalized ?? '');
        $normalized = trim($normalized ?? '');

        $maxWords = (int) config('tts.max_words', 160);
        if ($maxWords > 0) {
            $normalized = Str::words($normalized, $maxWords, '');
        }

        return trim($normalized);
    }

    private function cacheKey(string $token): string
    {
        return 'tts_audio_' . $token;
    }
}

