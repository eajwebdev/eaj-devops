<?php

namespace App\Workflow\Exceptions;

/**
 * A hard limit (fix cycles, review cycles) was reached: the workflow blocks
 * with LIMIT_REACHED and asks the owner instead of continuing (§48.1, G5).
 */
class StepLimitReachedException extends NonRetryableStepException {}
