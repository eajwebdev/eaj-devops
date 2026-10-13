import { ActivityTimeline } from '@/components/activity-timeline';
import { EmptyState } from '@/components/empty-state';
import { HealthPanel } from '@/components/health-panel';
import { MetricTile } from '@/components/metric-tile';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { ProjectCard } from '@/components/project-card';
import { Button } from '@/components/ui/button';
import { useDebouncedReload, useEventFeed } from '@/hooks/use-event-feed';
import AppLayout from '@/layouts/app-layout';
import { isFeedEvent } from '@/lib/events';
import { type BreadcrumbItem, type HealthReport, type Project, type SharedData, type StudioEvent } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { Activity, AlertTriangle, CheckCircle2, FolderKanban, FolderPlus, SquareKanban } from 'lucide-react';

interface CommandCenterProps {
    metrics: {
        active_projects: number;
        open_tasks: number;
        needs_decision: number;
        completed_today: number;
    };
    projects: Project[];
    activity: StudioEvent[];
    health: HealthReport;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Command Center', href: '/command-center' }];

const MAX_ACTIVITY = 15;

export default function CommandCenter({ metrics, projects, activity, health }: CommandCenterProps) {
    const { organization } = usePage<SharedData>().props;
    const canCreate = organization?.permissions.includes('projects.create') ?? false;
    const reload = useDebouncedReload(['metrics', 'projects', 'health']);
    const events = useEventFeed(activity, { max: MAX_ACTIVITY, filter: isFeedEvent, onIncoming: reload });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Command Center" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    eyebrow={organization?.name}
                    title="Command Center"
                    description="What is happening across your projects, what needs you, and whether the platform is healthy."
                />

                <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricTile label="Active projects" value={metrics.active_projects} icon={FolderKanban} hint="Connected to this organization" />
                    <MetricTile label="Open tasks" value={metrics.open_tasks} icon={SquareKanban} hint="Not completed or cancelled" />
                    <MetricTile
                        label="Needs your decision"
                        value={metrics.needs_decision}
                        icon={AlertTriangle}
                        hint="Blocked tasks and stopped workflows"
                        attention
                    />
                    <MetricTile label="Completed today" value={metrics.completed_today} icon={CheckCircle2} hint="Approved since midnight" />
                </div>

                <div className="grid grid-cols-1 gap-4 lg:grid-cols-3">
                    <Panel
                        title="Recent activity"
                        className="lg:col-span-2"
                        action={
                            <Link href={route('activity')} className="text-muted-foreground hover:text-foreground text-xs">
                                View all
                            </Link>
                        }
                    >
                        {events.length > 0 ? (
                            <ActivityTimeline events={events} />
                        ) : (
                            <EmptyState
                                icon={Activity}
                                title="No activity yet"
                                description="Every real action — projects registered, workflows started, gates passed — appears here as it happens."
                            />
                        )}
                    </Panel>

                    <Panel title="System health">
                        <HealthPanel health={health} />
                    </Panel>
                </div>

                <Panel
                    title="Projects"
                    action={
                        projects.length > 0 && (
                            <Link href={route('projects.index')} className="text-muted-foreground hover:text-foreground text-xs">
                                All projects
                            </Link>
                        )
                    }
                >
                    {projects.length > 0 ? (
                        <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                            {projects.map((project) => (
                                <ProjectCard key={project.id} project={project} />
                            ))}
                        </div>
                    ) : (
                        <EmptyState
                            icon={FolderPlus}
                            title="Connect your first project"
                            description="Register a local Laravel, React, Next.js or mixed-stack project. The Studio never edits the original folder — agents work in isolated workspaces."
                            action={
                                canCreate && (
                                    <Button asChild>
                                        <Link href={route('projects.create')}>
                                            <FolderPlus />
                                            New project
                                        </Link>
                                    </Button>
                                )
                            }
                        />
                    )}
                </Panel>
            </div>
        </AppLayout>
    );
}
