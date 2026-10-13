<?php

namespace App\AI\Contracts;

interface RepositoryProviderInterface
{
    public function read(string $path): string;
}
