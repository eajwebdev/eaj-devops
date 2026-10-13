<?php

return [
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 90),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 4000),
    'max_retry_after_seconds' => (int) env('AI_MAX_RETRY_AFTER_SECONDS', 60),
    'providers' => [
        'openai' => ['label' => 'OpenAI', 'key' => env('OPENAI_API_KEY'), 'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com')],
        'anthropic' => ['label' => 'Anthropic', 'key' => env('ANTHROPIC_API_KEY'), 'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'), 'version' => env('ANTHROPIC_VERSION', '2023-06-01')],
    ],
    'circuit_breaker' => ['consecutive_failures' => 5, 'window_seconds' => 120, 'minimum_samples' => 5, 'error_percentage' => 50, 'open_seconds' => 60],
];
