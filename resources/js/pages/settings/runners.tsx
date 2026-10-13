import { CopyCommand } from '@/components/copy-command';
import { EmptyState } from '@/components/empty-state';
import HeadingSmall from '@/components/heading-small';
import { useStudioEvents } from '@/components/realtime/realtime-provider';
import { RunnerStatusPill } from '@/components/runner-status-pill';
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
import { useDebouncedReload } from '@/hooks/use-event-feed';
import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { relativeTime } from '@/lib/events';
import { type BreadcrumbItem, type LocalRunner } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Ban, FolderLock, HardDrive, LoaderCircle, MonitorSmartphone, Plus, ShieldCheck } from 'lucide-react';
import { useEffect, useState } from 'react';

interface RunnersPageProps {
    runners: LocalRunner[];
    studioUrl: string;
    runnerScript: string;
    can: { manage: boolean };
    pairing: { code: string; expires_at: string } | null;
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Local runners', href: '/settings/runners' }];

export default function Runners({ runners, studioUrl, runnerScript, can, pairing }: RunnersPageProps) {
    const reload = useDebouncedReload(['runners'], 300);
    useStudioEvents((events) => {
        if (events.some((e) => e.type.startsWith('runner.') && !e.type.startsWith('runner.command'))) reload();
    });

    // Heartbeat-derived status changes without events (stale/offline): refresh quietly.
    useEffect(() => {
        const timer = window.setInterval(() => !document.hidden && router.reload({ only: ['runners'] }), 30_000);

        return () => window.clearInterval(timer);
    }, []);

    const active = runners.filter((r) => r.status !== 'revoked');
    const revoked = runners.filter((r) => r.status === 'revoked');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Local runners" />

            <SettingsLayout>
                <div className="space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <HeadingSmall
                            title="Local runners"
                            description="The EAJ Local Runner is the only thing that touches project folders and Git on a PC. It connects out to the Studio — your PC opens no ports."
                        />
                        {can.manage && <PairRunner studioUrl={studioUrl} runnerScript={runnerScript} pairing={pairing} />}
                    </div>

                    {active.length === 0 ? (
                        <EmptyState
                            icon={MonitorSmartphone}
                            title="No runners paired"
                            description="Pair the PC where your projects live (e.g. your Laragon or XAMPP machine). Scans, Git and task workspaces all run there."
                        />
                    ) : (
                        <ul className="space-y-3">
                            {active.map((runner) => (
                                <RunnerRow key={runner.id} runner={runner} canManage={can.manage} />
                            ))}
                        </ul>
                    )}

                    {revoked.length > 0 && (
                        <details className="text-sm">
                            <summary className="text-muted-foreground cursor-pointer">{revoked.length} revoked runner(s)</summary>
                            <ul className="mt-3 space-y-2">
                                {revoked.map((r) => (
                                    <li key={r.id} className="text-muted-foreground flex items-center gap-2">
                                        <Ban className="size-3.5" /> {r.name} · {r.hostname}
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}

                    <div className="bg-muted text-muted-foreground flex items-start gap-2 rounded-md p-3 text-xs">
                        <ShieldCheck className="text-success mt-px size-4 shrink-0" />
                        <p>
                            The runner only reads folders <em>you</em> allow on that PC (<code className="font-mono">eaj-runner allow</code>), refuses
                            unsigned instructions, never sends <code className="font-mono">.env</code> values or keys, and only runs Git operations
                            from its own approved list. The Studio cannot change any of that remotely.
                        </p>
                    </div>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}

function RunnerRow({ runner, canManage }: { runner: LocalRunner; canManage: boolean }) {
    return (
        <li className="bg-card border-border rounded-lg border p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex min-w-0 items-start gap-3">
                    <HardDrive className="text-muted-foreground mt-0.5 size-5 shrink-0" />
                    <div className="min-w-0">
                        <p className="text-heading text-sm font-semibold">{runner.name}</p>
                        <p className="text-muted-foreground text-xs">
                            {[runner.hostname, runner.os, runner.version && `runner v${runner.version}`].filter(Boolean).join(' · ')}
                        </p>
                        <p className="text-muted-foreground text-xs">
                            {runner.last_heartbeat_at ? `Last heartbeat ${relativeTime(runner.last_heartbeat_at)}` : 'Never connected'}
                            {runner.paired_by && ` · paired by ${runner.paired_by}`}
                        </p>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <RunnerStatusPill status={runner.status} />
                    {canManage && <RevokeRunner runner={runner} />}
                </div>
            </div>

            <div className="mt-3 grid gap-2 text-xs sm:grid-cols-2">
                <div>
                    <p className="eyebrow mb-1">Allowed project folders</p>
                    {runner.allowed_roots.length ? (
                        <ul className="space-y-0.5 font-mono">
                            {runner.allowed_roots.map((root) => (
                                <li key={root} className="text-heading flex items-center gap-1.5 break-all">
                                    <FolderLock className="text-muted-foreground size-3.5 shrink-0" />
                                    {root}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-warning">None yet — on that PC run: eaj-runner allow &lt;folder&gt;</p>
                    )}
                </div>
                <div>
                    <p className="eyebrow mb-1">Workspaces folder</p>
                    <p className="text-heading font-mono break-all">{runner.workspaces_root ?? '—'}</p>
                </div>
            </div>
        </li>
    );
}

function PairRunner({ studioUrl, runnerScript, pairing }: { studioUrl: string; runnerScript: string; pairing: RunnersPageProps['pairing'] }) {
    const [open, setOpen] = useState(pairing !== null);
    const { post, processing } = useForm({});
    const runner = `node "${runnerScript}"`;

    useEffect(() => {
        if (pairing) setOpen(true);
    }, [pairing]);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm">
                    <Plus />
                    Pair a runner
                </Button>
            </DialogTrigger>
            <DialogContent className="max-w-xl">
                <DialogHeader>
                    <DialogTitle>Pair a local runner</DialogTitle>
                    <DialogDescription>
                        Run these on the PC where your projects live (Node.js 18+). The code works once and expires in 10 minutes.
                    </DialogDescription>
                </DialogHeader>

                {pairing ? (
                    <div className="space-y-4">
                        <div className="text-center">
                            <p className="eyebrow">Pairing code</p>
                            <p className="text-heading font-mono text-3xl font-semibold tracking-[0.2em]">{pairing.code}</p>
                            <p className="text-muted-foreground text-xs">Expires {new Date(pairing.expires_at).toLocaleTimeString()}</p>
                        </div>
                        <ol className="space-y-3 text-sm">
                            <li>
                                <p className="mb-1">1. Pair this PC</p>
                                <CopyCommand command={`${runner} pair ${studioUrl} ${pairing.code}`} />
                            </li>
                            <li>
                                <p className="mb-1">2. Allow the folder that contains your projects</p>
                                <CopyCommand command={`${runner} allow "C:\\laragon\\www"`} />
                            </li>
                            <li>
                                <p className="mb-1">3. Start it (keep the window open)</p>
                                <CopyCommand command={`${runner} start`} />
                            </li>
                        </ol>
                        <p className="text-muted-foreground text-xs">
                            On another PC, copy the <code className="font-mono">runner</code> folder from the Studio repository and use its path
                            instead.
                        </p>
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">Generate a one-time code to connect a PC to this organization.</p>
                )}

                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="ghost">Done</Button>
                    </DialogClose>
                    <Button
                        variant={pairing ? 'outline' : 'default'}
                        disabled={processing}
                        onClick={() => post(route('runners.pair'), { preserveScroll: true })}
                    >
                        {processing && <LoaderCircle className="animate-spin" />}
                        {pairing ? 'New code' : 'Generate pairing code'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

function RevokeRunner({ runner }: { runner: LocalRunner }) {
    const { delete: destroy, processing } = useForm({});

    return (
        <Dialog>
            <DialogTrigger asChild>
                <Button variant="ghost" size="sm" className="text-muted-foreground hover:text-danger">
                    Revoke
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Revoke {runner.name}?</DialogTitle>
                    <DialogDescription>
                        Its token stops working immediately and pending scans or workspace operations for it are cancelled. Files on that PC are not
                        touched. Pair it again to reconnect.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button variant="ghost">Cancel</Button>
                    </DialogClose>
                    <Button
                        variant="destructive"
                        disabled={processing}
                        onClick={() => destroy(route('runners.revoke', runner.id), { preserveScroll: true })}
                    >
                        <Ban />
                        Revoke runner
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
