import { RunnerStatusPill } from '@/components/runner-status-pill';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { relativeTime } from '@/lib/events';
import { type LocalRunner, type Project } from '@/types';
import { Link, useForm } from '@inertiajs/react';
import { FolderLock, GitBranch, LoaderCircle, RefreshCcw, ShieldCheck } from 'lucide-react';

interface ConnectionPanelProps {
    project: Project;
    runner: LocalRunner | null;
    runners: LocalRunner[];
    busy: boolean;
    canUpdate: boolean;
}

export function ConnectionPanel({ project, runner, runners, busy, canUpdate }: ConnectionPanelProps) {
    const connection = project.connection;

    if (!connection) {
        return <p className="text-muted-foreground text-sm">This project has no connection.</p>;
    }

    const git = connection.git_state;

    return (
        <div className="space-y-4 text-sm">
            <div>
                <p className="text-muted-foreground text-xs">Folder (read-only to agents)</p>
                <p className="text-heading flex items-start gap-1.5 font-mono text-xs break-all">
                    <FolderLock className="text-muted-foreground mt-px size-3.5 shrink-0" />
                    {connection.local_path}
                </p>
            </div>

            <div>
                <p className="text-muted-foreground mb-1 text-xs">Runner</p>
                {runner ? (
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-heading">{runner.name}</span>
                        <RunnerStatusPill status={runner.status} />
                    </div>
                ) : (
                    <p className="text-muted-foreground text-xs">None assigned.</p>
                )}
                {canUpdate && <AssignRunner project={project} runners={runners} current={runner} />}
            </div>

            <div>
                <p className="text-muted-foreground text-xs">Git</p>
                {git ? (
                    git.enabled ? (
                        <p className="text-heading flex items-center gap-1.5">
                            <GitBranch className="text-muted-foreground size-3.5" />
                            {git.branch ?? 'detached'}
                            {git.head && <span className="text-muted-foreground font-mono text-xs">{git.head.slice(0, 7)}</span>}
                            {git.modified + git.untracked > 0 && (
                                <span className="text-muted-foreground text-xs">· {git.modified + git.untracked} uncommitted</span>
                            )}
                        </p>
                    ) : (
                        <p className="text-warning">Not a Git repository</p>
                    )
                ) : (
                    <p className="text-muted-foreground text-xs">Unknown until the first scan.</p>
                )}
                {git && !git.enabled && canUpdate && runner && <InitializeGit project={project} disabled={busy} />}
            </div>

            {connection.last_scanned_at && <p className="text-muted-foreground text-xs">Last scanned {relativeTime(connection.last_scanned_at)}</p>}

            {canUpdate && runner && (
                <div className="flex flex-wrap gap-2 pt-1">
                    <ActionButton href={route('projects.scan', project.slug)} disabled={busy} label="Rescan" />
                    {connection.detected_stack && (
                        <ActionButton href={route('projects.index.rebuild', project.slug)} disabled={busy} label="Rebuild index" />
                    )}
                </div>
            )}
        </div>
    );
}

function ActionButton({ href, disabled, label }: { href: string; disabled: boolean; label: string }) {
    const { post, processing } = useForm({});

    return (
        <Button size="sm" variant="outline" disabled={disabled || processing} onClick={() => post(href, { preserveScroll: true })}>
            {processing ? <LoaderCircle className="animate-spin" /> : <RefreshCcw />}
            {label}
        </Button>
    );
}

function AssignRunner({ project, runners, current }: { project: Project; runners: LocalRunner[]; current: LocalRunner | null }) {
    const { data, setData, put, processing, errors } = useForm<{ local_runner_id: number | null }>({
        local_runner_id: current?.id ?? runners[0]?.id ?? null,
    });

    if (runners.length === 0) {
        return (
            <p className="text-muted-foreground mt-1 text-xs">
                <Link href={route('runners.index')} className="text-brand-text hover:underline">
                    Pair a runner
                </Link>{' '}
                on the PC where this folder lives.
            </p>
        );
    }

    if (current && runners.length === 1) {
        return null;
    }

    return (
        <form
            className="mt-2 flex items-center gap-2"
            onSubmit={(e) => {
                e.preventDefault();
                put(route('projects.runner.assign', project.slug), { preserveScroll: true });
            }}
        >
            <select
                aria-label="Runner"
                value={data.local_runner_id ?? ''}
                onChange={(e) => setData('local_runner_id', Number(e.target.value))}
                className="border-input bg-background h-8 min-w-0 flex-1 rounded-md border px-2 text-xs"
            >
                {runners.map((r) => (
                    <option key={r.id} value={r.id}>
                        {r.name} — {r.status_label}
                    </option>
                ))}
            </select>
            <Button size="sm" variant="outline" disabled={processing || data.local_runner_id === current?.id}>
                {current ? 'Switch' : 'Assign'}
            </Button>
            {errors.local_runner_id && <p className="text-danger text-xs">{errors.local_runner_id}</p>}
        </form>
    );
}

/**
 * Plan §14: explain why version control is needed, then initialize only on
 * explicit approval. Existing files are never overwritten.
 */
function InitializeGit({ project, disabled }: { project: Project; disabled: boolean }) {
    const { post, processing, setData, data } = useForm<{ confirm: boolean }>({ confirm: false });

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button size="sm" className="mt-2" disabled={disabled}>
                    <GitBranch />
                    Initialize Git…
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Initialize Git for {project.name}?</DialogTitle>
                    <DialogDescription>
                        Safe AI changes need version control: every task works on its own branch in an isolated worktree, and nothing reaches your
                        code without tests, review and your approval.
                    </DialogDescription>
                </DialogHeader>
                <ul className="text-muted-foreground space-y-1.5 text-sm">
                    <li className="flex gap-2">
                        <ShieldCheck className="text-success mt-0.5 size-4 shrink-0" /> Creates a <code className="font-mono">.gitignore</code> only
                        if there isn't one — existing files are never overwritten.
                    </li>
                    <li className="flex gap-2">
                        <ShieldCheck className="text-success mt-0.5 size-4 shrink-0" /> <code className="font-mono">.env</code>, keys and credentials
                        are never committed, whatever the existing .gitignore says.
                    </li>
                    <li className="flex gap-2">
                        <ShieldCheck className="text-success mt-0.5 size-4 shrink-0" /> Creates one baseline commit on{' '}
                        <code className="font-mono">main</code> so every later change can be reviewed and reverted.
                    </li>
                </ul>
                <label className="flex items-center gap-2 text-sm">
                    <input
                        type="checkbox"
                        className="accent-brand-crimson size-4"
                        checked={data.confirm}
                        onChange={(e) => setData('confirm', e.target.checked)}
                    />
                    I approve initializing Git in {project.connection?.local_path}
                </label>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="ghost">Cancel</Button>
                    </DialogClose>
                    <Button
                        disabled={!data.confirm || processing}
                        onClick={() => post(route('projects.git-init', project.slug), { preserveScroll: true })}
                    >
                        {processing && <LoaderCircle className="animate-spin" />}
                        Initialize Git
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
