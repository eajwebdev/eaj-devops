import InputError from '@/components/input-error';
import { PageHeader } from '@/components/page-header';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type LocalRunner } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { AlertTriangle, Archive, FolderGit2, Github, LoaderCircle, ShieldCheck, Sparkles, type LucideIcon } from 'lucide-react';
import { useState, type FormEventHandler } from 'react';

interface ConnectionTypeOption {
    value: 'local' | 'github' | 'new' | 'archive';
    label: string;
    available: boolean;
}

const icons: Record<ConnectionTypeOption['value'], LucideIcon> = {
    local: FolderGit2,
    github: Github,
    new: Sparkles,
    archive: Archive,
};

const unavailableReason: Record<ConnectionTypeOption['value'], string> = {
    local: '',
    github: 'Arrives with GitHub integration (Phase 10)',
    new: 'Coming after the local project engine',
    archive: 'Coming after the local project engine',
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Projects', href: '/projects' },
    { title: 'New project', href: '/projects/create' },
];

/** Suggest a task-ID key from the name: "Booking SaaS" → "BS", "Boundary Cafe POS" → "BCP". */
function suggestKey(name: string): string {
    const words = name.toUpperCase().match(/[A-Z0-9]+/g) ?? [];

    if (words.length === 0) return '';
    if (words.length === 1) return words[0].replace(/^[0-9]+/, '').slice(0, 4);

    return words
        .map((w) => w[0])
        .join('')
        .replace(/^[0-9]+/, '')
        .slice(0, 6);
}

type ProjectForm = {
    connection_type: ConnectionTypeOption['value'];
    name: string;
    key: string;
    local_path: string;
    description: string;
    local_runner_id: number | null;
};

/** Mirrors LocalRunner::allowsPath — early feedback only; the runner re-checks on the PC. */
function runnerAllows(runner: LocalRunner, folder: string): boolean {
    const insensitive = runner.platform === 'win32' || runner.platform === 'darwin';
    const norm = (p: string) => {
        const unix = p.trim().replace(/\\/g, '/').replace(/\/+$/, '');

        return insensitive ? unix.toLowerCase() : unix;
    };
    const target = norm(folder);

    return runner.allowed_roots.some((root) => target === norm(root) || target.startsWith(`${norm(root)}/`));
}

