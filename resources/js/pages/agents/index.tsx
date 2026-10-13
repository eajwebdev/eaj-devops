import { AgentAvatar } from '@/components/agent-avatar';
import { AGENT_STATUS, type AgentStatus } from '@/components/agent-status';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { StatusPill } from '@/components/status-pill';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type AgentRole, type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Bot, CheckCircle2, Clock3, PauseCircle, PlayCircle, Plus, RefreshCw, Route, ShieldCheck, XCircle } from 'lucide-react';
import { type FormEvent } from 'react';

type RunStatus = 'PENDING' | 'RUNNING' | 'RETRYING' | 'PAUSED' | 'BLOCKED' | 'COMPLETED' | 'FAILED' | 'CANCELLED';

interface Run {
    id: string;
    agent: string;
    role: AgentRole;
    task_key: string;
    task_title: string;
    project_slug: string;
    status: RunStatus;
    purpose: string;
    attempt: number;
    max_attempts: number;
    total_tokens: number;
    token_budget: number;
    model: string | null;
    started_at: string | null;
    finished_at: string | null;
}

interface AgentDefinition {
    id: number;
    name: string;
    slug: string;
    role: { key: AgentRole; name: string; description: string; responsibilities: string[]; may_implement: boolean };
    status: AgentStatus;
    token_budget: number;
    retry_limit: number;
    max_wall_seconds: number;
    enabled: boolean;
    version: number;
    skills: { id: number; name: string; version: string | null; enabled: boolean }[];
    last_run: Run | null;
}

interface ModelDefinition {
    id: number;
    provider: string;
    model_key: string;
    display_name: string;
    tier: string;
    max_context: number | null;
    enabled: boolean;
}

interface ModelRouteDefinition {
    id: number;
    route_key: string;
    role: AgentRole | null;
    role_name: string;
    purpose: string;
    tier: string;
    primary_model_id: number | null;
    primary_model: string | null;
    fallback_model_id: number | null;
    fallback_model: string | null;
    enabled: boolean;
    version: number;
}

interface Props {
    agents: AgentDefinition[];
    routes: ModelRouteDefinition[];
    models: ModelDefinition[];
    runs: Run[];
    tiers: { value: string; label: string }[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'AI Team', href: '/ai-team' }];

const statusView = AGENT_STATUS;

const runView: Record<RunStatus, { tone: 'neutral' | 'brand' | 'success' | 'warning' | 'danger'; icon: typeof Clock3; label: string }> = {
    PENDING: { tone: 'neutral', icon: Clock3, label: 'Pending' },
    RUNNING: { tone: 'brand', icon: PlayCircle, label: 'Running' },
    RETRYING: { tone: 'warning', icon: RefreshCw, label: 'Retrying' },
    PAUSED: { tone: 'warning', icon: PauseCircle, label: 'Paused' },
    BLOCKED: { tone: 'danger', icon: AlertTriangle, label: 'Blocked' },
    COMPLETED: { tone: 'success', icon: CheckCircle2, label: 'Completed' },
    FAILED: { tone: 'danger', icon: XCircle, label: 'Failed' },
    CANCELLED: { tone: 'neutral', icon: XCircle, label: 'Cancelled' },
};

function formatNumber(value: number) {
    return new Intl.NumberFormat().format(value);
}

