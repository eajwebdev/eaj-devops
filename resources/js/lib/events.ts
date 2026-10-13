import { AGENT_STATUS } from '@/components/agent-status';
import { type StudioEvent } from '@/types';
import {
    AlertTriangle,
    ArrowRightLeft,
    Ban,
    Bot,
    CheckCircle2,
    CirclePause,
    CirclePlay,
    FileCode2,
    FolderGit2,
    FolderPlus,
    FolderX,
    GitBranch,
    GitCommitHorizontal,
    Hand,
    Link2,
    ListTree,
    LucideIcon,
    MessageSquare,
    OctagonX,
    Pencil,
    Play,
    PlugZap,
    RefreshCcw,
    RotateCcw,
    ScanSearch,
    ShieldAlert,
    ShieldCheck,
    SquarePlus,
    Unlink,
    Unplug,
    Wand2,
    Workflow,
    Wrench,
    XCircle,
} from 'lucide-react';

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'brand';

export interface EventDescription {
    icon: LucideIcon;
    tone: Tone;
    title: string;
    detail?: string;
}

const str = (value: unknown): string | undefined => (typeof value === 'string' && value !== '' ? value : undefined);

const stepName = (e: StudioEvent) => str((e.payload.step as { name?: string } | undefined)?.name) ?? 'step';
const projectName = (e: StudioEvent) => str((e.payload.project as { name?: string } | undefined)?.name) ?? 'Project';
const runnerName = (e: StudioEvent) => str((e.payload.runner as { name?: string } | undefined)?.name) ?? '';
const commandLabel = (e: StudioEvent) => str((e.payload.command as { label?: string } | undefined)?.label) ?? 'Runner command';
const taskKey = (e: StudioEvent) => str((e.payload.task as { key?: string } | undefined)?.key) ?? 'Task';
const taskLabel = (e: StudioEvent) => `${taskKey(e)} ${str((e.payload.task as { title?: string } | undefined)?.title) ?? ''}`.trim();

const TASK_STATUS_LABEL: Record<string, string> = {
    BACKLOG: 'Backlog',
    READY: 'Ready',
    PLANNING: 'Planning',
    IN_PROGRESS: 'In Development',
    TESTING: 'Testing',
    REVIEW: 'Senior Review',
    SECURITY_REVIEW: 'Security Review',
    CHANGES_REQUESTED: 'Changes Requested',
    BLOCKED: 'Blocked',
    RELEASE_READY: 'Release Ready',
    DONE: 'Completed',
    CANCELLED: 'Cancelled',
};
const agentMessage = (e: StudioEvent) => str(e.payload.message) ?? e.type;
const agentTask = (e: StudioEvent) => {
    const role = str((e.payload.agent as { role?: string } | undefined)?.role);
    const key = str((e.payload.task as { key?: string } | undefined)?.key);

    return [role, key].filter(Boolean).join(' · ') || undefined;
};
const toolRun = (e: StudioEvent) => (e.payload.tool_run ?? {}) as { label?: string; status?: string; summary?: string | null; error?: string | null };
const TOOL_RESULT: Record<string, { icon: LucideIcon; tone: Tone; verb: string }> = {
    passed: { icon: CheckCircle2, tone: 'success', verb: 'passed' },
    failed: { icon: XCircle, tone: 'danger', verb: 'failed' },
    flaky: { icon: AlertTriangle, tone: 'warning', verb: 'found flaky tests' },
    error: { icon: XCircle, tone: 'danger', verb: 'could not run' },
    skipped: { icon: CheckCircle2, tone: 'neutral', verb: 'had nothing to check' },
    blocked: { icon: ShieldAlert, tone: 'warning', verb: 'was blocked by the runner' },
    cancelled: { icon: Ban, tone: 'neutral', verb: 'was stopped' },
};
const workspaceKey = (e: StudioEvent) => str((e.payload.workspace as { task_key?: string } | undefined)?.task_key) ?? 'task';

