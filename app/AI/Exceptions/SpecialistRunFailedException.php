<?php

namespace App\AI\Exceptions;

use RuntimeException;
use Throwable;

/** A specialist run ended without a usable result; `retryable` mirrors whether the run is queued for another attempt. */
class SpecialistRunFailedException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $retryable, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
