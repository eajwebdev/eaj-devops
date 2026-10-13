<?php

namespace App\AI;

use App\AI\Contracts\AIProviderInterface;
use App\AI\Exceptions\ProviderException;
use App\AI\Providers\AnthropicProvider;
use App\AI\Providers\FakeAIProvider;
use App\AI\Providers\OpenAIProvider;

class ProviderRegistry
{
    public function __construct(
        private readonly OpenAIProvider $openAI,
        private readonly AnthropicProvider $anthropic,
        private readonly FakeAIProvider $fake,
    ) {}

    public function get(string $key): AIProviderInterface
    {
        return match (strtolower($key)) {
            'openai' => $this->openAI,
            'anthropic' => $this->anthropic,
            'fake' => $this->fake,
            default => throw new ProviderException("Unknown AI provider: {$key}.", $key, 'unknown_provider'),
        };
    }

    public function configured(string $key): bool
    {
        return $key === 'fake' ? app()->environment('testing') : filled(config("ai.providers.{$key}.key"));
    }
}
