import { ActivityTimeline } from '@/components/activity-timeline';
import { ActivityDots, AgentProgress, AgentStatusRing, type PresenceProgress, type PresenceStatus } from '@/components/agent-presence';
import { AGENT_STATUS, type AgentStatus } from '@/components/agent-status';
import { AnimatedNumber } from '@/components/animated-number';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { StatusPill } from '@/components/status-pill';
import { useDebouncedReload, useEventFeed } from '@/hooks/use-event-feed';
import AppLayout from '@/layouts/app-layout';
import { describeEvent, isFeedEvent, relativeTime, type Tone } from '@/lib/events';
import { cn } from '@/lib/utils';
import { type AgentRole, type BreadcrumbItem, type StudioEvent } from '@/types';
import { Head, Link, router } from '@inertiajs/react';
import { ArrowDown, Clock3, FolderPlus, RadioTower, ShieldCheck, type LucideIcon } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';

interface Presence {
    id: number;
    name: string;
    role: AgentRole;
    status: PresenceStatus;
    enabled: boolean;
    task: { number: number; key: string; title: string } | null;
    activity: string | null;
    progress: PresenceProgress;
    model: string | null;
    attempt: number | null;
    max_attempts: number | null;
    tokens: number;
    token_budget: number;
    heartbeat_at: string | null;
    started_at: string | null;
}

interface Props {
    projects: { id: number; key: string; name: string; slug: string }[];
    project: { id: number; key: string; name: string; slug: string } | null;
    agents: Presence[];
    activity: StudioEvent[];
    metrics: {
        progress: number;
        tasks_done: number;
        tasks_total: number;
        active_runs: number;
        stalled_runs: number;
        tokens: number;
        token_budget: number;
    } | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'AI War Room', href: '/war-room' }];
const view: Record<PresenceStatus, { label: string; tone: Tone; icon: LucideIcon }> = {
    ...AGENT_STATUS,
    STALLED: { label: 'Stalled', tone: 'danger', icon: Clock3 },
};

