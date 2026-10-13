import { AgentAvatar } from '@/components/agent-avatar';
import { StatusPill } from '@/components/status-pill';
import { GATE, QualityStep, type GateStatus, type Quality, type ToolRunView } from '@/components/task/quality-panel';
import { Button } from '@/components/ui/button';
import { relativeTime, type Tone } from '@/lib/events';
import { cn } from '@/lib/utils';
import { type AgentRole } from '@/types';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Ban,
    CheckCircle2,
    Clock3,
    FileCode2,
    GitPullRequestArrow,
    LoaderCircle,
    PauseCircle,
    Play,
    RotateCcw,
    ShieldAlert,
    ShieldCheck,
    Square,
    XCircle,
    type LucideIcon,
    type LucideProps,
} from 'lucide-react';
import { forwardRef, useState, type ReactNode } from 'react';

/** Spins only while a step really runs; tagged so reduced-motion and hidden-tab rules apply (§7.6). */
const Working = forwardRef<SVGSVGElement, LucideProps>(({ className, ...props }, ref) => (
    <LoaderCircle ref={ref} {...props} className={cn(className, 'animate-spin')} data-motion="loop" />
));
Working.displayName = 'Working';

type StepStatus = 'PENDING' | 'RUNNING' | 'COMPLETED' | 'FAILED' | 'CANCELLED';
type WorkflowStatus = 'PENDING' | 'RUNNING' | 'PAUSED' | 'BLOCKED' | 'COMPLETED' | 'FAILED' | 'CANCELLED';

interface ChangeSetView {
    id: number;
    role: AgentRole;
    mode: 'implement' | 'fix' | 'changes';
    status: 'applied' | 'empty';
    summary: string | null;
    confidence: 'HIGH' | 'MEDIUM' | 'LOW' | null;
    files: { path: string; additions: number | null; deletions: number | null; action: 'created' | 'modified' | 'deleted' }[];
    out_of_scope: string[];
    warnings: string[];
    additions: number;
    deletions: number;
    diff: string | null;
}

interface ReviewView {
    id: number;
    gate: 'qa' | 'senior' | 'security';
    passed: boolean;
    score: number;
    minimum_score: number;
    summary: string | null;
    confidence: string | null;
    head_sha: string | null;
    issues: { severity: string; file: string | null; line: number | null; issue: string; recommendation: string }[];
    acceptance: { criterion: string; met: boolean; evidence: string }[];
    blocking: string[];
    warnings: string[];
}

interface DeliveryStep {
    id: number;
    round: number;
    name: string;
    status: StepStatus;
    attempt: number;
    error: string | null;
    input: { to?: string; reason?: string; role?: AgentRole; mode?: string; gate?: string; tool?: string; cycle?: number };
    output: Record<string, unknown> | null;
    agent: string | null;
    agent_run: { status: string; model: string | null; tokens: number } | null;
    change_set: ChangeSetView | null;
    review: ReviewView | null;
    tool_run: ToolRunView | null;
}

export interface Delivery {
    workflow: {
        id: number;
        status: WorkflowStatus;
        blocked_reason: string | null;
        stopped_because: string | null;
        started_at: string | null;
        finished_at: string | null;
    };
    totals: { files: number; additions: number; deletions: number; tokens: number };
    steps: DeliveryStep[];
}

const WORKFLOW: Record<WorkflowStatus, { tone: Tone; icon: LucideIcon; label: string }> = {
    PENDING: { tone: 'neutral', icon: Clock3, label: 'Starting' },
    RUNNING: { tone: 'brand', icon: Working, label: 'AI team at work' },
    PAUSED: { tone: 'warning', icon: PauseCircle, label: 'Paused' },
    BLOCKED: { tone: 'danger', icon: AlertTriangle, label: 'Stopped — needs your decision' },
    COMPLETED: { tone: 'success', icon: CheckCircle2, label: 'Finished' },
    FAILED: { tone: 'danger', icon: XCircle, label: 'Failed' },
    CANCELLED: { tone: 'neutral', icon: Ban, label: 'Cancelled' },
};

