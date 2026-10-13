<?php

namespace App\Runner\Exceptions;

use RuntimeException;

class InvalidPairingCodeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This pairing code is invalid, already used or expired. Create a new one in the Studio.');
    }
}
