<?php

namespace App\Workflow\Exceptions;

use RuntimeException;

/**
 * A step failure that retrying cannot fix (e.g. missing handler, invalid
 * configuration). The workflow moves straight to BLOCKED.
 */
class NonRetryableStepException extends RuntimeException {}
