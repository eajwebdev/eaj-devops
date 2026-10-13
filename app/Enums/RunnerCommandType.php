<?php

namespace App\Enums;

/**
 * Instructions the Studio can send to an EAJ Local Runner. The runner decides
 * locally whether each is allowed (folders, Git policy); there is no generic
 * "run this command" instruction.
 */
enum RunnerCommandType: string
{
    case ProjectScan = 'project.scan';
    case ProjectIndex = 'project.index';
    case GitInit = 'git.init';
    case WorkspaceCreate = 'workspace.create';
    case WorkspaceCheckpoint = 'workspace.checkpoint';
    case WorkspaceReset = 'workspace.reset';
    case WorkspaceRemove = 'workspace.remove';

    public function label(): string
    {
        return match ($this) {
            self::ProjectScan => 'Project scan',
            self::ProjectIndex => 'Code index',
            self::GitInit => 'Git initialization',
            self::WorkspaceCreate => 'Create workspace',
            self::WorkspaceCheckpoint => 'Checkpoint',
            self::WorkspaceReset => 'Reset to checkpoint',
            self::WorkspaceRemove => 'Remove workspace',
        };
    }

    /** How long the runner may hold the command before it is offered again. */
    public function leaseSeconds(): int
    {
        return match ($this) {
            self::ProjectIndex, self::GitInit, self::WorkspaceCreate => 20 * 60,
            default => 5 * 60,
        };
    }
}
