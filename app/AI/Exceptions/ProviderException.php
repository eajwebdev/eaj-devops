<?php

namespace App\AI\Exceptions;

use RuntimeException;
use Throwable;

class ProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $provider,
        public readonly string $errorCode = 'provider_error',
        public readonly bool $retryable = false,
        public readonly ?int $httpStatus = null,
        public readonly int $retryAfter = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
