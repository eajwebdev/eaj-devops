import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { relativeTime } from '@/lib/events';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { ExternalLink, GitBranch, Github, LockKeyhole, RefreshCcw, Unplug, XCircle } from 'lucide-react';

type Available = { id: number; full_name: string; name: string; private: boolean; archived: boolean; default_branch: string; html_url: string };
type Linked = {
    id: number;
    project: { id: number; key: string; name: string; slug: string };
    full_name: string;
    html_url: string;
    default_branch: string;
    private: boolean;
    synced_at: string | null;
    branches: { name: string; head_sha: string; protected: boolean }[];
    pull_requests: { number: number; title: string; state: string; html_url: string; head_sha: string; reviews: { reviewer_login: string; state: string }[] }[];
};

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Repositories', href: '/repositories' }];

export default function Repositories({
    connection,
    available,
    linked,
    projects,
    githubError,
    can,
}: {
    connection: { login: string; avatar_url: string | null; scopes: string[]; last_used_at: string | null } | null;
    available: Available[];
    linked: Linked[];
    projects: { id: number; key: string; name: string; slug: string }[];
    githubError: string | null;
    can: { connect: boolean; manage: boolean };
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Repositories" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    eyebrow="Source control"
                    title="GitHub repositories"
                    description="Import repositories, follow branches and reviews, and merge only the exact commit approved in the Studio."
                    actions={
                        can.connect &&
                        (connection ? (
                            <Button variant="outline" size="sm" onClick={() => router.delete(route('github.disconnect'))}>
                                <Unplug /> Disconnect
                            </Button>
                        ) : (
                            <Button size="sm" asChild>
                                <a href={route('github.connect')}><Github /> Connect GitHub</a>
                            </Button>
                        ))
                    }
                />

                {(errors.github || githubError) && (
                    <div role="alert" className="border-danger/30 bg-danger/10 flex items-start gap-2 rounded-md border px-3 py-2 text-sm">
                        <XCircle className="text-danger mt-0.5 size-4" /> {errors.github ?? githubError}
                    </div>
                )}

                <Panel title="Connection">
                    <p className="text-muted-foreground mb-3 text-xs">OAuth credentials are encrypted in the Studio and are never sent to a Local Runner.</p>
                    {connection ? (
                        <div className="flex items-center gap-3 text-sm">
                            {connection.avatar_url && <img src={connection.avatar_url} alt="" className="size-9 rounded-full" />}
                            <div><p className="font-medium">@{connection.login}</p><p className="text-muted-foreground text-xs">Last used {connection.last_used_at ? relativeTime(connection.last_used_at) : 'just now'}</p></div>
                        </div>
                    ) : <p className="text-muted-foreground text-sm">Connect a GitHub OAuth App to browse private and public repositories.</p>}
                </Panel>

                {connection && (
                    <Panel title="Available repositories">
                        <p className="text-muted-foreground mb-3 text-xs">Create a GitHub-backed project, or attach a repository to an existing local project.</p>
                        <div className="grid gap-2">
                            {available.map((repo) => {
                                const already = linked.some((item) => item.full_name.toLowerCase() === repo.full_name.toLowerCase());
                                return (
                                    <div key={repo.id} className="border-border flex flex-wrap items-center justify-between gap-3 rounded-md border p-3">
                                        <div className="min-w-0"><a href={repo.html_url} target="_blank" rel="noreferrer" className="font-medium hover:underline">{repo.full_name}</a><p className="text-muted-foreground text-xs">{repo.private ? 'Private' : 'Public'} · default {repo.default_branch}</p></div>
                                        <div className="flex items-center gap-2">
                                            {already ? <span className="text-success text-xs">Attached</span> : <Button variant="outline" size="sm" asChild><Link href={`/projects/create?github_repository=${encodeURIComponent(repo.full_name)}`}>Import project</Link></Button>}
                                            {!already && projects.length > 0 && can.manage && (
                                                <select aria-label={`Attach ${repo.full_name}`} defaultValue="" onChange={(event) => event.target.value && router.post(route('projects.github.attach', event.target.value), { full_name: repo.full_name })} className="border-input bg-background h-8 rounded-md border px-2 text-xs">
                                                    <option value="">Attach to local…</option>{projects.map((project) => <option key={project.id} value={project.slug}>{project.key} · {project.name}</option>)}
                                                </select>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}
                            {available.length === 0 && <p className="text-muted-foreground text-sm">No accessible repositories were returned.</p>}
                        </div>
                    </Panel>
                )}

                <div className="grid gap-4">
                    {linked.map((repo) => (
                        <Panel key={repo.id} title={repo.full_name} action={can.manage && <Button variant="outline" size="sm" onClick={() => router.post(route('repositories.sync', repo.id), {}, { preserveScroll: true })}><RefreshCcw /> Sync</Button>}>
                            <p className="text-muted-foreground mb-3 text-xs">{repo.project.key} · {repo.project.name}</p>
                            <div className="grid gap-4 lg:grid-cols-2">
                                <div><p className="eyebrow mb-2">Branches</p><div className="grid gap-1 text-sm">{repo.branches.slice(0, 12).map((branch) => <div key={branch.name} className="flex items-center gap-2"><GitBranch className="size-3.5" /><span className="font-mono text-xs">{branch.name}</span>{branch.protected && <LockKeyhole className="text-warning size-3" />}<span className="text-muted-foreground ml-auto font-mono text-xs">{branch.head_sha.slice(0, 7)}</span></div>)}</div></div>
                                <div><p className="eyebrow mb-2">Pull requests</p><div className="grid gap-2 text-sm">{repo.pull_requests.map((pull) => <a key={pull.number} href={pull.html_url} target="_blank" rel="noreferrer" className="border-border flex items-center gap-2 rounded border p-2 hover:bg-muted"><span>#{pull.number}</span><span className="truncate">{pull.title}</span><span className="text-muted-foreground ml-auto text-xs">{pull.state}</span><ExternalLink className="size-3" /></a>)}{repo.pull_requests.length === 0 && <p className="text-muted-foreground text-xs">No synchronized pull requests.</p>}</div></div>
                            </div>
                        </Panel>
                    ))}
                </div>
            </div>
        </AppLayout>
    );
}
