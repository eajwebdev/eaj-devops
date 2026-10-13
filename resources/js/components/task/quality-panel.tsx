import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { relativeTime, type Tone } from '@/lib/events';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Clock3,
    GitCommitHorizontal,
    LoaderCircle,
    MinusCircle,
    PauseCircle,
    Play,
    ShieldAlert,
    ShieldCheck,
    Square,
    XCircle,
    type LucideIcon,
    type LucideProps,
} from 'lucide-react';
import { forwardRef, useState } from 'react';

/** Spins only while a tool really runs on the PC; tagged so reduced-motion and hidden-tab rules apply (§7.6). */
const Working = forwardRef<SVGSVGElement, LucideProps>(({ className, ...props }, ref) => (
    <LoaderCircle ref={ref} {...props} className={cn(className, 'animate-spin')} data-motion="loop" />
));
Working.displayName = 'Working';

type ToolStatus = 'queued' | 'running' | 'passed' | 'failed' | 'flaky' | 'error' | 'skipped' | 'blocked' | 'cancelled';
type StepStatus = 'PENDING' | 'RUNNING' | 'COMPLETED' | 'FAILED' | 'CANCELLED';
export type GateStatus = 'passed' | 'failed' | 'not_run' | 'not_configured';

export interface ToolRunView {
    id: number;
    tool_key: string;
    kind: string;
    label: string;
    command: string | null;
    mode: 'check' | 'fix';
    status: ToolStatus;
    summary: string | null;
    exit_code: number | null;
    duration_ms: number | null;
    head_sha: string | null;
    progress: string | null;
    error: string | null;
    started_at: string | null;
    finished_at: string | null;
    tests: { total: number; passed: number; failed: number; skipped: number } | null;
    failures: { name: string; file: string | null; message: string; flaky: boolean }[];
    issues: {
        file: string | null;
        line: number | null;
        column: number | null;
        severity: 'error' | 'warning';
        message: string;
        rule: string | null;
    }[];
    files_changed: string[];
    flaky: string[];
    database: { connection: string; database: string; source: string } | null;
    notes: string[];
    output_tail: string | null;
}

export interface Quality {
    tools: { runnable: number; total: number };
    workflow: {
        id: number;
        status: 'PENDING' | 'RUNNING' | 'PAUSED' | 'BLOCKED' | 'COMPLETED' | 'FAILED' | 'CANCELLED';
        blocked_reason: string | null;
        started_at: string | null;
        finished_at: string | null;
    } | null;
    report: {
        passed: boolean;
        head_sha: string | null;
        consistent: boolean;
        note: string | null;
        gates: { kind: string; label: string; status: GateStatus }[];
    } | null;
    steps: {
        id: number;
        name: 'tool.run' | 'workspace.checkpoint' | string;
        status: StepStatus;
        label: string;
        kind: string | null;
        mode: 'check' | 'fix' | null;
        attempt: number;
        error: string | null;
        reason: string | null;
        checkpoint: { sha: string | null; changed: boolean; files: number } | null;
        run: ToolRunView | null;
    }[];
}

const TOOL: Record<ToolStatus, { tone: Tone; icon: LucideIcon; label: string }> = {
    queued: { tone: 'neutral', icon: Clock3, label: 'Waiting for runner' },
    running: { tone: 'brand', icon: Working, label: 'Running' },
    passed: { tone: 'success', icon: CheckCircle2, label: 'Passed' },
    failed: { tone: 'danger', icon: XCircle, label: 'Failed' },
    flaky: { tone: 'warning', icon: AlertTriangle, label: 'Flaky' },
    error: { tone: 'danger', icon: XCircle, label: 'Could not run' },
    skipped: { tone: 'neutral', icon: MinusCircle, label: 'Nothing to check' },
    blocked: { tone: 'warning', icon: ShieldAlert, label: 'Blocked' },
    cancelled: { tone: 'neutral', icon: Ban, label: 'Stopped' },
};

const STEP: Record<StepStatus, { tone: Tone; icon: LucideIcon; label: string }> = {
    PENDING: { tone: 'neutral', icon: Clock3, label: 'Waiting' },
    RUNNING: { tone: 'brand', icon: Working, label: 'Running' },
    COMPLETED: { tone: 'success', icon: CheckCircle2, label: 'Done' },
    FAILED: { tone: 'danger', icon: XCircle, label: 'Failed' },
    CANCELLED: { tone: 'neutral', icon: Ban, label: 'Cancelled' },
};

const COMMITTED = { tone: 'success' as Tone, icon: GitCommitHorizontal, label: 'Committed' };

export const GATE: Record<GateStatus, { icon: LucideIcon; className: string; label: string }> = {
    passed: { icon: CheckCircle2, className: 'text-success', label: 'passed' },
    failed: { icon: XCircle, className: 'text-danger', label: 'failed' },
    not_run: { icon: MinusCircle, className: 'text-warning', label: 'could not run' },
    not_configured: { icon: MinusCircle, className: 'text-muted-foreground', label: 'not configured' },
};

