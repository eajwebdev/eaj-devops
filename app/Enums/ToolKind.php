<?php

namespace App\Enums;

/**
 * Engineering tool kinds, in the order the quality gates run them (Plan §33):
 * dependencies → formatter → static analysis → tests → build → end-to-end.
 */
enum ToolKind: string
{
    case Install = 'install';
    case Format = 'format';
    case Static = 'static';
    case Test = 'test';
    case Build = 'build';
    case E2e = 'e2e';

    public function label(): string
    {
        return match ($this) {
            self::Install => 'Dependencies',
            self::Format => 'Formatter',
            self::Static => 'Static analysis',
            self::Test => 'Tests',
            self::Build => 'Build',
            self::E2e => 'End-to-end tests',
        };
    }

    public function order(): int
    {
        return array_search($this, self::cases(), true);
    }

    /** Installing dependencies prepares the workspace; it is not a quality gate itself. */
    public function isGate(): bool
    {
        return $this !== self::Install;
    }

    /** Browser tests need a running app and installed browsers, so the owner switches them on (§24). */
    public function enabledByDefault(): bool
    {
        return $this !== self::E2e;
    }
}