function AgentCard({ agent, canManage }: { agent: AgentDefinition; canManage: boolean }) {
    const state = statusView[agent.status];
    const form = useForm({ version: agent.version, token_budget: agent.token_budget, retry_limit: agent.retry_limit, enabled: agent.enabled });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(route('agents.update', agent.id), { preserveScroll: true });
    }

    return (
        <article className="border-border bg-card flex min-w-0 flex-col rounded-lg border p-4">
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    <AgentAvatar role={agent.role.key} />
                    <div className="min-w-0">
                        <h2 className="truncate font-semibold">{agent.name}</h2>
                        <p className="text-muted-foreground mt-1 text-xs leading-relaxed">{agent.role.description}</p>
                    </div>
                </div>
                <StatusPill {...state} />
            </div>

            <ul className="text-muted-foreground mt-4 space-y-1 text-xs">
                {agent.role.responsibilities.slice(0, 3).map((item) => (
                    <li key={item} className="flex gap-2">
                        <span aria-hidden="true">•</span>
                        <span>{item}</span>
                    </li>
                ))}
            </ul>

            <div className="mt-4 flex flex-wrap gap-2">
                {agent.skills.map((skill) => (
                    <button
                        key={skill.id}
                        type="button"
                        disabled={!canManage}
                        onClick={() => router.patch(route('skills.update', skill.id), { enabled: !skill.enabled }, { preserveScroll: true })}
                        className={`rounded-full px-2 py-1 text-[0.68rem] font-medium ring-1 ring-inset ${skill.enabled ? 'bg-navy-50 text-navy-700 ring-navy-200 dark:bg-navy-800 dark:text-navy-100 dark:ring-navy-600' : 'bg-muted text-muted-foreground ring-border line-through'}`}
                        title={canManage ? 'Toggle skill availability' : undefined}
                    >
                        {skill.name} {skill.version && `v${skill.version}`}
                    </button>
                ))}
            </div>

            <form onSubmit={submit} className="border-border mt-4 grid grid-cols-2 gap-3 border-t pt-4">
                <div className="space-y-1">
                    <Label htmlFor={`budget-${agent.id}`} className="text-xs">
                        Run token cap
                    </Label>
                    <Input
                        id={`budget-${agent.id}`}
                        type="number"
                        min={100}
                        value={form.data.token_budget}
                        disabled={!canManage}
                        onChange={(e) => form.setData('token_budget', Number(e.target.value))}
                    />
                </div>
                <div className="space-y-1">
                    <Label htmlFor={`retries-${agent.id}`} className="text-xs">
                        Max attempts
                    </Label>
                    <Input
                        id={`retries-${agent.id}`}
                        type="number"
                        min={1}
                        max={10}
                        value={form.data.retry_limit}
                        disabled={!canManage}
                        onChange={(e) => form.setData('retry_limit', Number(e.target.value))}
                    />
                </div>
                <label className="text-muted-foreground flex items-center gap-2 text-xs">
                    <input
                        type="checkbox"
                        checked={form.data.enabled}
                        disabled={!canManage}
                        onChange={(e) => form.setData('enabled', e.target.checked)}
                    />{' '}
                    Enabled
                </label>
                {canManage && (
                    <Button type="submit" size="sm" disabled={form.processing || !form.isDirty}>
                        Save limits
                    </Button>
                )}
            </form>
        </article>
    );
}

