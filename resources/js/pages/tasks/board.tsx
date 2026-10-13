import { AgentAvatar } from '@/components/agent-avatar';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { useStudioEvents } from '@/components/realtime/realtime-provider';
import { ReasonDialog } from '@/components/reason-dialog';
import { PriorityBadge, STATUS } from '@/components/task-badges';
import { TaskFormDialog } from '@/components/task-form-dialog';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { useDebouncedReload } from '@/hooks/use-event-feed';
import { useReducedMotion } from '@/hooks/use-reduce-motion';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type TaskCard, type TaskOptions, type TaskPriority, type TaskStatus } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Bot, FolderGit2, FolderPlus, Lock, MessageSquare, MoreHorizontal, Plus, Search, SquareKanban, XCircle } from 'lucide-react';
import { LayoutGroup, motion } from 'motion/react';
import { useEffect, useMemo, useRef, useState, type DragEvent } from 'react';

interface BoardProps {
    projects: { id: number; key: string; name: string; slug: string }[];
    project: { id: number; key: string; name: string; slug: string } | null;
    columns: { value: TaskStatus; label: string; agent_only: boolean }[];
    tasks: TaskCard[];
    options: TaskOptions;
    can: { manage: boolean; approve: boolean };
}

type PendingMove = { task: TaskCard; to: TaskStatus; afterId: number | null; beforeId: number | null };

const REASON_TARGETS: TaskStatus[] = ['BLOCKED', 'CHANGES_REQUESTED'];

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tasks', href: '/tasks' }];