const STEP: Record<StepStatus, { tone: Tone; icon: LucideIcon; label: string }> = {
    PENDING: { tone: 'neutral', icon: Clock3, label: 'Waiting' },
    RUNNING: { tone: 'brand', icon: Working, label: 'Working' },
    COMPLETED: { tone: 'success', icon: CheckCircle2, label: 'Done' },
    FAILED: { tone: 'danger', icon: XCircle, label: 'Failed' },
    CANCELLED: { tone: 'neutral', icon: Ban, label: 'Cancelled' },
};

const GATE_LABEL: Record<string, string> = { qa: 'QA review', senior: 'Senior review', security: 'Security review' };
const MODE_LABEL: Record<string, string> = { implement: 'Implementation', fix: 'Fix', changes: 'Requested changes' };
const BLOCKING = new Set(['BLOCKER', 'MAJOR', 'CRITICAL', 'HIGH']);
const ACTIVE = new Set<WorkflowStatus>(['PENDING', 'RUNNING']);

/**
 * AI delivery (§33, §48.8): what each developer really changed, what the
 * project's tools said about it, and what each reviewer found — every line
 * from a persisted step, change set, tool run or review.
 */
export function DeliveryPanel({
    delivery,
    projectSlug,
    taskNumber,
    canManage,
    canStart,
    blocker,
}: {
    delivery: Delivery | null;
    projectSlug: string;
    taskNumber: number;
    canManage: boolean;
    canStart: boolean;
    blocker: string | null;
}) {
    const [busy, setBusy] = useState(false);
    const status = delivery?.workflow.status;
    const active = status !== undefined && ACTIVE.has(status);
    const stopped = status === 'BLOCKED' || status === 'PAUSED';

    const post = (name: 'tasks.delivery' | 'tasks.delivery.cancel' | 'tasks.delivery.resume') => {
        setBusy(true);
        router.post(route(name, [projectSlug, taskNumber]), {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    const rounds = groupRounds(delivery?.steps ?? []);

    return (
        <div className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-3">
                <div className="flex flex-wrap items-center gap-2 text-xs">
                    {delivery && <StatusPill {...WORKFLOW[delivery.workflow.status]} />}
                    {delivery && delivery.totals.files > 0 && (
                        <span className="text-muted-foreground tabular">
                            {delivery.totals.files} file(s) · <span className="text-success">+{delivery.totals.additions}</span>{' '}
                            <span className="text-danger">−{delivery.totals.deletions}</span>
                            {delivery.totals.tokens > 0 && ` · ${delivery.totals.tokens.toLocaleString()} tokens`}
                        </span>
                    )}
                    {delivery?.workflow.started_at && (
                        <span className="text-muted-foreground">
                            started {relativeTime(delivery.workflow.started_at)}
                            {delivery.workflow.finished_at && ` · finished ${relativeTime(delivery.workflow.finished_at)}`}
                        </span>
                    )}
                </div>

                {canManage && (
                    <div className="flex gap-2">
                        {stopped && (
                            <Button size="sm" disabled={busy} onClick={() => post('tasks.delivery.resume')}>
                                <RotateCcw />
                                Resume
                            </Button>
                        )}
                        {(active || stopped) && (
                            <Button size="sm" variant="outline" disabled={busy} onClick={() => post('tasks.delivery.cancel')}>
                                <Square />
                                Cancel
                            </Button>
                        )}
                        {!active && !stopped && canStart && (
                            <Button size="sm" disabled={busy || blocker !== null} onClick={() => post('tasks.delivery')} title={blocker ?? undefined}>
                                <Play />
                                {delivery ? 'Implement again' : 'Implement with AI team'}
                            </Button>
                        )}
                    </div>
                )}
            </div>

            {!delivery && (
                <p className="text-muted-foreground text-sm">
                    {blocker ??
                        "Developers change the code in the task workspace, the project's own tools check it, and QA, senior and security reviewers judge the diff. Failing checks and review findings go back to the developers a limited number of times; you approve the result."}
                </p>
            )}

            {stopped && delivery?.workflow.stopped_because && (
                <p role="alert" className="border-danger/30 bg-danger/10 rounded-md border px-3 py-2 text-sm">
                    <span className="font-medium">Stopped:</span> {delivery.workflow.stopped_because}
                </p>
            )}

            {rounds.map((round, i) => (
                <section key={round.index} aria-label={round.title} className="space-y-2">
                    <h3 className="eyebrow">{i === 0 ? 'Implementation' : round.title}</h3>
                    {round.blocks.map((block) => (
                        <div key={block.key}>{block.node}</div>
                    ))}
                </section>
            ))}
        </div>
    );
}

function groupRounds(steps: DeliveryStep[]) {
    const rounds: { index: number; title: string; blocks: { key: string; node: ReactNode }[] }[] = [];
    let tools: DeliveryStep[] = [];

    const flush = (round: (typeof rounds)[number]) => {
        if (tools.length === 0) return;
        const group = tools;
        tools = [];
        round.blocks.push({
            key: `tools-${group[0].id}`,
            node: (
                <ol className="divide-border divide-y rounded-md border">
                    {group.map((s) => (
                        <QualityStep key={s.id} step={asQualityStep(s)} />
                    ))}
                </ol>
            ),
        });
    };

    for (const step of steps) {
        let round = rounds[rounds.length - 1];
        if (!round || round.index !== step.round) {
            if (round) flush(round);
            round = { index: step.round, title: step.input.reason ?? `Round ${step.round}`, blocks: [] };
            rounds.push(round);
        }

        if (step.name === 'tool.run' || step.name === 'workspace.checkpoint') {
            tools.push(step);
            continue;
        }
        flush(round);

        const node = renderStep(step);
        if (node) round.blocks.push({ key: `step-${step.id}`, node });
    }

    const last = rounds[rounds.length - 1];
    if (last) flush(last);

    return rounds;
}

function renderStep(step: DeliveryStep): ReactNode {
    switch (step.name) {
        case 'task.transition':
            return step.status === 'COMPLETED' && step.output?.changed ? (
                <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                    <ArrowRight className="size-3.5" aria-hidden="true" />
                    Task moved to <span className="text-heading font-medium">{STATUS_LABEL[step.input.to ?? ''] ?? step.input.to}</span>
                </p>
            ) : null;
        case 'agent.implement':
            return <ImplementCard step={step} />;
        case 'agent.review':
            return <ReviewCard step={step} />;
        case 'quality.report':
            return step.output ? (
                <GateLine report={step.output as { passed: boolean; gates: { kind: string; label: string; status: GateStatus }[] }} />
            ) : null;
        case 'delivery.after_checks':
        case 'review.decide':
            return <Decision step={step} />;
        default:
            return null;
    }
}

const STATUS_LABEL: Record<string, string> = {
    IN_PROGRESS: 'In Development',
    TESTING: 'Testing',
    REVIEW: 'Senior Review',
    SECURITY_REVIEW: 'Security Review',
    CHANGES_REQUESTED: 'Changes Requested',
    RELEASE_READY: 'Release Ready',
};

function ImplementCard({ step }: { step: DeliveryStep }) {
    const c = step.change_set;
    const pill = c
        ? c.status === 'applied'
            ? { tone: 'success' as Tone, icon: FileCode2, label: 'Changes applied' }
            : { tone: 'neutral' as Tone, icon: CheckCircle2, label: 'No changes' }
        : STEP[step.status];

    return (
        <article className="rounded-md border p-3">
            <header className="flex flex-wrap items-center gap-2">
                {step.input.role && <AgentAvatar role={step.input.role} size="sm" />}
                <span className="text-heading text-sm font-medium">{step.agent ?? step.input.role}</span>
                <span className="text-muted-foreground text-xs">{MODE_LABEL[step.input.mode ?? 'implement']}</span>
                <StatusPill {...pill} />
                {step.attempt > 1 && <span className="text-warning text-xs">attempt {step.attempt}</span>}
                {c && (
                    <span className="text-muted-foreground tabular ml-auto text-xs">
                        <span className="text-success">+{c.additions}</span> <span className="text-danger">−{c.deletions}</span>
                        {c.confidence && ` · confidence ${c.confidence.toLowerCase()}`}
                    </span>
                )}
            </header>
            {step.error && step.status !== 'COMPLETED' && <p className="text-danger mt-2 text-xs">{step.error}</p>}
            {c?.summary && <p className="text-foreground mt-2 text-sm">{c.summary}</p>}
            {c && c.files.length > 0 && (
                <ul className="mt-2 space-y-0.5 font-mono text-xs">
                    {c.files.map((f) => (
                        <li key={f.path} className="flex gap-2">
                            <span
                                className={cn(
                                    'w-16 shrink-0',
                                    f.action === 'created' ? 'text-success' : f.action === 'deleted' ? 'text-danger' : 'text-muted-foreground',
                                )}
                            >
                                {f.action}
                            </span>
                            <span className="text-heading min-w-0 break-all">{f.path}</span>
                            {c.out_of_scope.includes(f.path) && <span className="text-warning shrink-0">outside the declared scope</span>}
                        </li>
                    ))}
                </ul>
            )}
            {c?.warnings.map((w, i) => (
                <p key={i} className="text-warning mt-1 text-xs">
                    {w}
                </p>
            ))}
            {c?.diff && (
                <details className="mt-2">
                    <summary className="text-muted-foreground cursor-pointer text-xs">
                        <GitPullRequestArrow className="mr-1 inline size-3.5" aria-hidden="true" />
                        View diff
                    </summary>
                    <Diff text={c.diff} />
                </details>
            )}
        </article>
    );
}

function Diff({ text }: { text: string }) {
    return (
        <pre className="bg-muted mt-1 max-h-96 overflow-auto rounded p-2 font-mono text-xs leading-relaxed">
            {text.split('\n').map((line, i) => (
                <div
                    key={i}
                    className={cn(
                        line.startsWith('+') && !line.startsWith('+++') && 'text-success',
                        line.startsWith('-') && !line.startsWith('---') && 'text-danger',
                        (line.startsWith('@@') || line.startsWith('diff --git')) && 'text-muted-foreground',
                    )}
                >
                    {line || ' '}
                </div>
            ))}
        </pre>
    );
}

function ReviewCard({ step }: { step: DeliveryStep }) {
    const r = step.review;
    const gate = step.input.gate ?? r?.gate ?? '';
    const security = gate === 'security';
    const pill = r
        ? r.passed
            ? { tone: 'success' as Tone, icon: security ? ShieldCheck : CheckCircle2, label: 'Passed' }
            : { tone: 'danger' as Tone, icon: security ? ShieldAlert : XCircle, label: 'Changes needed' }
        : STEP[step.status];

    return (
        <article className="rounded-md border p-3">
            <header className="flex flex-wrap items-center gap-2">
                {step.input.role && <AgentAvatar role={step.input.role} size="sm" />}
                <span className="text-heading text-sm font-medium">{GATE_LABEL[gate] ?? gate}</span>
                <StatusPill {...pill} />
                {r && (
                    <span className="text-muted-foreground tabular text-xs">
                        score <span className={cn('font-medium', r.score >= r.minimum_score ? 'text-success' : 'text-danger')}>{r.score}</span> / min{' '}
                        {r.minimum_score}
                    </span>
                )}
            </header>
            {step.error && step.status !== 'COMPLETED' && <p className="text-danger mt-2 text-xs">{step.error}</p>}
            {r?.summary && <p className="text-foreground mt-2 text-sm">{r.summary}</p>}
            {r && r.blocking.length > 0 && (
                <ul className="mt-2 space-y-1 text-xs">
                    {r.blocking.map((b, i) => (
                        <li key={i} className="text-danger flex gap-1.5">
                            <XCircle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                            {b}
                        </li>
                    ))}
                </ul>
            )}
            {r && (r.issues.length > 0 || r.acceptance.length > 0) && (
                <details className="mt-2">
                    <summary className="text-muted-foreground cursor-pointer text-xs">
                        {r.issues.length} finding(s)
                        {r.acceptance.length > 0 && ` · ${r.acceptance.filter((a) => a.met).length}/${r.acceptance.length} criteria met`}
                    </summary>
                    <ul className="mt-2 space-y-2 text-xs">
                        {r.issues.map((issue, i) => (
                            <li key={i}>
                                <span className={cn('font-medium', BLOCKING.has(issue.severity) ? 'text-danger' : 'text-muted-foreground')}>
                                    {issue.severity}
                                </span>{' '}
                                {issue.file && (
                                    <span className="text-heading font-mono">
                                        {issue.file}
                                        {issue.line != null && `:${issue.line}`}
                                    </span>
                                )}{' '}
                                {issue.issue}
                                {issue.recommendation && <p className="text-muted-foreground">→ {issue.recommendation}</p>}
                            </li>
                        ))}
                        {r.acceptance.map((a, i) => (
                            <li key={`a${i}`} className="flex gap-1.5">
                                {a.met ? (
                                    <CheckCircle2 className="text-success mt-0.5 size-3.5 shrink-0" aria-label="Met" />
                                ) : (
                                    <XCircle className="text-danger mt-0.5 size-3.5 shrink-0" aria-label="Not met" />
                                )}
                                <span>
                                    {a.criterion}
                                    {a.evidence && <span className="text-muted-foreground"> — {a.evidence}</span>}
                                </span>
                            </li>
                        ))}
                    </ul>
                </details>
            )}
            {r?.warnings.map((w, i) => (
                <p key={i} className="text-muted-foreground mt-1 text-xs">
                    {w}
                </p>
            ))}
        </article>
    );
}

function GateLine({ report }: { report: { passed: boolean; gates: { kind: string; label: string; status: GateStatus }[] } }) {
    return (
        <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs" aria-label="Quality gates">
            {report.gates.map((g) => {
                const s = GATE[g.status];

                return (
                    <li key={g.kind} className="inline-flex items-center gap-1">
                        <s.icon className={cn('size-3.5', s.className)} aria-hidden="true" />
                        <span className="text-heading">{g.label}</span>
                        <span className="text-muted-foreground">{s.label}</span>
                    </li>
                );
            })}
        </ul>
    );
}

function Decision({ step }: { step: DeliveryStep }) {
    const decision = step.output?.decision as string | undefined;

    if (step.status === 'FAILED' || (step.status === 'PENDING' && step.error)) {
        return (
            <p className="text-danger flex items-start gap-1.5 text-xs">
                <AlertTriangle className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                {step.error}
            </p>
        );
    }

    const text: Record<string, [LucideIcon, string, string]> = {
        review: [CheckCircle2, 'text-success', 'Quality gates passed — on to review.'],
        fix: [RotateCcw, 'text-warning', `Gates failed (${String(step.output?.failing ?? '')}) — back to the developers.`],
        release_ready: [ShieldCheck, 'text-success', 'Every review passed on the same commit — Release Ready, waiting for your approval.'],
        changes: [GitPullRequestArrow, 'text-warning', `Changes requested by the reviewers: ${String(step.output?.why ?? '')}.`],
    };
    const entry = decision ? text[decision] : undefined;
    if (!entry) return null;
    const [Icon, className, label] = entry;

    return (
        <p className={cn('flex items-start gap-1.5 text-xs font-medium', className)}>
            <Icon className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            {label}
        </p>
    );
}

function asQualityStep(step: DeliveryStep): Quality['steps'][number] {
    const checkpoint =
        step.name === 'workspace.checkpoint' && step.output
            ? { sha: (step.output.sha as string) ?? null, changed: Boolean(step.output.changed), files: Number(step.output.files ?? 0) }
            : null;

    return {
        id: step.id,
        name: step.name,
        status: step.status,
        label:
            step.tool_run?.label ??
            (step.name === 'workspace.checkpoint' ? `Checkpoint ${step.output?.reason ?? ''}`.trim() : (step.input.tool ?? 'Tool')),
        kind: step.tool_run?.kind ?? null,
        mode: (step.input.mode as 'check' | 'fix' | undefined) ?? null,
        attempt: step.attempt,
        error: step.error,
        reason: step.name === 'tool.run' ? ((step.output?.reason as string | undefined) ?? null) : null,
        checkpoint,
        run: step.tool_run,
    };
}
