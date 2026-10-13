<?php

namespace App\AI\Exceptions;

use RuntimeException;

class InvalidStructuredOutputException extends RuntimeException
{
    /** @param list<string> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The provider returned invalid structured output after one repair attempt: '.implode('; ', $errors));
    }
}