export default function Board({ projects, project, columns, tasks: serverTasks, options, can }: BoardProps) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const reduced = useReducedMotion();
    const [tasks, setTasks] = useState(serverTasks);
    const [query, setQuery] = useState('');
    const [priority, setPriority] = useState<TaskPriority | ''>('');
    const [creating, setCreating] = useState(false);
    const [dragging, setDragging] = useState<TaskCard | null>(null);
    const [over, setOver] = useState<{ status: TaskStatus; index: number } | null>(null);
    const [pending, setPending] = useState<PendingMove | null>(null);
    const [moving, setMoving] = useState(false);
    const columnRefs = useRef(new Map<TaskStatus, HTMLDivElement>());

    useEffect(() => setTasks(serverTasks), [serverTasks]);

    // Other people's (and later the AI team's) moves arrive as persisted events.
    const reload = useDebouncedReload(['tasks'], 300);
    useStudioEvents((events) => {
        if (project && events.some((e) => e.project_id === project.id && e.type.startsWith('task.'))) reload();
    });

    const byColumn = useMemo(() => {
        const q = query.trim().toLowerCase();
        const visible = tasks.filter(
            (t) => (!priority || t.priority === priority) && (!q || t.title.toLowerCase().includes(q) || t.key.toLowerCase().includes(q)),
        );
        const map = new Map<TaskStatus, TaskCard[]>(columns.map((c) => [c.value, []]));
        visible.forEach((t) => map.get(t.status)?.push(t));
        map.forEach((list) => list.sort((a, b) => a.position - b.position));

        return map;
    }, [tasks, columns, query, priority]);

    const canDrop = (task: TaskCard, to: TaskStatus) => to === task.status || task.user_targets.includes(to);

    const commit = ({ task, to, afterId, beforeId }: PendingMove, reason?: string) => {
        const list = (byColumn.get(to) ?? []).filter((t) => t.id !== task.id);
        const after = list.find((t) => t.id === afterId)?.position;
        const before = list.find((t) => t.id === beforeId)?.position;
        const position =
            after !== undefined && before !== undefined
                ? (after + before) / 2
                : after !== undefined
                  ? after + 1024
                  : before !== undefined
                    ? before - 1024
                    : 1024;

        // Optimistic (Plan §92 "only where safe"): the server re-checks and we roll back on refusal.
        setTasks((current) => current.map((t) => (t.id === task.id ? { ...t, status: to, position } : t)));
        setMoving(true);

        router.post(
            route('tasks.move', [project!.slug, task.number]),
            { to, from: task.status, after_id: afterId, before_id: beforeId, reason: reason ?? null },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['tasks', 'flash', 'errors'],
                onError: () => setTasks(serverTasks),
                onFinish: () => {
                    setMoving(false);
                    setPending(null);
                },
            },
        );
    };

    const request = (move: PendingMove) => {
        if (move.to !== move.task.status && REASON_TARGETS.includes(move.to)) {
            setPending(move);
        } else {
            commit(move);
        }
    };

    const indexAt = (status: TaskStatus, clientY: number) => {
        const cards = [...(columnRefs.current.get(status)?.querySelectorAll<HTMLElement>('[data-task-id]') ?? [])].filter(
            (el) => Number(el.dataset.taskId) !== dragging?.id,
        );
        const i = cards.findIndex((el) => {
            const r = el.getBoundingClientRect();

            return clientY < r.top + r.height / 2;
        });

        return i === -1 ? cards.length : i;
    };

    const onDragOver = (e: DragEvent, status: TaskStatus) => {
        if (!dragging || !canDrop(dragging, status)) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        const index = indexAt(status, e.clientY);
        if (over?.status !== status || over.index !== index) setOver({ status, index });
    };

    const onDrop = (e: DragEvent, status: TaskStatus) => {
        e.preventDefault();
        const task = dragging;
        setDragging(null);
        setOver(null);
        if (!task || !canDrop(task, status)) return;

        const list = (byColumn.get(status) ?? []).filter((t) => t.id !== task.id);
        const index = indexAt(status, e.clientY);
        const afterId = list[index - 1]?.id ?? null;
        const beforeId = list[index]?.id ?? null;

        if (status === task.status && list.findIndex((t) => t.id === beforeId) === (byColumn.get(status) ?? []).findIndex((t) => t.id === task.id))
            return;

        request({ task, to: status, afterId, beforeId });
    };

    if (!project) {
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Tasks" />
                <div className="p-4 md:p-6">
                    <EmptyState
                        icon={SquareKanban}
                        title="No projects yet"
                        description="Tasks belong to a project. Register a project first."
                        action={
                            <Button asChild>
                                <Link href={route('projects.create')}>
                                    <FolderPlus />
                                    New project
                                </Link>
                            </Button>
                        }
                    />
                </div>
            </AppLayout>
        );
    }

    const total = tasks.length;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Tasks · ${project.name}`} />

            <div className="flex min-w-0 flex-1 flex-col gap-4 p-4 md:p-6">
                <PageHeader
                    eyebrow="Tasks"
                    title={project.name}
                    description="Triage, hold and approve here. Planning, development, testing and review columns are filled by the AI team as it works."
                    actions={
                        <>
                            {projects.length > 1 && (
                                <select
                                    aria-label="Project"
                                    value={project.slug}
                                    onChange={(e) => router.get(route('tasks.board'), { project: e.target.value })}
                                    className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                                >
                                    {projects.map((p) => (
                                        <option key={p.id} value={p.slug}>
                                            {p.key} · {p.name}
                                        </option>
                                    ))}
                                </select>
                            )}
                            {can.manage && (
                                <Button onClick={() => setCreating(true)}>
                                    <Plus />
                                    New task
                                </Button>
                            )}
                        </>
                    }
                />

                {errors.task && (
                    <div role="alert" className="border-danger/30 bg-danger/10 flex items-start gap-2 rounded-md border px-3 py-2 text-sm">
                        <XCircle className="text-danger mt-0.5 size-4 shrink-0" />
                        {errors.task}
                    </div>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative w-full max-w-xs">
                        <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                        <input
                            type="search"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                            placeholder="Filter by key or title"
                            aria-label="Filter tasks"
                            className="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border pr-3 pl-9 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                    </div>
                    <select
                        aria-label="Priority"
                        value={priority}
                        onChange={(e) => setPriority(e.target.value as TaskPriority | '')}
                        className="border-input bg-background h-9 rounded-md border px-2 text-sm"
                    >
                        <option value="">All priorities</option>
                        {options.priorities.map((p) => (
                            <option key={p.value} value={p.value}>
                                {p.label}
                            </option>
                        ))}
                    </select>
                    <span className="text-muted-foreground text-xs">
                        {total} task{total === 1 ? '' : 's'} · completed tasks stay visible for 14 days
                    </span>
                </div>

                {total === 0 ? (
                    <EmptyState
                        icon={SquareKanban}
                        title="No tasks yet"
                        description="Describe a change in plain language — the impact analysis shows the files, tests and specialists it will involve before any work starts."
                        action={
                            can.manage && (
                                <Button onClick={() => setCreating(true)}>
                                    <Plus />
                                    New task
                                </Button>
                            )
                        }
                    />
                ) : (
                    <LayoutGroup>
                        <div
                            className="relative -mx-4 flex min-h-0 flex-1 gap-3 overflow-x-auto px-4 pb-4 md:-mx-6 md:px-6"
                            aria-label="Kanban board"
                        >
                            {columns.map((column) => {
                                const list = byColumn.get(column.value) ?? [];
                                const droppable = dragging ? canDrop(dragging, column.value) : false;

                                return (
                                    <section
                                        key={column.value}
                                        aria-label={column.label}
                                        onDragOver={(e) => onDragOver(e, column.value)}
                                        onDragLeave={(e) => {
                                            if (!e.currentTarget.contains(e.relatedTarget as Node)) setOver(null);
                                        }}
                                        onDrop={(e) => onDrop(e, column.value)}
                                        className={cn(
                                            'bg-muted/40 flex w-72 shrink-0 flex-col rounded-lg border border-transparent transition-colors',
                                            dragging && droppable && 'border-dashed border-[var(--ring)]/50',
                                            dragging && !droppable && 'opacity-40',
                                        )}
                                    >
                                        <header className="flex items-center justify-between gap-2 px-3 pt-3 pb-2">
                                            <h2 className="eyebrow flex items-center gap-1.5">
                                                {column.label}
                                                {column.agent_only && (
                                                    <TooltipProvider delayDuration={200}>
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Bot className="size-3.5" aria-label="Set by the AI team" />
                                                            </TooltipTrigger>
                                                            <TooltipContent className="max-w-56">
                                                                Set by the AI team as it works (agent engine, Phase 4) — not by hand, so it always
                                                                reflects real work.
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    </TooltipProvider>
                                                )}
                                            </h2>
                                            <span className="text-muted-foreground tabular text-xs">{list.length}</span>
                                        </header>

                                        <div
                                            ref={(el) => {
                                                if (el) columnRefs.current.set(column.value, el);
                                            }}
                                            className="flex min-h-24 flex-1 flex-col gap-2 px-2 pb-2"
                                        >
                                            {list.map((task, i) => (
                                                <div key={task.id}>
                                                    {over?.status === column.value && over.index === i && <DropMarker />}
                                                    <Card
                                                        task={task}
                                                        projectSlug={project.slug}
                                                        draggable={can.manage && !moving}
                                                        dimmed={dragging?.id === task.id}
                                                        layout={!reduced}
                                                        columns={columns}
                                                        onDragStart={() => setDragging(task)}
                                                        onDragEnd={() => {
                                                            setDragging(null);
                                                            setOver(null);
                                                        }}
                                                        onMove={(to) =>
                                                            request({
                                                                task,
                                                                to,
                                                                afterId: (byColumn.get(to) ?? []).at(-1)?.id ?? null,
                                                                beforeId: null,
                                                            })
                                                        }
                                                        canManage={can.manage}
                                                        canApprove={can.approve}
                                                    />
                                                </div>
                                            ))}
                                            {over?.status === column.value && over.index >= list.filter((t) => t.id !== dragging?.id).length && (
                                                <DropMarker />
                                            )}
                                            {list.length === 0 && !dragging && (
                                                <p className="text-muted-foreground px-1 py-4 text-center text-xs">No tasks</p>
                                            )}
                                        </div>
                                    </section>
                                );
                            })}
                        </div>
                    </LayoutGroup>
                )}
            </div>

            {can.manage && <TaskFormDialog open={creating} onOpenChange={setCreating} projectSlug={project.slug} options={options} />}

            <ReasonDialog
                open={pending !== null}
                onOpenChange={(open) => !open && setPending(null)}
                title={pending?.to === 'BLOCKED' ? `Block ${pending.task.key}?` : `Request changes on ${pending?.task.key}?`}
                description={
                    pending?.to === 'BLOCKED'
                        ? 'The task stops where it is. Unblocking returns it to exactly this column.'
                        : 'Your feedback is kept on the task for the specialist who picks it up.'
                }
                label={pending?.to === 'BLOCKED' ? 'Why is it blocked?' : 'What needs to change?'}
                confirm={pending?.to === 'BLOCKED' ? 'Block task' : 'Request changes'}
                processing={moving}
                onConfirm={(reason) => pending && commit(pending, reason)}
            />
        </AppLayout>
    );
}

function DropMarker() {
    return <div className="bg-brand-crimson mb-2 h-0.5 rounded-full" aria-hidden="true" />;
}

function Card({
    task,
    projectSlug,
    draggable,
    dimmed,
    layout,
    columns,
    onDragStart,
    onDragEnd,
    onMove,
    canManage,
    canApprove,
}: {
    task: TaskCard;
    projectSlug: string;
    draggable: boolean;
    dimmed: boolean;
    layout: boolean;
    columns: BoardProps['columns'];
    onDragStart: () => void;
    onDragEnd: () => void;
    onMove: (to: TaskStatus) => void;
    canManage: boolean;
    canApprove: boolean;
}) {
    const targets = task.user_targets.filter((t) => t !== 'CANCELLED' && (t !== 'DONE' || canApprove));
    const agentWork = ['PLANNING', 'IN_PROGRESS', 'TESTING', 'REVIEW', 'SECURITY_REVIEW', 'CHANGES_REQUESTED'].includes(task.status);
    const label = (s: TaskStatus) => columns.find((c) => c.value === s)?.label ?? STATUS[s].label;

    return (
        // Native drag and drop lives on this wrapper; motion only animates layout (FLIP, Plan §7.6).
        <div
            data-task-id={task.id}
            draggable={draggable}
            onDragStart={(e) => {
                e.dataTransfer.setData('text/plain', String(task.id));
                e.dataTransfer.effectAllowed = 'move';
                onDragStart();
            }}
            onDragEnd={onDragEnd}
        >
            <motion.article
                layout={layout}
                layoutId={layout ? `task-${task.id}` : undefined}
                transition={{ duration: 0.24, ease: [0.22, 1, 0.36, 1] }}
                className={cn(
                    'bg-card border-border group hover:border-navy-500 rounded-md border p-3 text-sm shadow-xs transition-colors',
                    draggable && 'cursor-grab active:cursor-grabbing',
                    dimmed && 'opacity-40',
                    agentWork && 'brand-edge',
                )}
            >
                <div className="flex items-start justify-between gap-2">
                    <Link href={route('tasks.show', [projectSlug, task.number])} className="text-muted-foreground font-mono text-xs hover:underline">
                        {task.key}
                    </Link>
                    <div className="flex items-center gap-1.5">
                        <PriorityBadge priority={task.priority} compact />
                        {canManage && (
                            <DropdownMenu>
                                <DropdownMenuTrigger asChild>
                                    <button
                                        type="button"
                                        className="text-muted-foreground hover:text-foreground -my-1 rounded p-0.5"
                                        aria-label={`Actions for ${task.key}`}
                                    >
                                        <MoreHorizontal className="size-4" />
                                    </button>
                                </DropdownMenuTrigger>
                                <DropdownMenuContent align="end" className="w-52">
                                    <DropdownMenuItem asChild>
                                        <Link href={route('tasks.show', [projectSlug, task.number])}>Open task</Link>
                                    </DropdownMenuItem>
                                    {targets.length > 0 && (
                                        <>
                                            <DropdownMenuSeparator />
                                            <DropdownMenuLabel className="eyebrow">Move to</DropdownMenuLabel>
                                            {targets.map((to) => (
                                                <DropdownMenuItem key={to} onSelect={() => onMove(to)}>
                                                    {to === 'DONE' ? 'Approve → Completed' : label(to)}
                                                </DropdownMenuItem>
                                            ))}
                                        </>
                                    )}
                                </DropdownMenuContent>
                            </DropdownMenu>
                        )}
                    </div>
                </div>

                <Link
                    href={route('tasks.show', [projectSlug, task.number])}
                    className="text-heading mt-1 line-clamp-2 block font-medium hover:underline"
                >
                    {task.title}
                </Link>

                {task.status === 'BLOCKED' && task.blocked_reason && (
                    <p className="text-warning mt-1.5 line-clamp-2 text-xs">{task.blocked_reason}</p>
                )}

                <div className="text-muted-foreground mt-2 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
                    {task.agent_run && (
                        <span
                            className="flex items-center gap-1.5"
                            title={`${task.agent_run.agent} / ${task.agent_run.model ?? 'Model not configured'} / ${task.agent_run.tokens.toLocaleString()} of ${task.agent_run.token_budget.toLocaleString()} tokens`}
                        >
                            <AgentAvatar role={task.agent_run.role} size="sm" />
                            <span>{task.agent_run.status.toLowerCase()}</span>
                        </span>
                    )}
                    {task.complexity && (
                        <span title="Estimated complexity" className="bg-muted rounded px-1.5 font-mono">
                            {task.complexity}
                        </span>
                    )}
                    {task.token_estimate !== null && <span title="Token estimate">~{Math.round(task.token_estimate / 1000)}k tok</span>}
                    {task.waiting_on.length > 0 && (
                        <span className="text-warning flex items-center gap-1" title={`Waits for ${task.waiting_on.map((d) => d.key).join(', ')}`}>
                            <Lock className="size-3" />
                            {task.waiting_on.map((d) => d.key).join(', ')}
                        </span>
                    )}
                    {!!task.comments_count && (
                        <span className="flex items-center gap-1">
                            <MessageSquare className="size-3" />
                            {task.comments_count}
                        </span>
                    )}
                    {task.workspace_status && task.workspace_status !== 'removed' && (
                        <span className="flex items-center gap-1" title={`Workspace ${task.workspace_status}`}>
                            <FolderGit2
                                className={cn(
                                    'size-3',
                                    task.workspace_status === 'failed' && 'text-danger',
                                    task.workspace_status === 'ready' && 'text-success',
                                )}
                            />
                            {task.workspace_status}
                        </span>
                    )}
                </div>
            </motion.article>
        </div>
    );
}