const WORKFLOW: Record<NonNullable<Quality['workflow']>['status'], { tone: Tone; icon: LucideIcon; label: string }> = {
    PENDING: { tone: 'neutral', icon: Clock3, label: 'Starting' },
    RUNNING: { tone: 'brand', icon: Working, label: 'Checking' },
    PAUSED: { tone: 'warning', icon: PauseCircle, label: 'Paused' },
    BLOCKED: { tone: 'danger', icon: AlertTriangle, label: 'Stopped — needs your decision' },
    COMPLETED: { tone: 'success', icon: CheckCircle2, label: 'Finished' },
    FAILED: { tone: 'danger', icon: XCircle, label: 'Failed' },
    CANCELLED: { tone: 'neutral', icon: Ban, label: 'Cancelled' },
};

const ACTIVE = new Set(['PENDING', 'RUNNING', 'PAUSED', 'BLOCKED']);

/**
 * Quality gates on the task workspace (Phase 7, §33): what each tool really
 * reported, parsed on the PC. The verdict is the tools', never the AI's (§48.8).
 */
export function QualityPanel({
    quality,
    projectSlug,
    taskNumber,
    canRun,
    blocker,
}: {
    quality: Quality;
    projectSlug: string;
    taskNumber: number;
    canRun: boolean;
    blocker: string | null;
}) {
    const [busy, setBusy] = useState(false);
    const active = quality.workflow !== null && ACTIVE.has(quality.workflow.status);

    const post = (name: 'tasks.quality' | 'tasks.quality.cancel') => {
        setBusy(true);
        router.post(route(name, [projectSlug, taskNumber]), {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2 text-xs">
                    {quality.report && !active ? (
                        <StatusPill
                            tone={quality.report.passed ? 'success' : 'danger'}
                            icon={quality.report.passed ? ShieldCheck : ShieldAlert}
                            label={quality.report.passed ? 'Quality gates passed' : 'Quality gates failed'}
                        />
                    ) : (
                        quality.workflow && <StatusPill {...WORKFLOW[quality.workflow.status]} />
                    )}
                    {quality.report?.head_sha && !active && (
                        <span className="text-muted-foreground inline-flex items-center gap-1 font-mono">
                            <GitCommitHorizontal className="size-3.5" aria-hidden="true" />
                            {quality.report.head_sha.slice(0, 10)}
                        </span>
                    )}
                    {quality.workflow?.started_at && (
                        <span className="text-muted-foreground">
                            started {relativeTime(quality.workflow.started_at)}
                            {quality.workflow.finished_at && ` · finished ${relativeTime(quality.workflow.finished_at)}`}
                        </span>
                    )}
                </div>

                {canRun &&
                    (active ? (
                        <Button size="sm" variant="outline" disabled={busy} onClick={() => post('tasks.quality.cancel')}>
                            <Square />
                            Cancel checks
                        </Button>
                    ) : (
                        <Button size="sm" disabled={busy || blocker !== null} onClick={() => post('tasks.quality')} title={blocker ?? undefined}>
                            <Play />
                            {quality.workflow ? 'Run checks again' : 'Run quality checks'}
                        </Button>
                    ))}
            </div>

            {!quality.workflow && (
                <p className="text-muted-foreground text-sm">
                    {blocker ??
                        `Runs the project's own formatter, static analysis, tests and build in the task workspace (${quality.tools.runnable} of ${quality.tools.total} tools allowed). Results come from the tools' output, not from the AI.`}
                </p>
            )}

            {quality.report && !active && (
                <ul className="flex flex-wrap gap-x-4 gap-y-1.5 text-xs" aria-label="Quality gates">
                    {quality.report.gates.map((gate) => {
                        const g = GATE[gate.status];

                        return (
                            <li
                                key={gate.kind}
                                className={cn('inline-flex items-center gap-1', gate.status === 'not_configured' && 'text-muted-foreground')}
                            >
                                <g.icon className={cn('size-3.5', g.className)} aria-hidden="true" />
                                <span className="text-heading">{gate.label}</span>
                                <span className="text-muted-foreground">{g.label}</span>
                            </li>
                        );
                    })}
                </ul>
            )}

            {quality.report?.note && <p className="border-warning/30 bg-warning/10 rounded-md border px-3 py-2 text-xs">{quality.report.note}</p>}

            {quality.steps.length > 0 && (
                <ol className="divide-border divide-y rounded-md border">
                    {quality.steps.map((step) => (
                        <QualityStep key={step.id} step={step} />
                    ))}
                </ol>
            )}
        </div>
    );
}

export function QualityStep({ step }: { step: Quality['steps'][number] }) {
    const run = step.run;
    const checkpoint = step.checkpoint;
    const pill = run ? TOOL[run.status] : checkpoint ? COMMITTED : step.status === 'COMPLETED' && step.reason ? TOOL.skipped : STEP[step.status];
    const committed = checkpoint ? (checkpoint.changed ? `${checkpoint.files} file(s) committed` : 'No new changes') : null;
    const detail = run?.progress ?? run?.summary ?? run?.error ?? run?.notes[0] ?? step.reason ?? committed ?? step.error;
    const expandable =
        run && (run.failures.length > 0 || run.issues.length > 0 || run.files_changed.length > 0 || run.notes.length > 0 || run.output_tail);

    const header = (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
            <StatusPill {...pill} className="shrink-0" />
            <span className="text-heading text-sm font-medium">{step.label}</span>
            {step.mode === 'fix' && <span className="text-muted-foreground text-xs">fix mode</span>}
            {checkpoint?.sha && <span className="text-muted-foreground font-mono text-xs">{checkpoint.sha.slice(0, 10)}</span>}
            {run?.duration_ms != null && run.status !== 'running' && run.status !== 'skipped' && (
                <span className="text-muted-foreground tabular text-xs">{formatDuration(run.duration_ms)}</span>
            )}
            {step.attempt > 1 && <span className="text-warning text-xs">attempt {step.attempt}</span>}
            {detail && <span className="text-muted-foreground w-full truncate text-xs sm:w-auto sm:flex-1">{detail}</span>}
        </div>
    );

    if (!expandable) {
        return <li className="px-3 py-2.5">{header}</li>;
    }

    return (
        <li>
            <details className="group">
                <summary className="hover:bg-muted/50 cursor-pointer list-none px-3 py-2.5 [&::-webkit-details-marker]:hidden">{header}</summary>
                <div className="space-y-3 px-3 pb-3 text-xs">
                    {run.command && <p className="text-muted-foreground font-mono break-all">$ {run.command}</p>}

                    {run.database && (
                        <p className="text-muted-foreground">
                            Test database: <span className="text-heading">{run.database.connection}</span> · {run.database.database} (
                            {run.database.source})
                        </p>
                    )}

                    {run.failures.length > 0 && (
                        <ul className="space-y-2">
                            {run.failures.map((f, i) => (
                                <li key={i}>
                                    <p className="text-heading flex items-center gap-1.5 font-medium">
                                        {f.flaky ? (
                                            <AlertTriangle className="text-warning size-3.5 shrink-0" aria-label="Flaky" />
                                        ) : (
                                            <XCircle className="text-danger size-3.5 shrink-0" aria-label="Failed" />
                                        )}
                                        {f.name}
                                        {f.flaky && <span className="text-warning font-normal">passed when re-run alone</span>}
                                    </p>
                                    {f.file && <p className="text-muted-foreground font-mono">{f.file}</p>}
                                    {f.message && (
                                        <pre className="bg-muted mt-1 max-h-40 overflow-auto rounded p-2 whitespace-pre-wrap">{f.message}</pre>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}

                    {run.issues.length > 0 && (
                        <ul className="space-y-1">
                            {run.issues.map((issue, i) => (
                                <li key={i} className="flex gap-2">
                                    {issue.severity === 'error' ? (
                                        <XCircle className="text-danger mt-0.5 size-3.5 shrink-0" aria-label="Error" />
                                    ) : (
                                        <AlertTriangle className="text-warning mt-0.5 size-3.5 shrink-0" aria-label="Warning" />
                                    )}
                                    <span className="min-w-0">
                                        {issue.file && (
                                            <span className="text-heading font-mono break-all">
                                                {issue.file}
                                                {issue.line != null && `:${issue.line}`}
                                            </span>
                                        )}{' '}
                                        {issue.message}
                                        {issue.rule && <span className="text-muted-foreground"> ({issue.rule})</span>}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}

                    {run.files_changed.length > 0 && (
                        <div>
                            <p className="eyebrow mb-1">{run.mode === 'fix' ? 'Files formatted' : 'Files the tool changed'}</p>
                            <ul className="text-heading font-mono">
                                {run.files_changed.map((f) => (
                                    <li key={f} className="break-all">
                                        {f}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}

                    {run.notes.map((note, i) => (
                        <p key={i} className="text-muted-foreground">
                            {note}
                        </p>
                    ))}

                    {run.output_tail && (
                        <details>
                            <summary className="text-muted-foreground cursor-pointer">Output (last lines)</summary>
                            <pre className="bg-muted mt-1 max-h-64 overflow-auto rounded p-2 font-mono whitespace-pre-wrap">{run.output_tail}</pre>
                        </details>
                    )}
                </div>
            </details>
        </li>
    );
}

function formatDuration(ms: number): string {
    if (ms < 1000) return `${ms} ms`;
    const s = Math.round(ms / 1000);

    return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${s % 60}s`;
}
