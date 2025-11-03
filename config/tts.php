<?php

return [
    'model' => env('OPENAI_TTS_MODEL', 'gpt-4o-mini-tts'),
    'voice' => env('OPENAI_TTS_VOICE', 'alloy'),
    'format' => env('OPENAI_TTS_FORMAT', 'ogg'),
    'max_words' => env('OPENAI_TTS_MAX_WORDS', 160),
    'token_ttl' => env('OPENAI_TTS_TOKEN_TTL', 600),
];

