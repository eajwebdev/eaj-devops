<?php

namespace App\AI\Data;

use Closure;

final readonly class ProviderRequest
{
    /**
     * @param  array<string, mixed>  $schema
     * @param  Closure(): void|null  $heartbeat  called while waiting on the provider (about once a second) so a long call is not mistaken for a stall
     */
    public function __construct(
        public string $model,
        public string $system,
        public string $input,
        public array $schema,
        public string $schemaName,
        public int $maxOutputTokens,
        public string $idempotencyKey,
        public ?Closure $heartbeat = null,
    ) {}

    public function withInput(string $input): self
    {
        return new self($this->model, $this->system, $input, $this->schema, $this->schemaName, $this->maxOutputTokens, $this->idempotencyKey, $this->heartbeat);
    }

    public function forModel(string $model, string $idempotencyKey, ?Closure $heartbeat): self
    {
        return new self($model, $this->system, $this->input, $this->schema, $this->schemaName, $this->maxOutputTokens, $idempotencyKey, $heartbeat);
    }

    /** Guzzle request options shared by the HTTP providers. */
    public function httpOptions(): array
    {
        return $this->heartbeat ? ['progress' => fn () => ($this->heartbeat)()] : [];
    }
}
