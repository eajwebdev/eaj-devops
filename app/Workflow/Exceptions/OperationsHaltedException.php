<?php

namespace App\Workflow\Exceptions;

use RuntimeException;

class OperationsHaltedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('AI operations are stopped. Resume operations before starting new work.');
    }
}
