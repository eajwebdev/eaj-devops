<?php

namespace App\Enums;

/**
 * Instructions the Studio can send to an EAJ Local Runner. The runner decides
 * locally whether each is allowed (folders, Git policy, approved tools); there
 * is no generic "run this command" instruction.
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
    // An approved project tool, by key — the runner builds the command line itself (§84).
    case ToolRun = 'tool.run';
    // Agent file access inside a task workspace (§48.8): redacted reads, hash-checked all-or-nothing writes.
    case WorkspaceRead = 'workspace.read';
    case WorkspaceApply = 'workspace.apply';
    case WorkspaceDiff = 'workspace.diff';
    // Publish only the isolated ai/* task branch. Credentials remain on the developer machine.
    case WorkspacePush = 'workspace.push';

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
            self::ToolRun => 'Engineering tool',
            self::WorkspaceRead => 'Read files',
            self::WorkspaceApply => 'Apply changes',
            self::WorkspaceDiff => 'Collect diff',
            self::WorkspacePush => 'Publish task branch',
        };
    }

    /**
     * How long the runner may hold the command before it is offered again. Tool
     * runs last up to 30 minutes, but the runner reports progress at least every
     * 30 s and each report extends the lease.
     */
    public function leaseSeconds(): int
    {
        return match ($this) {
            self::ProjectIndex, self::GitInit, self::WorkspaceCreate, self::WorkspacePush => 20 * 60,
            default => 5 * 60,
        };
    }
}
