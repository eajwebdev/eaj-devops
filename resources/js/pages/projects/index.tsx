import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { ProjectCard } from '@/components/project-card';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem, type Project } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { FolderPlus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';

interface ProjectsIndexProps {
    projects: Project[];
    can: { create: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Projects', href: '/projects' }];

export default function ProjectsIndex({ projects, can }: ProjectsIndexProps) {
    const [query, setQuery] = useState('');

    const filtered = useMemo(() => {
        const q = query.trim().toLowerCase();

        return q === '' ? projects : projects.filter((p) => [p.name, p.key, p.connection?.local_path ?? ''].some((v) => v.toLowerCase().includes(q)));
    }, [projects, query]);

    const newProject = can.create && (
        <Button asChild>
            <Link href={route('projects.create')}>
                <FolderPlus />
                New project
            </Link>
        </Button>
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Projects" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    eyebrow="Workspace"
                    title="Projects"
                    description="Local and remote codebases the AI team can work on. Original folders stay read-only to agents."
                    actions={projects.length > 0 && newProject}
                />

                {projects.length === 0 ? (
                    <EmptyState
                        icon={FolderPlus}
                        title="No projects yet"
                        description={
                            can.create
                                ? 'Register a local project folder to get started. Scanning and stack detection run on your machine through the EAJ Local Runner.'
                                : 'No projects have been connected to this organization yet. Ask an owner or project manager to add one.'
                        }
                        action={newProject}
                    />
                ) : (
                    <>
                        {projects.length > 6 && (
                            <div className="relative max-w-sm">
                                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                                <input
                                    type="search"
                                    value={query}
                                    onChange={(e) => setQuery(e.target.value)}
                                    placeholder="Filter by name, key or path"
                                    aria-label="Filter projects"
                                    className="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border pr-3 pl-9 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                />
                            </div>
                        )}

                        {filtered.length === 0 ? (
                            <EmptyState icon={Search} title="No matching projects" description={`Nothing matches “${query}”.`} />
                        ) : (
                            <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
                                {filtered.map((project) => (
                                    <ProjectCard key={project.id} project={project} />
                                ))}
                            </div>
                        )}
                    </>
                )}
            </div>
        </AppLayout>
    );
}
