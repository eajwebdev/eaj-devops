<?php

namespace App\AI\Contracts;

interface ExecutionSandboxInterface
{
    /** @param list<string> $arguments */
    public function execute(string $command, array $arguments = []): mixed;
}
