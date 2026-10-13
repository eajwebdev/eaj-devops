import { StatusPill } from '@/components/status-pill';
import { relativeTime, type Tone } from '@/lib/events';
import { type RunnerCommand, type Workspace } from '@/types';
import { Ban, CheckCircle2, CircleDashed, Clock, FolderGit2, LoaderCircle, XCircle, type LucideIcon } from 'lucide-react';

const commandStatus: Record<RunnerCommand['status'], { tone: Tone; icon: LucideIcon; label: string }> = {
    queued: { tone: 'info', icon: CircleDashed, label: 'Queued' },
    claimed: { tone: 'brand', icon: LoaderCircle, label: 'Running' },
    succeeded: { tone: 'success', icon: CheckCircle2, label: 'Done' },
    failed: { tone: 'danger', icon: XCircle, label: 'Failed' },
    expired: { tone: 'neutral', icon: Clock, label: 'Expired' },
    cancelled: { tone: 'neutral', icon: Ban, label: 'Cancelled' },
};

/** What the runner was asked to do for this project, and what happened. */
export function RunnerCommandList({ commands }: { commands: RunnerCommand[] }) {
    if (commands.length === 0) {
        return <p className="text-muted-foreground text-sm">Nothing has been sent to a runner for this project yet.</p>;
    }

    return (
        <ul className="divide-border divide-y">
            {commands.map((c) => {
                const s = commandStatus[c.status];

                return (
                    <li key={c.id} className="py-2 first:pt-0 last:pb-0">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-heading text-sm">{c.label}</span>
                            <StatusPill tone={s.tone} icon={s.icon} label={s.label} />
                        </div>
                        <p className="text-muted-foreground text-xs">
                            {c.created_at && relativeTime(c.created_at)}
                            {c.status === 'claimed' && c.progress && ` · ${c.progress}`}
                        </p>
                        {c.error && <p className="text-danger mt-0.5 text-xs break-words">{c.error}</p>}
                    </li>
                );
            })}
        </ul>
    );
}

const workspaceStatus: Record<Workspace['status'], { tone: Tone; icon: LucideIcon; label: string }> = {
    creating: { tone: 'brand', icon: LoaderCircle, label: 'Creating' },
    ready: { tone: 'success', icon: CheckCircle2, label: 'Ready' },
    removing: { tone: 'info', icon: LoaderCircle, label: 'Removing' },
    removed: { tone: 'neutral', icon: Ban, label: 'Removed' },
    failed: { tone: 'danger', icon: XCircle, label: 'Failed' },
};

/** Task worktrees (Plan §13). Created by tasks — the Task Engine arrives in Phase 3. */
export function WorkspaceList({ workspaces }: { workspaces: Workspace[] }) {
    if (workspaces.length === 0) {
        return (
            <p className="text-muted-foreground text-sm">
                No workspaces. Each task gets its own Git worktree and <code className="font-mono text-xs">ai/&lt;task&gt;</code> branch once the Task
                Engine arrives (Phase 3); the original folder is never edited.
            </p>
        );
    }

    return (
        <ul className="divide-border divide-y">
            {workspaces.map((w) => {
                const s = workspaceStatus[w.status];

                return (
                    <li key={w.id} className="py-2 first:pt-0 last:pb-0">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-heading flex items-center gap-1.5 font-mono text-xs">
                                <FolderGit2 className="text-muted-foreground size-3.5" />
                                {w.branch}
                            </span>
                            <StatusPill tone={s.tone} icon={s.icon} label={s.label} />
                        </div>
                        {w.path && <p className="text-muted-foreground truncate font-mono text-[0.6875rem]">{w.path}</p>}
                        {w.error && <p className="text-danger text-xs">{w.error}</p>}
                    </li>
                );
            })}
        </ul>
    );
}
