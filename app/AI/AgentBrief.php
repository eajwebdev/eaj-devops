<?php

namespace App\AI;

use App\Enums\AgentStatus;
use Closure;

/**
 * What one agent run is asked to do: which prompt, with which context, and the
 * deterministic checks around its answer (§48.7, §48.8).
 */
final readonly class AgentBrief
{
    /**
     * @param  array<string, mixed>  $context  already redacted by whoever built it; empty = the planning context
     * @param  Closure(array<string, mixed>): list<string>|null  $check  semantic problems with a schema-valid answer
     *                                                                   (e.g. edits that do not apply); they get one correction round
     * @param  Closure(array<string, mixed>): array<string, mixed>|null  $guard  deterministic clean-up of the accepted answer
     */
    public function __construct(
        public string $purpose,
        public string $promptKey,
        public array $context,
        public AgentStatus $activity,
        public string $message,
        public ?Closure $check = null,
        public ?Closure $guard = null,
        public ?int $maxOutputTokens = null,
    ) {}
}
