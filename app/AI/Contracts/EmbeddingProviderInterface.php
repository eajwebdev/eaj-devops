<?php

namespace App\AI\Contracts;

interface EmbeddingProviderInterface
{
    /** @return list<float> */
    public function embed(string $text): array;
}
