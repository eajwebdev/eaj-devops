<?php

namespace App\AI\Data;

final readonly class ProviderResponse
{
    /** @param array<string, mixed> $output */
    public function __construct(
        public array $output,
        public int $inputTokens = 0,
        public int $cachedInputTokens = 0,
        public int $outputTokens = 0,
        public ?string $requestId = null,
    ) {}
}
