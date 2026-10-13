import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { useStudioEvents } from '@/components/realtime/realtime-provider';
import { ReasonDialog } from '@/components/reason-dialog';
import { PriorityBadge, STATUS, TaskStatusBadge, TypeLabel } from '@/components/task-badges';
import { TaskFormDialog } from '@/components/task-form-dialog';
import { AiPlanPanel, type AiPlan } from '@/components/task/ai-plan-panel';
import { ImpactPanel } from '@/components/task/impact-panel';
import { TaskConversation } from '@/components/task/task-conversation';
import { Button } from '@/components/ui/button';
import { useDebouncedReload } from '@/hooks/use-event-feed';
import AppLayout from '@/layouts/app-layout';
import { relativeTime } from '@/lib/events';
import { type BreadcrumbItem, type TaskDetail, type TaskOptions, type TaskStatus } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Bot, CheckCircle2, FolderGit2, Link2, LoaderCircle, Lock, Pencil, RefreshCcw, X, XCircle } from 'lucide-react';
import { useState } from 'react';

interface TaskPageProps {
    project: { id: number; key: string; name: string; slug: string };
    task: TaskDetail;
    options: TaskOptions;
    candidates: { number: number; key: string; title: string; status: TaskStatus }[];
    aiPlan: AiPlan | null;
    workspaceReady: { runner: boolean; git: boolean };
    can: { manage: boolean; approve: boolean };
}

/** Wording for the actions a person can take on a task (Plan §95). */
const ACTION: Partial<Record<TaskStatus, string>> = {
    BACKLOG: 'Move to Backlog',
    READY: 'Mark ready',
    BLOCKED: 'Block…',
    CANCELLED: 'Cancel task…',
    CHANGES_REQUESTED: 'Request changes…',
    DONE: 'Approve',
};

