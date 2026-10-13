<?php

namespace App\Tools\Exceptions;

use DomainException;

/** The project cannot run this tool right now; the message says why and what to do. */
class ToolUnavailableException extends DomainException {}
