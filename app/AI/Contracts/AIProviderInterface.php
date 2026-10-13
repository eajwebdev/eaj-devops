<?php

namespace App\AI\Contracts;

use App\AI\Data\ProviderRequest;
use App\AI\Data\ProviderResponse;

interface AIProviderInterface
{
    public function key(): string;

    public function generate(ProviderRequest $request): ProviderResponse;
}
