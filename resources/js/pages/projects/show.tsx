import { ActivityTimeline } from '@/components/activity-timeline';
import { EmptyState } from '@/components/empty-state';
import InputError from '@/components/input-error';
import { MetricTile } from '@/components/metric-tile';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { connectionStatus } from '@/components/project-card';
import { CodeIndexPanel } from '@/components/project/code-index-panel';
import { ConnectionPanel } from '@/components/project/connection-panel';
import { RunnerCommandList, WorkspaceList } from '@/components/project/runner-activity';
import { ScanProgress } from '@/components/project/scan-progress';
import { StackProfile } from '@/components/project/stack-profile';
import { StatusPill } from '@/components/status-pill';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useDebouncedReload, useEventFeed } from '@/hooks/use-event-feed';
import AppLayout from '@/layouts/app-layout';
import { isFeedEvent } from '@/lib/events';
import { type BreadcrumbItem, type LocalRunner, type Project, type RunnerCommand, type SharedData, type StudioEvent, type Workspace } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { Activity, AlertTriangle, CirclePause, LoaderCircle, ScanSearch, SquareKanban, Trash2, Unplug, Workflow, XCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

interface ProjectShowProps {
    project: Project;
    events: StudioEvent[];
    stats: { active_workflows: number; blocked_workflows: number; paused_workflows: number };
    runner: LocalRunner | null;
    runners: LocalRunner[];
    commands: RunnerCommand[];
    workspaces: Workspace[];
    can: { update: boolean; delete: boolean };
}

const ACTIVE = ['queued', 'claimed'];

export default function ProjectShow({ project, events: initialEvents, stats, runner, runners, commands, workspaces, can }: ProjectShowProps) {
    const { errors } = usePage<SharedData & { errors: Record<string, string> }>().props;
    const reload = useDebouncedReload(['project', 'stats', 'runner', 'commands', 'workspaces'], 400);
    const events = useEventFeed(initialEvents, { max: 40, filter: (e) => e.project_id === project.id, onIncoming: reload });
    const connection = project.connection;
    const status = connection ? connectionStatus[connection.status] : null;

    // Live step text comes from the runner's persisted progress events (Plan §7.7).
    const active = commands.find((c) => ACTIVE.includes(c.status) && ['project.scan', 'project.index', 'git.init'].includes(c.type)) ?? null;
    const latestProgress = [...events]
        .reverse()
        .find((e) => e.type === 'runner.command.progress' && (e.payload.command as { id?: string } | undefined)?.id === active?.id);
    const step = (latestProgress?.payload.command as { progress?: string } | undefined)?.progress ?? active?.progress ?? null;

    const runnerAway = runner && runner.status !== 'online';
    const feed = events.filter(isFeedEvent);

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Projects', href: '/projects' },
        { title: project.name, href: `/projects/${project.slug}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={project.name} />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    eyebrow={`Project · ${project.key}`}
                    title={project.name}
                    description={project.description ?? undefined}
                    actions={
                        <>
                            {connection && status && <StatusPill tone={status.tone} icon={status.icon} label={connection.status_label} />}
                            <Button size="sm" variant="outline" asChild>
                                <Link href={route('tasks.board', { project: project.slug })}>
                                    <SquareKanban />
                                    Tasks
                                </Link>
                            </Button>
                            {can.delete && <DeleteProjectDialog project={project} />}
                        </>
                    }
                />

                {errors.runner && (
                    <div role="alert" className="border-danger/30 bg-danger/10 flex items-start gap-2 rounded-md border px-3 py-2 text-sm">
                        <XCircle className="text-danger mt-0.5 size-4 shrink-0" />
                        {errors.runner}
                    </div>
                )}

                {runnerAway && (
                    <div role="status" className="border-warning/30 bg-warning/10 flex items-start gap-2 rounded-md border px-3 py-2 text-sm">
                        <Unplug className="text-warning mt-0.5 size-4 shrink-0" />
                        <span>
                            <span className="font-medium">Runner offline — reconnect your PC.</span>{' '}
                            <span className="text-muted-foreground">
                                {runner.name} is {runner.status_label.toLowerCase()}. Queued work waits until it runs{' '}
                                <code className="font-mono">eaj-runner start</code> again.
                            </span>
                        </span>
                    </div>
                )}

                {connection?.last_error && !active && (
                    <div role="alert" className="border-danger/30 bg-danger/10 flex items-start gap-2 rounded-md border px-3 py-2 text-sm">
                        <AlertTriangle className="text-danger mt-0.5 size-4 shrink-0" />
                        <span className="break-words">{connection.last_error}</span>
                    </div>
                )}

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <MetricTile label="Workflows running" value={stats.active_workflows} icon={Workflow} hint="In progress right now" />
                    <MetricTile
                        label="Needs your decision"
                        value={stats.blocked_workflows}
                        icon={AlertTriangle}
                        hint="Blocked on a limit or failure"
                        attention
                    />
                    <MetricTile label="Paused" value={stats.paused_workflows} icon={CirclePause} hint="Held at their last checkpoint" />
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Panel title="Stack profile" className="lg:col-span-2">
                        {active && (
                            <ScanProgress
                                title={active.label}
                                step={step}
                                waitingForRunner={active.status === 'queued'}
                                className="border-border mb-5 border-b pb-5"
                            />
                        )}
                        {connection?.detected_stack ? (
                            <StackProfile stack={connection.detected_stack} git={connection.git_state} warnings={connection.warnings} />
                        ) : (
                            !active && (
                                <EmptyState
                                    icon={ScanSearch}
                                    title="Not scanned yet"
                                    description={
                                        runner ? (
                                            'Rescan to detect the framework, versions, tests and Git state. Nothing in the folder is changed.'
                                        ) : (
                                            <>
                                                Scanning runs on the PC where the folder lives.{' '}
                                                <Link href={route('runners.index')} className="text-brand-text hover:underline">
                                                    Pair a runner
                                                </Link>{' '}
                                                and assign it to this project.
                                            </>
                                        )
                                    }
                                />
                            )
                        )}
                    </Panel>

                    <div className="flex flex-col gap-4">
                        <Panel title="Connection">
                            <ConnectionPanel project={project} runner={runner} runners={runners} busy={active !== null} canUpdate={can.update} />
                        </Panel>
                        <Panel title="Code index">{connection && <CodeIndexPanel projectSlug={project.slug} connection={connection} />}</Panel>
                    </div>
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Panel title="Project activity" className="lg:col-span-2">
                        {feed.length > 0 ? (
                            <ActivityTimeline events={feed} />
                        ) : (
                            <EmptyState
                                icon={Activity}
                                title="No activity yet"
                                description="Scans, workflows and reviews for this project will appear here live."
                            />
                        )}
                    </Panel>
                    <div className="flex flex-col gap-4">
                        <Panel title="Workspaces">
                            <WorkspaceList workspaces={workspaces} />
                        </Panel>
                        <Panel title="Runner activity">
                            <RunnerCommandList commands={commands} />
                        </Panel>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
function DeleteProjectDialog({ project }: { project: Project }) {
    const { data, setData, delete: destroy, processing, errors, reset } = useForm({ confirm_key: '' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        destroy(route('projects.destroy', project.slug), { preserveScroll: true });
    };

    return (
        <Dialog onOpenChange={(open) => !open && reset()}>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-danger">
                    <Trash2 />
                    Remove
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>Remove {project.name} from the Studio?</DialogTitle>
                        <DialogDescription>
                            This removes the project record and its event history from the Studio. The folder on disk is not touched. The audit log
                            keeps a record of the removal.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="confirm_key">
                            Type <span className="text-heading font-mono">{project.key}</span> to confirm
                        </Label>
                        <Input
                            id="confirm_key"
                            autoComplete="off"
                            className="font-mono uppercase"
                            value={data.confirm_key}
                            onChange={(e) => setData('confirm_key', e.target.value.toUpperCase())}
                        />
                        <InputError message={errors.confirm_key} />
                    </div>
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" variant="destructive" disabled={processing || data.confirm_key !== project.key}>
                            {processing ? <LoaderCircle className="animate-spin" /> : <Trash2 />}
                            Remove project
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
