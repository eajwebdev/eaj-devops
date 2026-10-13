<?php

namespace App\Enums;

enum ToolRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Passed = 'passed';
    case Failed = 'failed';
    // Failed, then passed when re-run on its own (§48.8): reported and still blocking until a person accepts it.
    case Flaky = 'flaky';
    // The tool could not run properly (missing binary, crash, time limit).
    case Error = 'error';
    case Skipped = 'skipped';
    // The runner refused it under its local safety rules (§85, §15).
    case Blocked = 'blocked';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return ! in_array($this, [self::Queued, self::Running], true);
    }

    /** Only real, parsed success counts; a skip passes because there was nothing for the tool to check. */
    public function passesGate(): bool
    {
        return in_array($this, [self::Passed, self::Skipped], true);
    }
}
