import { StatusPill } from '@/components/status-pill';
import { type Tone } from '@/lib/events';
import { type Project, type ProjectConnection } from '@/types';
import { Link } from '@inertiajs/react';
import { CheckCircle2, CircleDashed, FolderGit2, LoaderCircle, ScanSearch, XCircle, type LucideIcon } from 'lucide-react';

export const connectionStatus: Record<ProjectConnection['status'], { tone: Tone; icon: LucideIcon }> = {
    pending_scan: { tone: 'info', icon: CircleDashed },
    scanning: { tone: 'brand', icon: LoaderCircle },
    indexing: { tone: 'brand', icon: ScanSearch },
    connected: { tone: 'success', icon: CheckCircle2 },
    error: { tone: 'danger', icon: XCircle },
};

export function ProjectCard({ project }: { project: Project }) {
    const connection = project.connection;
    const status = connection ? connectionStatus[connection.status] : null;

    return (
        <Link
            href={route('projects.show', project.slug)}
            className="bg-card border-border hover:border-navy-500 focus-visible:ring-ring group block rounded-lg border p-4 transition-colors focus-visible:ring-2 focus-visible:outline-hidden"
        >
            <div className="flex items-start justify-between gap-3">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="bg-secondary text-heading font-display flex size-9 shrink-0 items-center justify-center rounded-md text-[0.625rem] font-bold tracking-wider">
                        {project.key.slice(0, 4)}
                    </span>
                    <div className="min-w-0">
                        <p className="text-heading truncate text-sm font-semibold">{project.name}</p>
                        <p className="text-muted-foreground truncate text-xs">{connection?.type_label ?? 'Not connected'}</p>
                    </div>
                </div>
                {connection && status && <StatusPill tone={status.tone} icon={status.icon} label={connection.status_label} className="shrink-0" />}
            </div>
            {connection?.local_path && (
                <p className="text-muted-foreground mt-3 flex items-center gap-1.5 truncate font-mono text-xs" title={connection.local_path}>
                    <FolderGit2 className="size-3.5 shrink-0" aria-hidden="true" />
                    <span className="truncate">{connection.local_path}</span>
                </p>
            )}
        </Link>
    );
}