export default function WarRoom({ projects, project, agents, activity, metrics }: Props) {
    // Short debounce: presence must follow events within the 2s SLO (§48.12), bursts still collapse.
    const reload = useDebouncedReload(['agents', 'metrics'], 150);
    // Persisted activity events carry the new state, message and counts: apply them to the
    // cards at once; the server reload that follows stays the authority (§48.6, G6).
    const [live, setLive] = useState(agents);
    useEffect(() => setLive(agents), [agents]);
    const events = useEventFeed(activity, {
        max: 100,
        filter: (event) => event.project_id === project?.id && isFeedEvent(event),
        onIncoming: (incoming) => {
            setLive((current) => applyAgentEvents(current, incoming));
            reload();
        },
    });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AI War Room" />
            <div className="flex flex-1 flex-col gap-5 p-4 md:p-6">
                <PageHeader
                    eyebrow="Realtime development operations"
                    title="AI War Room"
                    description="Every status, animation and activity line comes from persisted work. Reconnects replay the durable event stream before live updates continue."
                />

                {projects.length > 0 && (
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <label className="flex items-center gap-2 text-sm">
                            <span className="text-muted-foreground">Project</span>
                            <select
                                className="border-input bg-background h-9 rounded-md border px-3"
                                value={project?.slug ?? ''}
                                onChange={(e) => router.get(route('war-room'), { project: e.target.value }, { preserveState: false })}
                            >
                                {projects.map((item) => (
                                    <option key={item.id} value={item.slug}>
                                        {item.key} — {item.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                        {metrics && (
                            <p className="text-muted-foreground text-xs">
                                {metrics.tasks_done} of {metrics.tasks_total} tasks completed
                            </p>
                        )}
                    </div>
                )}

                {!project || !metrics ? (
                    <EmptyState
                        icon={FolderPlus}
                        title="No project to observe"
                        description="Connect a project first. The War Room never invents agent activity for an empty workspace."
                    />
                ) : (
                    <>
                        <section className="bg-card border-border rounded-lg border p-4">
                            <div className="grid gap-4 md:grid-cols-[minmax(15rem,1fr)_auto_auto_auto] md:items-center">
                                <div>
                                    <div className="mb-2 flex items-center justify-between text-xs">
                                        <span className="font-medium">Overall project progress</span>
                                        <span className="font-mono">{metrics.progress}%</span>
                                    </div>
                                    <div className="bg-muted h-2 overflow-hidden rounded-full">
                                        <div
                                            className="bg-brand-crimson h-full rounded-full transition-[width] duration-400"
                                            style={{ width: `${metrics.progress}%` }}
                                        />
                                    </div>
                                </div>
                                <Metric label="Working" value={metrics.active_runs} />
                                <Metric label="Stalled" value={metrics.stalled_runs} danger={metrics.stalled_runs > 0} />
                                <Metric
                                    label="Tokens"
                                    value={metrics.tokens}
                                    suffix={metrics.token_budget > 0 ? ` / ${metrics.token_budget.toLocaleString()}` : ''}
                                />
                            </div>
                        </section>

                        <div className="grid min-h-0 gap-4 xl:grid-cols-[minmax(0,1.65fr)_minmax(20rem,0.85fr)]">
                            <Panel title="AI team" action={<span className="text-muted-foreground text-xs">Working agents sort first</span>}>
                                <div className="grid gap-3 lg:grid-cols-2">
                                    {live.map((agent) => (
                                        <PresenceCard key={agent.id} agent={agent} projectSlug={project.slug} />
                                    ))}
                                </div>
                            </Panel>

                            <div className="flex min-w-0 flex-col gap-4">
                                <Panel title="Live activity" className="max-h-[42rem] overflow-hidden">
                                    {events.length ? (
                                        <LiveActivity events={events} />
                                    ) : (
                                        <EmptyState
                                            icon={RadioTower}
                                            title="Waiting for real activity"
                                            description="Persisted project events will appear here immediately. Nothing is simulated."
                                            className="py-8"
                                        />
                                    )}
                                </Panel>
                                <Panel title="Quality gates">
                                    <div className="flex items-start gap-3 text-sm">
                                        <ShieldCheck className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                                        <div>
                                            <p className="font-medium">No gate results yet</p>
                                            <p className="text-muted-foreground mt-1 text-xs">
                                                Automated tools arrive in Phase 7 and review gates in Phase 8. This panel stays empty until evidence
                                                exists.
                                            </p>
                                        </div>
                                    </div>
                                </Panel>
                            </div>
                        </div>
                    </>
                )}
            </div>
        </AppLayout>
    );
}

function Metric({ label, value, suffix = '', danger = false }: { label: string; value: number; suffix?: string; danger?: boolean }) {
    return (
        <div className="min-w-24">
            <p className="eyebrow">{label}</p>
            <p className={cn('text-heading mt-1 text-xl font-semibold', danger && 'text-danger')}>
                <AnimatedNumber value={value} />
                {suffix}
            </p>
        </div>
    );
}

type ActivityGroup = 'all' | 'agent' | 'task' | 'workflow' | 'runner' | 'errors';

function LiveActivity({ events }: { events: StudioEvent[] }) {
    const scroll = useRef<HTMLDivElement | null>(null);
    const newest = events.at(-1)?.id ?? 0;
    const [pausedAt, setPausedAt] = useState<number | null>(null);
    const [agent, setAgent] = useState('all');
    const [group, setGroup] = useState<ActivityGroup>('all');
    const pending = pausedAt === null ? 0 : events.filter((event) => event.id > pausedAt).length;
    const visible = pausedAt === null ? events : events.filter((event) => event.id <= pausedAt);
    const agents = useMemo(() => {
        const choices = new Map<string, string>();
        events.forEach((event) => {
            const value = event.payload.agent as { id?: number; name?: string; role?: string } | undefined;
            if (value?.id) choices.set(String(value.id), value.role ? `${value.role} · ${value.name ?? 'Agent'}` : (value.name ?? 'Agent'));
        });

        return [...choices.entries()];
    }, [events]);
    const filtered = visible.filter((event) => {
        const eventAgent = event.payload.agent as { id?: number } | undefined;
        if (agent !== 'all' && String(eventAgent?.id) !== agent) return false;
        if (group === 'errors') return describeEvent(event).tone === 'danger';
        if (group !== 'all') return event.type.startsWith(`${group}.`);

        return true;
    });

    const resume = () => {
        setPausedAt(null);
        scroll.current?.scrollTo({ top: 0, behavior: 'smooth' });
    };

    return (
        <div>
            <div className="mb-3 flex flex-wrap gap-2">
                <select
                    aria-label="Filter activity by agent"
                    className="border-input bg-background h-8 min-w-32 rounded-md border px-2 text-xs"
                    value={agent}
                    onChange={(event) => setAgent(event.target.value)}
                >
                    <option value="all">All agents</option>
                    {agents.map(([id, label]) => (
                        <option key={id} value={id}>
                            {label}
                        </option>
                    ))}
                </select>
                <select
                    aria-label="Filter activity by event type"
                    className="border-input bg-background h-8 min-w-32 rounded-md border px-2 text-xs"
                    value={group}
                    onChange={(event) => setGroup(event.target.value as ActivityGroup)}
                >
                    <option value="all">All events</option>
                    <option value="agent">Agent events</option>
                    <option value="task">Task events</option>
                    <option value="workflow">Workflow events</option>
                    <option value="runner">Runner events</option>
                    <option value="errors">Errors only</option>
                </select>
            </div>
            {pending > 0 && (
                <button
                    type="button"
                    onClick={resume}
                    className="bg-brand-crimson text-primary-foreground mx-auto mb-2 flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium shadow-sm"
                >
                    {pending} new {pending === 1 ? 'event' : 'events'} <ArrowDown className="size-3" />
                </button>
            )}
            <div
                ref={scroll}
                className="max-h-[32rem] overflow-y-auto"
                onScroll={(event) => {
                    if (event.currentTarget.scrollTop > 24 && pausedAt === null) setPausedAt(newest);
                    if (event.currentTarget.scrollTop <= 8 && pausedAt !== null && pending === 0) setPausedAt(null);
                }}
            >
                {filtered.length ? (
                    <ActivityTimeline events={filtered} showAgent absoluteTime />
                ) : (
                    <p className="text-muted-foreground py-8 text-center text-xs">No persisted events match these filters.</p>
                )}
            </div>
        </div>
    );
}

function PresenceCard({ agent, projectSlug }: { agent: Presence; projectSlug: string }) {
    const visible = useVisible();
    const [now, setNow] = useState(Date.now());
    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), 15000);
        return () => window.clearInterval(timer);
    }, []);
    // The server already marks STALLED; between reloads the client applies the same 90s rule (§48.5).
    const heartbeatAge = agent.heartbeat_at ? now - new Date(agent.heartbeat_at).getTime() : null;
    const status: PresenceStatus =
        heartbeatAge !== null && heartbeatAge > 90000 && AGENT_STATUS[agent.status as AgentStatus]?.active ? 'STALLED' : agent.status;
    const state = view[status];
    const active = status !== 'STALLED' && AGENT_STATUS[status].active;
    const animate = visible.current && active;
    const waiting = status === 'WAITING_FOR_TOOL' || status === 'WAITING_FOR_AGENT';
    const finished = ['COMPLETED', 'FAILED', 'CANCELLED'].includes(status);

    return (
        <article ref={visible.ref} className={cn('border-border bg-background min-w-0 rounded-md border p-3', active && 'brand-edge')}>
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 gap-3">
                    <AgentStatusRing role={agent.role} status={status} animate={visible.current} />
                    <div className="min-w-0">
                        <p className="truncate font-medium">{agent.name}</p>
                        {agent.task ? (
                            <Link
                                href={route('tasks.show', [projectSlug, agent.task.number])}
                                className="text-muted-foreground block truncate text-xs hover:underline"
                            >
                                {agent.task.key} · {agent.task.title}
                            </Link>
                        ) : (
                            <p className="text-muted-foreground text-xs">No run on this project</p>
                        )}
                    </div>
                </div>
                <StatusPill {...state} />
            </div>
            {!finished && agent.task && (
                <>
                    {agent.activity && (
                        <p className="text-muted-foreground mt-3 line-clamp-2 text-xs">
                            {agent.activity}
                            {(active || waiting) && <ActivityDots working={active} animate={animate} />}
                        </p>
                    )}
                    <AgentProgress status={status} progress={agent.progress} animate={animate} />
                    <div className="text-muted-foreground mt-3 grid grid-cols-3 gap-2 text-[0.68rem]">
                        <span className="truncate" title={agent.model ?? undefined}>
                            {agent.model ?? 'No model'}
                        </span>
                        <span className="text-center">{agent.attempt ? `Attempt ${agent.attempt}/${agent.max_attempts}` : '—'}</span>
                        <span className="text-right font-mono">
                            <AnimatedNumber value={agent.tokens} />/{agent.token_budget.toLocaleString()}
                        </span>
                    </div>
                    {agent.heartbeat_at && (
                        <p
                            className={cn('mt-2 text-[0.68rem]', status === 'STALLED' ? 'text-danger' : 'text-muted-foreground')}
                            title={new Date(agent.heartbeat_at).toLocaleString()}
                        >
                            {status === 'STALLED' ? 'No activity for over 90s — ' : 'Last event '}
                            {relativeTime(agent.heartbeat_at, now)}
                        </p>
                    )}
                </>
            )}
        </article>
    );
}
/** Fold persisted gent.status.changed events into the presence cards (newest wins). */
// The state each run lifecycle event leaves the agent in — mirrors AgentRunService / WarRoomController.
const LIFECYCLE_STATUS: Record<string, AgentStatus> = {
    'agent.run.started': 'INITIALIZING',
    'agent.run.attempt_started': 'INITIALIZING',
    'agent.run.resumed': 'INITIALIZING',
    'agent.run.retrying': 'QUEUED',
    'agent.run.budget_exhausted': 'BLOCKED',
    'agent.run.retry_limit_reached': 'BLOCKED',
    'agent.run.failed': 'FAILED',
    'agent.run.paused': 'PAUSED',
    'agent.run.cancelled': 'CANCELLED',
    'agent.run.completed': 'COMPLETED',
};

