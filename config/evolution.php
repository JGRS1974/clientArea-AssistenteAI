<?php

return [
    // Base URL da Evolution API (ex.: https://your-evolution-host/api)
    'base_url' => env('EVOLUTION_BASE_URL', ''),

    // ID da instância configurada na Evolution (usado nas rotas de envio)
    'instance' => env('EVOLUTION_INSTANCE', ''),

    // Token de autenticação (Bearer) para a Evolution API
    'api_key' => env('EVOLUTION_API_KEY', ''),

    // Habilita o envio de áudio (PTT) com base no texto retornado pelo assistente
    'send_audio_on_demand' => env('EVOLUTION_SEND_AUDIO_ON_DEMAND', false),

    // Permite processar mensagens marcadas como fromMe (útil para testes)
    'accept_from_me' => filter_var(env('EVOLUTION_ACCEPT_FROM_ME', false), FILTER_VALIDATE_BOOLEAN),
];