export default function TaskPage({ project, task, options, candidates, aiPlan, workspaceReady, can }: TaskPageProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const [editing, setEditing] = useState(false);
    const [asking, setAsking] = useState<TaskStatus | null>(null);
    const [busy, setBusy] = useState(false);

    const reload = useDebouncedReload(['task', 'candidates', 'aiPlan'], 300);
    useStudioEvents((events) => {
        if (
            events.some(
                (e) =>
                    e.project_id === project.id && ['task.', 'workspace.', 'agent.', 'workflow.', 'ai.'].some((prefix) => e.type.startsWith(prefix)),
            )
        )
            reload();
    });

    const transition = (to: TaskStatus, reason?: string) => {
        setBusy(true);
        router.post(
            route('tasks.transition', [project.slug, task.number]),
            { to, from: task.status, reason: reason ?? null },
            { preserveScroll: true, onFinish: () => (setBusy(false), setAsking(null)) },
        );
    };

    const targets = task.user_targets.filter((t) => t !== 'DONE' || can.approve);
    // A task blocked out of agent work is released to Ready (nothing would be running otherwise).
    const unblockTo = task.status === 'BLOCKED' ? (targets.find((t) => t === task.blocked_from) ?? targets.find((t) => t === 'READY') ?? null) : null;

    const startAi = () => {
        setBusy(true);
        router.post(route('tasks.ai', [project.slug, task.number]), {}, { preserveScroll: true, onFinish: () => setBusy(false) });
    };

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Tasks', href: `/tasks?project=${project.slug}` },
        { title: task.key, href: `/projects/${project.slug}/tasks/${task.number}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${task.key} · ${task.title}`} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    eyebrow={`${project.name} · ${task.key}`}
                    title={task.title}
                    description={
                        <span className="flex flex-wrap items-center gap-3">
                            <TaskStatusBadge status={task.status} />
                            <PriorityBadge priority={task.priority} />
                            <TypeLabel type={task.type} />
                        </span>
                    }
                    actions={
                        can.manage && (
                            <div className="flex flex-wrap justify-end gap-2">
                                {task.status === 'READY' && (
                                    <Button size="sm" disabled={busy} onClick={startAi}>
                                        <Bot />
                                        Plan with AI team
                                    </Button>
                                )}
                                {targets.map((to) => {
                                    const needsReason = to === 'BLOCKED' || to === 'CHANGES_REQUESTED' || to === 'CANCELLED';
                                    const label = to === unblockTo ? `Unblock → ${STATUS[to].label}` : (ACTION[to] ?? STATUS[to].label);

                                    return (
                                        <Button
                                            key={to}
                                            size="sm"
                                            variant={to === 'DONE' || to === unblockTo || to === 'READY' ? 'default' : 'outline'}
                                            className={to === 'CANCELLED' ? 'text-muted-foreground hover:text-danger' : undefined}
                                            disabled={busy}
                                            onClick={() => (needsReason ? setAsking(to) : transition(to))}
                                        >
                                            {to === 'DONE' && <CheckCircle2 />}
                                            {label}
                                        </Button>
                                    );
                                })}
                                <Button size="sm" variant="ghost" onClick={() => setEditing(true)}>
                                    <Pencil />
                                    Edit
                                </Button>
                            </div>
                        )
                    }
                />

                {(errors.task || errors.workspace || errors.ai) && (
                    <div role="alert" className="border-danger/30 bg-danger/10 flex items-start gap-2 rounded-md border px-3 py-2 text-sm">
                        <XCircle className="text-danger mt-0.5 size-4 shrink-0" />
                        {errors.task ?? errors.workspace ?? errors.ai}
                    </div>
                )}

                {task.status === 'BLOCKED' && task.blocked_reason && (
                    <div className="border-warning/30 bg-warning/10 rounded-md border px-3 py-2 text-sm">
                        <span className="font-medium">Blocked:</span> {task.blocked_reason}
                    </div>
                )}

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <div className="flex min-w-0 flex-col gap-4 lg:col-span-2">
                        <Panel title="Request">
                            {task.description ? (
                                <p className="text-foreground text-sm whitespace-pre-wrap">{task.description}</p>
                            ) : (
                                <p className="text-muted-foreground text-sm">No description.</p>
                            )}
                            {task.acceptance_criteria.length > 0 && (
                                <div className="mt-4">
                                    <p className="eyebrow mb-2">Acceptance criteria</p>
                                    <ul className="space-y-1.5">
                                        {task.acceptance_criteria.map((c, i) => (
                                            <li key={i} className="flex items-start gap-2 text-sm">
                                                <CheckCircle2 className="text-muted-foreground mt-0.5 size-4 shrink-0" />
                                                {c}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </Panel>

                        <Panel
                            title="Change impact"
                            action={
                                can.manage && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => router.post(route('tasks.impact', [project.slug, task.number]), {}, { preserveScroll: true })}
                                    >
                                        <RefreshCcw />
                                        Re-run
                                    </Button>
                                )
                            }
                        >
                            {task.impact ? (
                                <ImpactPanel impact={task.impact} analyzedAt={task.impact_analyzed_at} />
                            ) : (
                                <p className="text-muted-foreground text-sm">Not analyzed yet.</p>
                            )}
                        </Panel>

                        {aiPlan && (
                            <Panel title="AI plan">
                                <AiPlanPanel plan={aiPlan} />
                            </Panel>
                        )}

                        <Panel title="Conversation">
                            <TaskConversation task={task} projectSlug={project.slug} canManage={can.manage} />
                        </Panel>
                    </div>

                    <div className="flex flex-col gap-4">
                        <Panel title="Details">
                            <dl className="grid grid-cols-[7rem_1fr] gap-y-2 text-sm">
                                <dt className="text-muted-foreground">Created</dt>
                                <dd className="text-heading">
                                    {task.created_by ?? '—'}
                                    {task.created_at && <span className="text-muted-foreground"> · {relativeTime(task.created_at)}</span>}
                                </dd>
                                <dt className="text-muted-foreground">Started</dt>
                                <dd className="text-heading">{task.started_at ? relativeTime(task.started_at) : 'Not yet'}</dd>
                                <dt className="text-muted-foreground">Completed</dt>
                                <dd className="text-heading">{task.completed_at ? relativeTime(task.completed_at) : '—'}</dd>
                                <dt className="text-muted-foreground">Token budget</dt>
                                <dd className="text-heading tabular">
                                    {task.token_budget ? `≈ ${task.token_budget.toLocaleString()}` : '—'}
                                    <span className="text-muted-foreground block text-xs">
                                        From the impact estimate. AI runs stop when it is reached.
                                    </span>
                                </dd>
                            </dl>
                        </Panel>

                        <Panel title="Dependencies">
                            <Dependencies
                                task={task}
                                projectSlug={project.slug}
                                candidates={candidates}
                                canManage={can.manage}
                                error={errors.dependency}
                            />
                        </Panel>

                        <Panel title="Workspace">
                            <WorkspaceBox task={task} projectSlug={project.slug} ready={workspaceReady} canManage={can.manage} />
                        </Panel>

                        <Panel title="History">
                            <ol className="space-y-2.5">
                                {[...task.transitions].reverse().map((t) => (
                                    <li key={t.id} className="text-xs">
                                        <p className="text-heading">
                                            {t.from ? (
                                                <>
                                                    {STATUS[t.from].label} → <span className="font-medium">{STATUS[t.to].label}</span>
                                                </>
                                            ) : (
                                                <>Created in {STATUS[t.to].label}</>
                                            )}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {t.actor === 'system' ? 'AI team' : (t.user ?? 'Someone')}
                                            {t.created_at && ` · ${relativeTime(t.created_at)}`}
                                            {t.reason && t.reason !== 'Created' && ` · “${t.reason}”`}
                                        </p>
                                    </li>
                                ))}
                            </ol>
                        </Panel>
                    </div>
                </div>
            </div>

            {can.manage && <TaskFormDialog open={editing} onOpenChange={setEditing} projectSlug={project.slug} options={options} task={task} />}

            <ReasonDialog
                open={asking !== null}
                onOpenChange={(open) => !open && setAsking(null)}
                title={
                    asking === 'BLOCKED' ? `Block ${task.key}?` : asking === 'CANCELLED' ? `Cancel ${task.key}?` : `Request changes on ${task.key}?`
                }
                description={
                    asking === 'BLOCKED'
                        ? `It stops in ${STATUS[task.status].label}; unblocking returns it there.`
                        : asking === 'CANCELLED'
                          ? 'The task leaves the board. Its history, comments and any workspace branch are kept, and it can be reopened.'
                          : 'Your feedback is kept on the task for the specialist who picks it up.'
                }
                label={asking === 'BLOCKED' ? 'Why is it blocked?' : asking === 'CANCELLED' ? 'Reason (optional)' : 'What needs to change?'}
                confirm={asking === 'BLOCKED' ? 'Block task' : asking === 'CANCELLED' ? 'Cancel task' : 'Request changes'}
                required={asking !== 'CANCELLED'}
                destructive={asking === 'CANCELLED'}
                processing={busy}
                onConfirm={(reason) => asking && transition(asking, reason || undefined)}
            />
        </AppLayout>
    );
}

function Dependencies({
    task,
    projectSlug,
    candidates,
    canManage,
    error,
}: {
    task: TaskDetail;
    projectSlug: string;
    candidates: TaskPageProps['candidates'];
    canManage: boolean;
    error?: string;
}) {
    const { data, setData, post, processing } = useForm<{ number: string }>({ number: '' });
    const existing = new Set(task.dependencies.map((d) => d.number));
    const options = candidates.filter((c) => !existing.has(c.number));

    return (
        <div className="space-y-3 text-sm">
            <div>
                <p className="eyebrow mb-1.5">Waits for</p>
                {task.dependencies.length ? (
                    <ul className="space-y-1.5">
                        {task.dependencies.map((d) => (
                            <li key={d.id} className="flex items-center justify-between gap-2">
                                <Link href={route('tasks.show', [projectSlug, d.number])} className="min-w-0 hover:underline">
                                    <span className="text-muted-foreground font-mono text-xs">{d.key}</span>{' '}
                                    <span className="text-heading">{d.title}</span>
                                </Link>
                                <span className="flex shrink-0 items-center gap-1">
                                    {d.status === 'DONE' ? (
                                        <CheckCircle2 className="text-success size-3.5" aria-label="Done" />
                                    ) : (
                                        <Lock className="text-warning size-3.5" aria-label="Open" />
                                    )}
                                    {canManage && (
                                        <button
                                            type="button"
                                            aria-label={`Remove dependency on ${d.key}`}
                                            className="text-muted-foreground hover:text-danger"
                                            onClick={() =>
                                                router.delete(route('tasks.dependencies.destroy', [projectSlug, task.number, d.number]), {
                                                    preserveScroll: true,
                                                })
                                            }
                                        >
                                            <X className="size-3.5" />
                                        </button>
                                    )}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-muted-foreground text-xs">Nothing — it can start once it's Ready.</p>
                )}
            </div>

            {task.dependents.length > 0 && (
                <div>
                    <p className="eyebrow mb-1.5">Blocks</p>
                    <ul className="space-y-1">
                        {task.dependents.map((d) => (
                            <li key={d.id}>
                                <Link href={route('tasks.show', [projectSlug, d.number])} className="hover:underline">
                                    <span className="text-muted-foreground font-mono text-xs">{d.key}</span>{' '}
                                    <span className="text-heading">{d.title}</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {canManage && options.length > 0 && (
                <form
                    className="flex gap-2"
                    onSubmit={(e) => {
                        e.preventDefault();
                        post(route('tasks.dependencies.store', [projectSlug, task.number]), {
                            preserveScroll: true,
                            onSuccess: () => setData('number', ''),
                        });
                    }}
                >
                    <select
                        aria-label="Add a dependency"
                        value={data.number}
                        onChange={(e) => setData('number', e.target.value)}
                        className="border-input bg-background h-8 min-w-0 flex-1 rounded-md border px-2 text-xs"
                    >
                        <option value="">Waits for…</option>
                        {options.map((c) => (
                            <option key={c.number} value={c.number}>
                                {c.key} · {c.title}
                            </option>
                        ))}
                    </select>
                    <Button size="sm" variant="outline" disabled={processing || !data.number}>
                        <Link2 />
                        Add
                    </Button>
                </form>
            )}
            {error && <p className="text-danger text-xs">{error}</p>}
        </div>
    );
}

function WorkspaceBox({
    task,
    projectSlug,
    ready,
    canManage,
}: {
    task: TaskDetail;
    projectSlug: string;
    ready: TaskPageProps['workspaceReady'];
    canManage: boolean;
}) {
    const { post, processing } = useForm({});
    const ws = task.workspace;

    if (ws && ws.status !== 'removed' && ws.status !== 'failed') {
        return (
            <div className="space-y-1 text-sm">
                <p className="text-heading flex items-center gap-1.5 font-mono text-xs">
                    <FolderGit2 className="text-muted-foreground size-3.5" />
                    {ws.branch}
                </p>
                <p className="text-muted-foreground text-xs capitalize">
                    {ws.status === 'creating' ? 'Being created on the runner…' : ws.status}
                    {ws.base_sha && ` · from ${ws.base_branch ?? 'HEAD'} ${ws.base_sha.slice(0, 7)}`}
                </p>
                {ws.path && <p className="text-muted-foreground truncate font-mono text-[0.6875rem]">{ws.path}</p>}
                {ws.last_checkpoint_sha && <p className="text-muted-foreground text-xs">Last checkpoint {ws.last_checkpoint_sha.slice(0, 7)}</p>}
            </div>
        );
    }

    const blocker = !ready.runner
        ? 'Assign a runner to the project first.'
        : !ready.git
          ? 'Initialize Git for the project first.'
          : task.status !== 'READY'
            ? 'Available once the task is Ready.'
            : null;

    return (
        <div className="space-y-2 text-sm">
            <p className="text-muted-foreground text-xs">
                Each task gets its own Git worktree on <code className="font-mono">ai/{task.key}-…</code>; the original folder is never edited.
            </p>
            {ws?.status === 'failed' && ws.error && <p className="text-danger text-xs">{ws.error}</p>}
            {canManage && (
                <>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={processing || blocker !== null}
                        onClick={() => post(route('tasks.workspace', [projectSlug, task.number]), { preserveScroll: true })}
                    >
                        {processing ? <LoaderCircle className="animate-spin" /> : <FolderGit2 />}
                        {ws?.status === 'failed' ? 'Retry workspace' : 'Prepare workspace'}
                    </Button>
                    {blocker && <p className="text-muted-foreground text-xs">{blocker}</p>}
                </>
            )}
        </div>
    );
}