const NO_PROGRESS: PresenceProgress = { file: null, passed: null, failed: null, total: null };

/**
 * Folds persisted agent events into the cards the moment catch-up returns them, so a card never
 * contradicts the activity feed while the debounced reload (model, tokens, metrics) is in flight.
 */
function applyAgentEvents(current: Presence[], events: StudioEvent[]): Presence[] {
    const updates = new Map<number, StudioEvent[]>();
    events
        .filter((event) => event.type === 'agent.status.changed' || event.type in LIFECYCLE_STATUS)
        .forEach((event) => {
            const id = (event.payload.agent as { id?: number } | undefined)?.id;
            if (id) updates.set(id, [...(updates.get(id) ?? []), event]);
        });

    if (updates.size === 0) return current;

    return current.map((agent) => (updates.get(agent.id) ?? []).reduce(applyAgentEvent, agent));
}

function applyAgentEvent(agent: Presence, event: StudioEvent): Presence {
    const meta = (event.payload.metadata ?? {}) as Record<string, unknown>;
    const status =
        event.type === 'agent.status.changed'
            ? typeof meta.status === 'string' && meta.status in AGENT_STATUS
                ? (meta.status as AgentStatus)
                : null
            : LIFECYCLE_STATUS[event.type];

    if (!status) return agent;

    const task = event.payload.task as { key?: string; number?: number; title?: string } | undefined;

    return {
        ...agent,
        status,
        task:
            task?.key && task.number !== undefined && task.title !== undefined
                ? { key: task.key, number: task.number, title: task.title }
                : agent.task,
        activity: typeof event.payload.message === 'string' ? event.payload.message : agent.activity,
        heartbeat_at: event.occurred_at,
        started_at: event.type === 'agent.run.started' ? event.occurred_at : agent.started_at,
        progress:
            event.type === 'agent.status.changed'
                ? {
                      file: typeof meta.file === 'string' ? meta.file : null,
                      passed: typeof meta.passed === 'number' ? meta.passed : null,
                      failed: typeof meta.failed === 'number' ? meta.failed : null,
                      total: typeof meta.total === 'number' ? meta.total : null,
                  }
                : NO_PROGRESS,
    };
}

function useVisible() {
    const ref = useRef<HTMLElement | null>(null);
    const [current, setCurrent] = useState(true);
    useEffect(() => {
        if (!ref.current || !('IntersectionObserver' in window)) return;
        const observer = new IntersectionObserver(([entry]) => setCurrent(entry.isIntersecting));
        observer.observe(ref.current);
        return () => observer.disconnect();
    }, []);

    return { ref, current };
}
