<?php

return [
    'follow_up_variant' => env('ASSISTANT_FOLLOW_UP_VARIANT', 'default'),
    'line_break' => '<br>',
    'tone_agent' => [
        'enabled' => (bool) env('ASSISTANT_TONE_AGENT_ENABLED', false),
        'model' => env('ASSISTANT_TONE_AGENT_MODEL', 'gpt-4.1-mini'),
        'max_steps' => (int) env('ASSISTANT_TONE_AGENT_MAX_STEPS', 1),
    ],
];