export default function CreateProject({ connectionTypes, runners }: { connectionTypes: ConnectionTypeOption[]; runners: LocalRunner[] }) {
    const defaultRunnerId: number | null = (runners.find((r) => r.status === 'online') ?? runners[0])?.id ?? null;
    const { data, setData, post, processing, errors } = useForm<ProjectForm>({
        connection_type: 'local',
        name: '',
        key: '',
        local_path: '',
        description: '',
        local_runner_id: defaultRunnerId,
    });
    const selectedRunner = runners.find((r) => r.id === data.local_runner_id) ?? null;
    // Once the user edits the key, stop suggesting one from the name.
    const [keyTouched, setKeyTouched] = useState(false);

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('projects.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="New project" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader eyebrow="Projects" title="New project" description="Register a codebase with the Studio. Nothing on disk is changed." />

                <form onSubmit={submit} className="grid max-w-3xl gap-8">
                    <fieldset className="grid gap-3">
                        <legend className="eyebrow mb-3">1 · Project source</legend>
                        <div className="grid gap-3 sm:grid-cols-2" role="radiogroup" aria-label="Project source">
                            {connectionTypes.map((type) => {
                                const Icon = icons[type.value];
                                const selected = data.connection_type === type.value;

                                return (
                                    <button
                                        key={type.value}
                                        type="button"
                                        role="radio"
                                        aria-checked={selected}
                                        disabled={!type.available}
                                        onClick={() => setData('connection_type', type.value)}
                                        className={cn(
                                            'bg-card border-border focus-visible:ring-ring flex items-start gap-3 rounded-lg border p-4 text-left transition-colors focus-visible:ring-2 focus-visible:outline-hidden',
                                            selected && 'border-brand-crimson brand-edge',
                                            type.available ? 'hover:border-navy-500' : 'cursor-not-allowed opacity-50',
                                        )}
                                    >
                                        <Icon className={cn('mt-0.5 size-5 shrink-0', selected ? 'text-brand-text' : 'text-muted-foreground')} />
                                        <span>
                                            <span className="text-heading block text-sm font-medium">{type.label}</span>
                                            <span className="text-muted-foreground block text-xs">
                                                {type.available
                                                    ? 'A folder on a machine running the EAJ Local Runner'
                                                    : unavailableReason[type.value]}
                                            </span>
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                        <InputError message={errors.connection_type} />
                    </fieldset>

                    <fieldset className="grid gap-5">
                        <legend className="eyebrow mb-3">2 · Details</legend>

                        <div className="grid gap-5 sm:grid-cols-[1fr_10rem]">
                            <div className="grid gap-2">
                                <Label htmlFor="name">Project name</Label>
                                <Input
                                    id="name"
                                    required
                                    autoFocus
                                    maxLength={120}
                                    value={data.name}
                                    onChange={(e) => {
                                        const name = e.target.value;
                                        setData((d) => ({ ...d, name, key: keyTouched ? d.key : suggestKey(name) }));
                                    }}
                                    placeholder="Booking SaaS"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="key">Key</Label>
                                <Input
                                    id="key"
                                    required
                                    maxLength={10}
                                    value={data.key}
                                    onChange={(e) => {
                                        setKeyTouched(true);
                                        setData('key', e.target.value.toUpperCase());
                                    }}
                                    placeholder="BOOK"
                                    className="font-mono uppercase"
                                    aria-describedby="key-hint"
                                />
                                <p id="key-hint" className="text-muted-foreground text-xs">
                                    Task IDs look like {data.key || 'KEY'}-114
                                </p>
                                <InputError message={errors.key} />
                            </div>
                        </div>

                        {data.connection_type === 'local' && (
                            <div className="grid gap-2">
                                <Label htmlFor="local_path">Folder path</Label>
                                <Input
                                    id="local_path"
                                    required
                                    maxLength={1024}
                                    value={data.local_path}
                                    onChange={(e) => setData('local_path', e.target.value)}
                                    placeholder="C:\laragon\www\booking-saas"
                                    className="font-mono"
                                    spellCheck={false}
                                    autoComplete="off"
                                />
                                <p className="text-muted-foreground flex items-start gap-1.5 text-xs">
                                    <ShieldCheck className="text-success mt-px size-3.5 shrink-0" />
                                    This folder stays read-only to agents. They work in isolated Git worktrees and changes merge only after tests,
                                    review and your approval.
                                </p>
                                <InputError message={errors.local_path} />
                            </div>
                        )}

                        {data.connection_type === 'local' && (
                            <div className="grid gap-2">
                                <Label htmlFor="local_runner_id">Runner on that PC</Label>
                                {runners.length > 0 ? (
                                    <>
                                        <select
                                            id="local_runner_id"
                                            value={data.local_runner_id ?? ''}
                                            onChange={(e) => setData('local_runner_id', e.target.value ? Number(e.target.value) : null)}
                                            className="border-input bg-background focus-visible:ring-ring h-10 w-full rounded-md border px-3 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                                        >
                                            {runners.map((r) => (
                                                <option key={r.id} value={r.id}>
                                                    {r.name} — {r.status_label}
                                                    {r.hostname ? ` (${r.hostname})` : ''}
                                                </option>
                                            ))}
                                            <option value="">Choose later</option>
                                        </select>
                                        {selectedRunner && data.local_path && !runnerAllows(selectedRunner, data.local_path) && (
                                            <p className="text-warning flex items-start gap-1.5 text-xs">
                                                <AlertTriangle className="mt-px size-3.5 shrink-0" />
                                                This runner hasn't allowed that folder yet. On {selectedRunner.hostname ?? 'that PC'} run: eaj-runner
                                                allow "{data.local_path.replace(/[\\/][^\\/]*$/, '')}"
                                            </p>
                                        )}
                                        {selectedRunner && selectedRunner.status !== 'online' && (
                                            <p className="text-muted-foreground text-xs">
                                                The runner is {selectedRunner.status_label.toLowerCase()}; the scan waits until it connects.
                                            </p>
                                        )}
                                    </>
                                ) : (
                                    <p className="text-muted-foreground text-xs">
                                        No runner paired yet. You can create the project now and{' '}
                                        <Link href={route('runners.index')} className="text-brand-text underline-offset-4 hover:underline">
                                            pair a runner
                                        </Link>{' '}
                                        to scan it.
                                    </p>
                                )}
                                <InputError message={errors.local_runner_id} />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="description">
                                Description <span className="text-muted-foreground font-normal">(optional)</span>
                            </Label>
                            <textarea
                                id="description"
                                rows={3}
                                maxLength={2000}
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                            />
                            <InputError message={errors.description} />
                        </div>
                    </fieldset>

                    <div className="bg-muted text-muted-foreground rounded-md p-3 text-xs">
                        <span className="text-heading font-medium">Next:</span> the runner scans the folder on that PC — framework and version,
                        frontend, tests, Git state — then builds the code index. Nothing in the folder is changed. Choosing the AI team, budgets and
                        approval rules follow in later phases.
                    </div>

                    <div className="flex items-center gap-3">
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="animate-spin" />}
                            Create project
                        </Button>
                        <Button variant="ghost" asChild>
                            <Link href={route('projects.index')}>Cancel</Link>
                        </Button>
                    </div>
                </form>
            </div>
        </AppLayout>
    );
}
