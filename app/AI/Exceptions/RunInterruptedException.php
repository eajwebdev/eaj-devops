<?php

namespace App\AI\Exceptions;

use RuntimeException;

/**
 * The run may no longer call a provider: operations were stopped (§57) or the
 * run was paused, cancelled or finished elsewhere. Nothing is sent or billed.
 */
class RunInterruptedException extends RuntimeException {}