function RouteRow({
    item,
    models,
    tiers,
    canManage,
}: {
    item: ModelRouteDefinition;
    models: ModelDefinition[];
    tiers: Props['tiers'];
    canManage: boolean;
}) {
    const form = useForm({
        version: item.version,
        tier: item.tier,
        primary_model_id: item.primary_model_id as number | null,
        fallback_model_id: item.fallback_model_id as number | null,
        enabled: item.enabled,
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.patch(route('model-routes.update', item.id), { preserveScroll: true });
    }

    return (
        <form
            onSubmit={submit}
            className="border-border grid gap-3 border-b py-3 last:border-0 md:grid-cols-[minmax(12rem,1fr)_10rem_minmax(10rem,1fr)_minmax(10rem,1fr)_auto] md:items-end"
        >
            <div>
                <div className="flex items-center gap-2">
                    {item.role && <AgentAvatar role={item.role} size="sm" />}
                    <div>
                        <p className="text-sm font-medium">{item.role_name}</p>
                        <p className="text-muted-foreground font-mono text-[0.68rem]">{item.route_key}</p>
                    </div>
                </div>
            </div>
            <label className="space-y-1 text-xs">
                <span className="text-muted-foreground">Tier</span>
                <select
                    className="border-input bg-background h-9 w-full rounded-md border px-2"
                    value={form.data.tier}
                    disabled={!canManage}
                    onChange={(e) => form.setData('tier', e.target.value)}
                >
                    {tiers.map((tier) => (
                        <option key={tier.value} value={tier.value}>
                            {tier.label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="space-y-1 text-xs">
                <span className="text-muted-foreground">Primary</span>
                <select
                    className="border-input bg-background h-9 w-full rounded-md border px-2"
                    value={form.data.primary_model_id ?? ''}
                    disabled={!canManage}
                    onChange={(e) => form.setData('primary_model_id', e.target.value ? Number(e.target.value) : null)}
                >
                    <option value="">Auto / unconfigured</option>
                    {models.map((model) => (
                        <option key={model.id} value={model.id}>
                            {model.display_name}
                        </option>
                    ))}
                </select>
            </label>
            <label className="space-y-1 text-xs">
                <span className="text-muted-foreground">Fallback</span>
                <select
                    className="border-input bg-background h-9 w-full rounded-md border px-2"
                    value={form.data.fallback_model_id ?? ''}
                    disabled={!canManage}
                    onChange={(e) => form.setData('fallback_model_id', e.target.value ? Number(e.target.value) : null)}
                >
                    <option value="">Auto / none</option>
                    {models
                        .filter((model) => model.id !== form.data.primary_model_id)
                        .map((model) => (
                            <option key={model.id} value={model.id}>
                                {model.display_name}
                            </option>
                        ))}
                </select>
            </label>
            {canManage && (
                <Button type="submit" size="sm" disabled={form.processing || !form.isDirty}>
                    Save
                </Button>
            )}
        </form>
    );
}

function NewModel({ tiers }: { tiers: Props['tiers'] }) {
    const form = useForm({ provider: '', model_key: '', display_name: '', tier: 'standard', max_context: null as number | null });
    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('models.store'), { preserveScroll: true, onSuccess: () => form.reset() });
    }
    return (
        <form onSubmit={submit} className="bg-muted/40 border-border mt-4 grid gap-3 rounded-md border p-3 md:grid-cols-5 md:items-end">
            <label className="space-y-1 text-xs">
                <span>Provider</span>
                <Input value={form.data.provider} onChange={(e) => form.setData('provider', e.target.value)} placeholder="Provider key" required />
            </label>
            <label className="space-y-1 text-xs">
                <span>Model key</span>
                <Input
                    value={form.data.model_key}
                    onChange={(e) => form.setData('model_key', e.target.value)}
                    placeholder="API model identifier"
                    required
                />
            </label>
            <label className="space-y-1 text-xs">
                <span>Display name</span>
                <Input
                    value={form.data.display_name}
                    onChange={(e) => form.setData('display_name', e.target.value)}
                    placeholder="Engineering model"
                    required
                />
            </label>
            <label className="space-y-1 text-xs">
                <span>Tier</span>
                <select
                    className="border-input bg-background h-10 w-full rounded-md border px-2"
                    value={form.data.tier}
                    onChange={(e) => form.setData('tier', e.target.value)}
                >
                    {tiers.map((tier) => (
                        <option key={tier.value} value={tier.value}>
                            {tier.label}
                        </option>
                    ))}
                </select>
            </label>
            <Button type="submit" disabled={form.processing}>
                <Plus /> Add model
            </Button>
        </form>
    );
}

export default function AgentTeam({ agents, routes, models, runs, tiers, can }: Props) {
    const { organization } = usePage<SharedData>().props;
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="AI Team" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    eyebrow={organization?.name}
                    title="AI Team"
                    description="Versioned specialist definitions, relevant skills, model routes and real run evidence. Provider calls are connected in the AI Integration phase."
                />

                <div className="grid gap-4 lg:grid-cols-2 2xl:grid-cols-3">
                    {agents.map((agent) => (
                        <AgentCard key={agent.id} agent={agent} canManage={can.manage} />
                    ))}
                </div>

                <Panel
                    title="Model routing"
                    action={
                        <span className="text-muted-foreground flex items-center gap-1 text-xs">
                            <Route className="size-3.5" /> Provider-neutral
                        </span>
                    }
                >
                    {routes.map((item) => (
                        <RouteRow key={item.id} item={item} models={models} tiers={tiers} canManage={can.manage} />
                    ))}
                    {can.manage && <NewModel tiers={tiers} />}
                </Panel>

                <Panel title="Recent agent runs">
                    {runs.length === 0 ? (
                        <EmptyState
                            icon={Bot}
                            title="No agent runs yet"
                            description="The ledger is ready. Runs will appear when a task workflow invokes a specialist; no simulated activity is shown."
                        />
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[760px] text-left text-sm">
                                <thead className="text-muted-foreground border-border border-b text-xs">
                                    <tr>
                                        <th className="py-2 pr-3 font-medium">Agent</th>
                                        <th className="px-3 py-2 font-medium">Task</th>
                                        <th className="px-3 py-2 font-medium">Status</th>
                                        <th className="px-3 py-2 font-medium">Model</th>
                                        <th className="px-3 py-2 text-right font-medium">Attempt</th>
                                        <th className="py-2 pl-3 text-right font-medium">Tokens</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {runs.map((run) => {
                                        const state = runView[run.status];
                                        return (
                                            <tr key={run.id} className="border-border border-b last:border-0">
                                                <td className="py-3 pr-3">
                                                    <div className="flex items-center gap-2">
                                                        <AgentAvatar role={run.role} size="sm" />
                                                        <span>{run.agent}</span>
                                                    </div>
                                                </td>
                                                <td className="px-3 py-3">
                                                    <Link
                                                        className="hover:text-brand-crimson font-medium"
                                                        href={route('tasks.show', [run.project_slug, run.task_key.split('-').pop()])}
                                                    >
                                                        {run.task_key}
                                                    </Link>
                                                    <p className="text-muted-foreground max-w-60 truncate text-xs">{run.task_title}</p>
                                                </td>
                                                <td className="px-3 py-3">
                                                    <StatusPill {...state} />
                                                </td>
                                                <td className="text-muted-foreground px-3 py-3">{run.model ?? 'Not configured'}</td>
                                                <td className="px-3 py-3 text-right font-mono">
                                                    {run.attempt}/{run.max_attempts}
                                                </td>
                                                <td className="py-3 pl-3 text-right font-mono">
                                                    {formatNumber(run.total_tokens)} / {formatNumber(run.token_budget)}
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Panel>

                <div className="text-muted-foreground flex items-start gap-2 text-xs">
                    <ShieldCheck className="text-success mt-0.5 size-4 shrink-0" />
                    <p>
                        Runs, token usage and agent events are append-only evidence. Retry and token limits are enforced by application code, never by
                        an agent prompt.
                    </p>
                </div>
            </div>
        </AppLayout>
    );
}