/** Per-command chatter drives live progress but stays out of activity feeds (Plan §61: meaningful events only). */
const QUIET_TYPES = new Set([
    'runner.command.queued',
    'runner.command.started',
    'runner.command.progress',
    'runner.command.succeeded',
    'task.reordered',
    'task.impact.analyzed',
    'tool.run.queued',
    'tool.run.started',
    'tool.run.progress',
    'workflow.steps.appended',
]);

export function isFeedEvent(event: StudioEvent): boolean {
    return !QUIET_TYPES.has(event.type);
}

/**
 * Human wording for persisted event types (Plan §61). Unknown types still
 * render — as their raw type — so no real event is ever hidden.
 */
export function describeEvent(event: StudioEvent): EventDescription {
    const p = event.payload;

    switch (event.type) {
        case 'project.created':
            return {
                icon: FolderPlus,
                tone: 'brand',
                title: `${projectName(event)} was registered`,
                detail: str(p.connection_type) && `Connection: ${p.connection_type}`,
            };
        case 'project.deleted':
            return {
                icon: FolderX,
                tone: 'neutral',
                title: `${projectName(event)} was removed from the Studio`,
                detail: 'Files on disk were not touched',
            };
        case 'operations.halted':
            return {
                icon: OctagonX,
                tone: 'danger',
                title: p.scope === 'global' ? 'AI operations stopped platform-wide' : 'AI operations stopped',
                detail: str(p.reason),
            };
        case 'operations.resumed':
            return {
                icon: CirclePlay,
                tone: 'success',
                title: 'AI operations resumed',
                detail: `${p.resumed_workflows ?? 0} workflow(s) continuing`,
            };
        case 'workflow.status.changed':
            return describeWorkflowStatus(event);
        case 'workflow.step.started':
            return { icon: Play, tone: 'brand', title: `Started ${stepName(event)}`, detail: `Attempt ${p.attempt ?? 1}` };
        case 'workflow.step.completed':
            return {
                icon: GitCommitHorizontal,
                tone: 'success',
                title: `Completed ${stepName(event)}`,
                detail: str(p.commit_sha) && `Checkpoint ${String(p.commit_sha).slice(0, 7)}`,
            };
        case 'workflow.step.retrying':
            return { icon: RefreshCcw, tone: 'warning', title: `Retrying ${stepName(event)} in ${p.retry_in_seconds}s`, detail: str(p.error) };
        case 'workflow.step.recovered':
            return { icon: RotateCcw, tone: 'info', title: `Recovered ${stepName(event)} from the last checkpoint`, detail: str(p.error) };
        case 'workflow.step.failed':
            return { icon: XCircle, tone: 'danger', title: `${stepName(event)} failed`, detail: str(p.error) };
        case 'human.approval.required':
            return { icon: AlertTriangle, tone: 'warning', title: 'Needs your decision', detail: str(p.detail) };
        case 'task.created':
            return { icon: SquarePlus, tone: 'brand', title: `${taskLabel(event)} created` };
        case 'task.updated':
            return {
                icon: Pencil,
                tone: 'neutral',
                title: `${taskLabel(event)} edited`,
                detail: Array.isArray(p.fields) ? `Changed ${(p.fields as string[]).join(', ').replace(/_/g, ' ')}` : undefined,
            };
        case 'task.status.changed':
            return {
                icon: p.to === 'DONE' ? CheckCircle2 : p.to === 'BLOCKED' ? Hand : p.to === 'CANCELLED' ? Ban : ArrowRightLeft,
                tone:
                    p.to === 'DONE'
                        ? 'success'
                        : p.to === 'BLOCKED' || p.to === 'CHANGES_REQUESTED'
                          ? 'warning'
                          : p.to === 'CANCELLED'
                            ? 'neutral'
                            : 'brand',
                title: `${taskKey(event)} → ${TASK_STATUS_LABEL[String(p.to)] ?? p.to}`,
                detail: str(p.reason) ?? str((p.task as { title?: string } | undefined)?.title),
            };
        case 'task.comment.added':
            return {
                icon: p.kind === 'instruction' ? Wand2 : MessageSquare,
                tone: 'neutral',
                title: `${p.kind === 'instruction' ? 'Instruction' : 'Comment'} on ${taskKey(event)}`,
                detail: str(p.excerpt),
            };
        case 'task.dependency.added':
            return {
                icon: Link2,
                tone: 'neutral',
                title: `${taskKey(event)} now waits for ${str((p.depends_on as { key?: string } | undefined)?.key) ?? 'another task'}`,
            };
        case 'task.dependency.removed':
            return {
                icon: Unlink,
                tone: 'neutral',
                title: `${taskKey(event)} no longer waits for ${str((p.depends_on as { key?: string } | undefined)?.key) ?? 'another task'}`,
            };
        case 'agent.definition.updated':
            return { icon: Bot, tone: 'neutral', title: 'Agent definition updated' };
        case 'skill.updated':
            return { icon: Wand2, tone: 'neutral', title: 'Skill availability updated' };
        case 'model_route.updated':
        case 'ai_model.created':
            return {
                icon: ArrowRightLeft,
                tone: 'neutral',
                title: event.type === 'ai_model.created' ? 'AI model registered' : 'Model route updated',
            };
        case 'runner.paired':
            return {
                icon: PlugZap,
                tone: 'brand',
                title: `Runner ${runnerName(event)} was paired`,
                detail: str((p.runner as { hostname?: string } | undefined)?.hostname),
            };
        case 'runner.connected':
            return { icon: PlugZap, tone: 'success', title: `Runner ${runnerName(event)} connected` };
        case 'runner.disconnected':
            return {
                icon: Unplug,
                tone: 'warning',
                title: `Runner ${runnerName(event)} went offline`,
                detail: 'Start the runner on that PC to continue',
            };
        case 'runner.revoked':
            return { icon: Ban, tone: 'neutral', title: `Runner ${runnerName(event)} was revoked` };
        case 'runner.command.failed':
            return {
                icon: XCircle,
                tone: 'danger',
                title: `${commandLabel(event)} failed`,
                detail: str((p.command as { error?: string } | undefined)?.error),
            };
        case 'runner.command.requeued':
            return {
                icon: RefreshCcw,
                tone: 'warning',
                title: `${commandLabel(event)} offered to the runner again`,
                detail: 'The runner stopped responding',
            };
        case 'project.scanned':
            return { icon: ScanSearch, tone: 'success', title: 'Project scanned', detail: str(p.summary) };
        case 'project.indexed':
            return {
                icon: ListTree,
                tone: 'success',
                title: 'Code index ready',
                detail: `${Number(p.files ?? 0).toLocaleString()} files · ${Number(p.symbols ?? 0).toLocaleString()} symbols`,
            };
        case 'git.initialized':
            return {
                icon: GitBranch,
                tone: 'success',
                title: 'Git initialized with a baseline commit',
                detail: `${p.files_committed ?? 0} files committed`,
            };
        case 'workspace.created':
            return {
                icon: FolderGit2,
                tone: 'brand',
                title: `Workspace ready for ${workspaceKey(event)}`,
                detail: str((p.workspace as { branch?: string } | undefined)?.branch),
            };
        case 'workspace.checkpointed':
            return { icon: GitCommitHorizontal, tone: 'success', title: `Checkpoint in ${workspaceKey(event)}` };
        case 'workspace.deleted':
            return { icon: FolderX, tone: 'neutral', title: `Workspace for ${workspaceKey(event)} removed`, detail: 'The branch was kept' };
        case 'workspace.failed':
        case 'workspace.remove_failed':
            return { icon: XCircle, tone: 'danger', title: `Workspace problem in ${workspaceKey(event)}`, detail: str(p.error) };
        case 'agent.run.started':
        case 'agent.run.resumed':
            return { icon: Play, tone: 'brand', title: agentMessage(event), detail: agentTask(event) };
        case 'agent.status.changed': {
            const status = String((p.metadata as { status?: string } | undefined)?.status ?? '');
            const state = AGENT_STATUS[status as keyof typeof AGENT_STATUS];

            return { icon: state?.icon ?? Workflow, tone: state?.tone ?? 'brand', title: agentMessage(event), detail: agentTask(event) };
        }
        case 'agent.run.completed':
            return { icon: CheckCircle2, tone: 'success', title: agentMessage(event), detail: agentTask(event) };
        case 'agent.run.paused':
            return { icon: CirclePause, tone: 'warning', title: agentMessage(event), detail: agentTask(event) };
        case 'agent.run.retrying':
            return { icon: RefreshCcw, tone: 'warning', title: agentMessage(event), detail: agentTask(event) };
        case 'agent.run.attempt_started':
            return { icon: Play, tone: 'brand', title: agentMessage(event), detail: agentTask(event) };
        case 'ai.provider.fallback':
            return { icon: RotateCcw, tone: 'warning', title: agentMessage(event), detail: agentTask(event) };
        case 'agent.run.retry_limit_reached':
        case 'agent.run.budget_exhausted':
            return { icon: AlertTriangle, tone: 'warning', title: agentMessage(event), detail: `Needs your decision · ${agentTask(event) ?? ''}` };
        case 'budget.warning':
            return { icon: AlertTriangle, tone: 'warning', title: agentMessage(event), detail: agentTask(event) };
        case 'agent.run.failed':
            return { icon: XCircle, tone: 'danger', title: agentMessage(event), detail: agentTask(event) };
        case 'agent.run.cancelled':
            return { icon: Ban, tone: 'neutral', title: agentMessage(event), detail: agentTask(event) };
        case 'tool.run.queued':
        case 'tool.run.started':
            return { icon: Wrench, tone: 'brand', title: `${toolRun(event).label ?? 'Tool'} started` };
        case 'tool.run.progress':
            return { icon: Wrench, tone: 'brand', title: str(p.message) ?? 'Tool running' };
        case 'tool.run.finished': {
            const run = toolRun(event);
            const result = TOOL_RESULT[run.status ?? ''] ?? TOOL_RESULT.error;

            return {
                icon: result.icon,
                tone: result.tone,
                title: `${run.label ?? 'Tool'} ${result.verb}`,
                detail: run.summary ?? run.error ?? undefined,
            };
        }
        case 'quality.checks.completed': {
            const gates = (p.gates as { label: string; status: string }[] | undefined) ?? [];
            const failing = gates.filter((g) => g.status === 'failed' || g.status === 'not_run').map((g) => g.label);

            return p.passed
                ? { icon: ShieldCheck, tone: 'success', title: `Quality gates passed for ${str(p.task) ?? 'task'}` }
                : {
                      icon: ShieldAlert,
                      tone: 'danger',
                      title: `Quality gates failed for ${str(p.task) ?? 'task'}`,
                      detail: failing.join(', ') || undefined,
                  };
        }
        case 'project.tools.updated':
            return { icon: Wrench, tone: 'info', title: 'Engineering tools updated', detail: `${p.tools ?? 0} tools detected` };
        case 'project.tool.changed': {
            const tool = (p.tool ?? {}) as { label?: string; enabled?: boolean };

            return { icon: Wrench, tone: 'neutral', title: `${tool.label ?? 'Tool'} ${tool.enabled ? 'allowed for agents' : 'switched off'}` };
        }
        case 'delivery.changes.applied': {
            const c = (p.change_set ?? {}) as { role?: string; files?: unknown[]; additions?: number; deletions?: number; mode?: string };

            return {
                icon: FileCode2,
                tone: 'brand',
                title: `${c.role ?? 'Developer'} changed ${c.files?.length ?? 0} file(s) in ${taskKey(event)}`,
                detail: `+${c.additions ?? 0} −${c.deletions ?? 0}${c.mode && c.mode !== 'implement' ? ` · ${c.mode === 'fix' ? 'fix cycle' : 'requested changes'}` : ''}`,
            };
        }
        case 'delivery.review.completed': {
            const r = (p.review ?? {}) as { gate?: string; passed?: boolean; score?: number; minimum_score?: number; blocking?: number };
            const gate = { qa: 'QA', senior: 'Senior', security: 'Security' }[r.gate ?? ''] ?? 'Review';

            return r.passed
                ? {
                      icon: CheckCircle2,
                      tone: 'success',
                      title: `${gate} review passed ${taskKey(event)}`,
                      detail: `score ${r.score}/${r.minimum_score}`,
                  }
                : {
                      icon: XCircle,
                      tone: 'danger',
                      title: `${gate} review requested changes on ${taskKey(event)}`,
                      detail: `score ${r.score}/${r.minimum_score} · ${r.blocking ?? 0} blocking`,
                  };
        }
        case 'delivery.release_ready':
            return { icon: ShieldCheck, tone: 'success', title: `${taskLabel(event)} is Release Ready`, detail: 'Waiting for your approval' };
        case 'runner.command.cancel_requested':
            return { icon: Ban, tone: 'neutral', title: `Stopping ${commandLabel(event)} on the runner` };
        default:
            return { icon: Workflow, tone: 'neutral', title: event.type };
    }
}

