<?php

namespace App\AI;

use App\Models\Agent;
use App\Models\PromptVersion;

class PromptBuilder
{
    /** @param array<string, mixed> $context @return array{system: string, input: string} */
    public function build(PromptVersion $prompt, Agent $agent, array $context): array
    {
        return [
            'system' => $prompt->system_prompt."\nYour role key is {$agent->role->key}. Return only an object matching the required schema.",
            'input' => json_encode($context, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ];
    }
}
