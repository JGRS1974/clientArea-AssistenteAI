<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;

class TtsAudioController extends Controller
{
    public function stream(string $token)
    {
        $cacheKey = 'tts_audio_' . $token;
        $encoded = Cache::get($cacheKey);

        if (!$encoded) {
            abort(404);
        }

        $binary = base64_decode($encoded, true);
        if ($binary === false) {
            abort(404);
        }

        return response($binary, 200, [
            'Content-Type' => 'audio/ogg',
            'Cache-Control' => 'no-store',
        ]);
    }
}

