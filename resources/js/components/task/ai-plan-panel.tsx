import { AgentAvatar } from '@/components/agent-avatar';
import { StatusPill } from '@/components/status-pill';
import { relativeTime, type Tone } from '@/lib/events';
import { cn } from '@/lib/utils';
import { type AgentRole } from '@/types';
import {
    AlertTriangle,
    Ban,
    CheckCircle2,
    Clock3,
    LoaderCircle,
    PauseCircle,
    RefreshCw,
    SkipForward,
    XCircle,
    type LucideIcon,
    type LucideProps,
} from 'lucide-react';
import { forwardRef } from 'react';

/** Spins only while a run is really executing; tagged so reduced-motion and hidden-tab rules apply (§7.6). */
const Working = forwardRef<SVGSVGElement, LucideProps>(({ className, ...props }, ref) => (
    <LoaderCircle ref={ref} {...props} className={cn(className, 'animate-spin')} data-motion="loop" />
));
Working.displayName = 'Working';

type RunStatus = 'PENDING' | 'RUNNING' | 'RETRYING' | 'PAUSED' | 'BLOCKED' | 'COMPLETED' | 'FAILED' | 'CANCELLED';

export interface AiPlan {
    status: 'PENDING' | 'RUNNING' | 'PAUSED' | 'BLOCKED' | 'COMPLETED' | 'FAILED' | 'CANCELLED';
    started_at: string | null;
    finished_at: string | null;
    skipped: { role: AgentRole; reason: string }[];
    specialists: {
        role: AgentRole;
        name: string;
        reason: string;
        step_status: 'PENDING' | 'RUNNING' | 'COMPLETED' | 'FAILED' | 'CANCELLED';
        error: string | null;
        run: { status: RunStatus; model: string | null; tokens: number; attempt: number; prompt_version: string | null } | null;
        result: { status: 'completed' | 'blocked' | null; summary: string; warnings: string[] } | null;
    }[];
}

const RUN: Record<RunStatus, { tone: Tone; icon: LucideIcon; label: string }> = {
    PENDING: { tone: 'neutral', icon: Clock3, label: 'Waiting' },
    RUNNING: { tone: 'brand', icon: Working, label: 'Planning' },
    RETRYING: { tone: 'warning', icon: RefreshCw, label: 'Retrying' },
    PAUSED: { tone: 'warning', icon: PauseCircle, label: 'Paused' },
    BLOCKED: { tone: 'danger', icon: AlertTriangle, label: 'Blocked' },
    COMPLETED: { tone: 'success', icon: CheckCircle2, label: 'Plan ready' },
    FAILED: { tone: 'danger', icon: XCircle, label: 'Failed' },
    CANCELLED: { tone: 'neutral', icon: Ban, label: 'Cancelled' },
};

const PLAN: Record<AiPlan['status'], string> = {
    PENDING: 'Starting',
    RUNNING: 'The AI team is planning',
    PAUSED: 'Paused',
    BLOCKED: 'Stopped — needs your decision',
    COMPLETED: 'Plan finished',
    FAILED: 'Failed',
    CANCELLED: 'Cancelled',
};

/**
 * The orchestrated plan (Phase 6): which specialists the task needed, what each
 * one's run is really doing, and its schema-validated result. Nothing here is
 * shown unless a persisted run or step says so.
 */
export function AiPlanPanel({ plan }: { plan: AiPlan }) {
    return (
        <div className="space-y-4">
            <p className="text-muted-foreground text-xs">
                {PLAN[plan.status]}
                {plan.started_at && <> · started {relativeTime(plan.started_at)}</>}
                {plan.finished_at && <> · finished {relativeTime(plan.finished_at)}</>}
            </p>

            <ol className="space-y-3">
                {plan.specialists.map((s) => {
                    const status: RunStatus = s.run?.status ?? (s.step_status === 'CANCELLED' ? 'CANCELLED' : 'PENDING');
                    const view = RUN[status];

                    return (
                        <li key={s.role} className="border-border rounded-md border p-3">
                            <div className="flex flex-wrap items-start justify-between gap-2">
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <AgentAvatar role={s.role} size="sm" hint={s.reason} />
                                    <div className="min-w-0">
                                        <p className="truncate text-sm font-medium">{s.name}</p>
                                        <p className="text-muted-foreground truncate text-xs">{s.reason}</p>
                                    </div>
                                </div>
                                <StatusPill tone={view.tone} icon={view.icon} label={view.label} />
                            </div>

                            {s.result && (
                                <div className="mt-3 space-y-2">
                                    {s.result.status === 'blocked' && (
                                        <p className="text-warning flex items-center gap-1.5 text-xs font-medium">
                                            <AlertTriangle className="size-3.5" />
                                            Needs clarification before it can plan
                                        </p>
                                    )}
                                    <p className="text-sm whitespace-pre-wrap">{s.result.summary}</p>
                                    {s.result.warnings.length > 0 && (
                                        <ul className="space-y-1">
                                            {s.result.warnings.map((w, i) => (
                                                <li key={i} className="text-muted-foreground flex items-start gap-1.5 text-xs">
                                                    <AlertTriangle className="text-warning mt-0.5 size-3.5 shrink-0" />
                                                    {w}
                                                </li>
                                            ))}
                                        </ul>
                                    )}
                                </div>
                            )}

                            {!s.result && s.error && (status === 'FAILED' || status === 'BLOCKED' || s.step_status === 'FAILED') && (
                                <p className="text-danger mt-3 text-xs break-words">{s.error}</p>
                            )}

                            {s.run && (
                                <p className="text-muted-foreground mt-3 flex flex-wrap gap-x-3 gap-y-1 text-[0.7rem]">
                                    <span>{s.run.model ?? 'No model'}</span>
                                    <span className="tabular">{s.run.tokens.toLocaleString()} tokens</span>
                                    <span>Attempt {s.run.attempt}</span>
                                    {s.run.prompt_version && <span>Prompt v{s.run.prompt_version}</span>}
                                </p>
                            )}
                        </li>
                    );
                })}
            </ol>

            {plan.skipped.length > 0 && (
                <div>
                    <p className="eyebrow mb-2">Not activated</p>
                    <ul className="space-y-1">
                        {plan.skipped.map((s) => (
                            <li key={s.role} className="text-muted-foreground flex items-start gap-1.5 text-xs">
                                <SkipForward className="mt-0.5 size-3.5 shrink-0" />
                                <span>
                                    <span className="text-foreground font-medium">{s.role}</span> — {s.reason}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
        </div>
    );
}
