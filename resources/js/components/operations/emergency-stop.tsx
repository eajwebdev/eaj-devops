import InputError from '@/components/input-error';
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
import { Label } from '@/components/ui/label';
import { type SharedData } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { CirclePlay, LoaderCircle, OctagonX } from 'lucide-react';
import { useEffect, useState, type FormEventHandler } from 'react';

type Scope = 'organization' | 'global';

/**
 * STOP ALL AI OPERATIONS (Plan §57). Anyone doing the work can stop; only
 * owners can resume, after seeing exactly which work will resume.
 */
export function EmergencyStop() {
    const { operations, organization, auth } = usePage<SharedData>().props;
    const permissions = organization?.permissions ?? [];
    const superAdmin = auth.user?.is_super_admin ?? false;

    if (!operations) {
        return null;
    }

    if (operations.halted) {
        const canResume = operations.scope === 'global' ? superAdmin : permissions.includes('operations.resume');

        return canResume && operations.scope ? <ResumeDialog scope={operations.scope} /> : null;
    }

    return permissions.includes('operations.halt') ? <StopDialog canStopPlatform={superAdmin} /> : null;
}

function StopDialog({ canStopPlatform }: { canStopPlatform: boolean }) {
    const [open, setOpen] = useState(false);
    const { data, setData, post, processing, errors, reset } = useForm<{ reason: string; scope: Scope }>({ reason: '', scope: 'organization' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('operations.halt'), {
            preserveScroll: true,
            onSuccess: () => {
                reset();
                setOpen(false);
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm" className="border-danger/40 text-danger hover:bg-danger/10 hover:text-danger">
                    <OctagonX />
                    <span className="hidden md:inline">Stop AI</span>
                </Button>
            </DialogTrigger>
            <DialogContent>
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>Stop all AI operations?</DialogTitle>
                        <DialogDescription>
                            No new steps will start. Running work stops at its next safe point and is kept at its last checkpoint. Workspaces, logs
                            and task state are preserved. An organization owner must resume.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="halt-reason">Reason (recorded in the audit log)</Label>
                        <textarea
                            id="halt-reason"
                            required
                            minLength={3}
                            maxLength={500}
                            rows={3}
                            value={data.reason}
                            onChange={(e) => setData('reason', e.target.value)}
                            placeholder="e.g. Agent is touching files outside the task scope"
                            className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                        />
                        <InputError message={errors.reason} />
                    </div>
                    {canStopPlatform && (
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="accent-brand-crimson size-4"
                                checked={data.scope === 'global'}
                                onChange={(e) => setData('scope', e.target.checked ? 'global' : 'organization')}
                            />
                            Stop every organization on the platform (Super Admin)
                        </label>
                    )}
                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Keep running
                            </Button>
                        </DialogClose>
                        <Button type="submit" variant="destructive" disabled={processing}>
                            {processing ? <LoaderCircle className="animate-spin" /> : <OctagonX />}
                            Stop all AI operations
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

function ResumeDialog({ scope }: { scope: Scope }) {
    const [open, setOpen] = useState(false);
    const [preview, setPreview] = useState<{ id: number; type: string }[] | null>(null);
    const [previewError, setPreviewError] = useState(false);
    const { post, processing } = useForm({ scope });

    useEffect(() => {
        if (!open) {
            return;
        }

        setPreview(null);
        setPreviewError(false);

        fetch(route('operations.preview', { scope }), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
            .then((r) => (r.ok ? r.json() : Promise.reject()))
            .then((body: { workflows: { id: number; type: string }[] }) => setPreview(body.workflows))
            .catch(() => setPreviewError(true));
    }, [open, scope]);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button size="sm" variant="outline">
                    <CirclePlay />
                    <span className="hidden md:inline">Resume AI</span>
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{scope === 'global' ? 'Resume AI operations platform-wide?' : 'Resume AI operations?'}</DialogTitle>
                    <DialogDescription>Paused work continues from its last checkpoint.</DialogDescription>
                </DialogHeader>
                <div className="bg-muted rounded-md p-3 text-sm" aria-live="polite">
                    {previewError && <p className="text-danger">Could not load the list of paused work. Close and try again.</p>}
                    {!previewError && preview === null && <div className="skeleton h-5 w-48" />}
                    {preview?.length === 0 && <p>No paused workflows — resuming only re-enables new work.</p>}
                    {preview && preview.length > 0 && (
                        <>
                            <p className="mb-2 font-medium">{preview.length} workflow(s) will resume:</p>
                            <ul className="text-muted-foreground font-mono text-xs">
                                {preview.map((w) => (
                                    <li key={w.id}>
                                        #{w.id} · {w.type}
                                    </li>
                                ))}
                            </ul>
                        </>
                    )}
                </div>
                <DialogFooter className="gap-2">
                    <DialogClose asChild>
                        <Button type="button" variant="ghost">
                            Stay stopped
                        </Button>
                    </DialogClose>
                    <Button
                        disabled={processing || preview === null}
                        onClick={() => post(route('operations.resume'), { preserveScroll: true, onSuccess: () => setOpen(false) })}
                    >
                        {processing ? <LoaderCircle className="animate-spin" /> : <CirclePlay />}
                        Resume operations
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
