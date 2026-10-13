<?php

namespace App\Tasks;

use RuntimeException;

/**
 * A task operation that cannot happen as asked. `conflict` means the task
 * changed since the caller last saw it (HTTP 409); otherwise it is a rule (422).
 */
class TaskException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $conflict = false)
    {
        parent::__construct($message);
    }

    public static function conflict(string $message): self
    {
        return new self($message, true);
    }
}