function describeWorkflowStatus(event: StudioEvent): EventDescription {
    const to = event.payload.to;
    const reason = str(event.payload.reason);

    switch (to) {
        case 'RUNNING':
            return { icon: Play, tone: 'brand', title: 'Workflow running', detail: reason };
        case 'PAUSED':
            return { icon: CirclePause, tone: 'warning', title: 'Workflow paused', detail: reason };
        case 'BLOCKED':
            return { icon: AlertTriangle, tone: 'warning', title: 'Workflow blocked', detail: reason };
        case 'COMPLETED':
            return { icon: CheckCircle2, tone: 'success', title: 'Workflow completed' };
        case 'FAILED':
            return { icon: XCircle, tone: 'danger', title: 'Workflow failed', detail: reason };
        case 'CANCELLED':
            return { icon: XCircle, tone: 'neutral', title: 'Workflow cancelled', detail: reason };
        default:
            return { icon: Workflow, tone: 'neutral', title: `Workflow ${String(to).toLowerCase()}` };
    }
}

export const toneText: Record<Tone, string> = {
    success: 'text-success',
    warning: 'text-warning',
    danger: 'text-danger',
    info: 'text-info',
    neutral: 'text-muted-foreground',
    brand: 'text-brand-text',
};

export const toneSurface: Record<Tone, string> = {
    success: 'bg-success/10 text-success ring-success/25',
    warning: 'bg-warning/10 text-warning ring-warning/25',
    danger: 'bg-danger/10 text-danger ring-danger/25',
    info: 'bg-info/10 text-info ring-info/25',
    neutral: 'bg-muted text-muted-foreground ring-border',
    brand: 'bg-brand-crimson/10 text-brand-text ring-brand-crimson/25',
};

export function relativeTime(iso: string, now: number = Date.now()): string {
    const seconds = Math.round((now - new Date(iso).getTime()) / 1000);

    if (seconds < 45) return 'just now';
    if (seconds < 90) return '1 min ago';
    if (seconds < 3600) return `${Math.round(seconds / 60)} min ago`;
    if (seconds < 86400) return `${Math.round(seconds / 3600)} h ago`;

    return new Date(iso).toLocaleDateString(undefined, { month: 'short', day: 'numeric' });
}
