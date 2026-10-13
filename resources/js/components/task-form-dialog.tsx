import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type TaskDetail, type TaskOptions } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import { type FormEventHandler } from 'react';

type TaskForm = {
    title: string;
    description: string;
    criteria: string;
    type: string;
    priority: string;
    status: string;
    token_budget: string;
};

const fieldClass =
    'border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden';

/**
 * New task (Plan §66: plain language) or edit. Acceptance criteria are one per
 * line. Turning the request into structured work is the AI Orchestrator's job
 * once the task is Ready ("Plan with AI team"); the deterministic impact analysis runs on save.
 */
export function TaskFormDialog({
    open,
    onOpenChange,
    projectSlug,
    options,
    task,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    projectSlug: string;
    options: TaskOptions;
    task?: TaskDetail;
}) {
    const { data, setData, post, patch, processing, errors, reset, transform } = useForm<TaskForm>({
        title: task?.title ?? '',
        description: task?.description ?? '',
        criteria: (task?.acceptance_criteria ?? []).join('\n'),
        type: task?.type ?? 'feature',
        priority: task?.priority ?? 'MEDIUM',
        status: 'BACKLOG',
        token_budget: task?.token_budget ? String(task.token_budget) : '',
    });

    transform(({ criteria, status, token_budget, ...rest }) => ({
        ...rest,
        token_budget: token_budget === '' ? null : Number(token_budget),
        acceptance_criteria: criteria
            .split('\n')
            .map((l) => l.replace(/^\s*[-*•]\s*/, '').trim())
            .filter(Boolean),
        ...(task ? { version: task.version } : { status }),
    }));

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        const done = {
            preserveScroll: true,
            onSuccess: () => {
                onOpenChange(false);
                if (!task) reset();
            },
        };

        if (task) {
            patch(route('tasks.update', [projectSlug, task.number]), done);
        } else {
            post(route('tasks.store', projectSlug), done);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-w-2xl">
                <form onSubmit={submit} className="space-y-5">
                    <DialogHeader>
                        <DialogTitle>{task ? `Edit ${task.key}` : 'New task'}</DialogTitle>
                        <DialogDescription>
                            {task
                                ? 'Changing the title, description or criteria re-runs the impact analysis.'
                                : 'Describe the change in plain language. The impact analysis runs against the code index when you save.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-2">
                        <Label htmlFor="task-title">Title</Label>
                        <Input
                            id="task-title"
                            required
                            maxLength={200}
                            autoFocus
                            value={data.title}
                            onChange={(e) => setData('title', e.target.value)}
                            placeholder="Add expenses export to Excel"
                        />
                        <InputError message={errors.title} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="task-description">Request</Label>
                        <textarea
                            id="task-description"
                            rows={5}
                            maxLength={20000}
                            value={data.description}
                            onChange={(e) => setData('description', e.target.value)}
                            placeholder={'Only Admin and Super Admin can access it.\nBranch Managers must remain read-only.'}
                            className={fieldClass}
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="task-criteria">
                            Acceptance criteria <span className="text-muted-foreground font-normal">(one per line, optional)</span>
                        </Label>
                        <textarea
                            id="task-criteria"
                            rows={3}
                            value={data.criteria}
                            onChange={(e) => setData('criteria', e.target.value)}
                            className={fieldClass}
                        />
                        <InputError message={(errors as Record<string, string>).acceptance_criteria} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="task-type">Type</Label>
                            <select
                                id="task-type"
                                value={data.type}
                                onChange={(e) => setData('type', e.target.value)}
                                className={`${fieldClass} h-10 py-0`}
                            >
                                {options.types.map((t) => (
                                    <option key={t.value} value={t.value}>
                                        {t.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="task-priority">Priority</Label>
                            <select
                                id="task-priority"
                                value={data.priority}
                                onChange={(e) => setData('priority', e.target.value)}
                                className={`${fieldClass} h-10 py-0`}
                            >
                                {options.priorities.map((p) => (
                                    <option key={p.value} value={p.value}>
                                        {p.label}
                                    </option>
                                ))}
                            </select>
                        </div>
                        {!task && (
                            <div className="grid gap-2">
                                <Label htmlFor="task-status">Start in</Label>
                                <select
                                    id="task-status"
                                    value={data.status}
                                    onChange={(e) => setData('status', e.target.value)}
                                    className={`${fieldClass} h-10 py-0`}
                                >
                                    <option value="BACKLOG">Backlog</option>
                                    <option value="READY">Ready</option>
                                </select>
                            </div>
                        )}
                    </div>
                    <div className="grid max-w-xs gap-2">
                        <Label htmlFor="task-token-budget">
                            Task token cap <span className="text-muted-foreground font-normal">(optional)</span>
                        </Label>
                        <Input
                            id="task-token-budget"
                            type="number"
                            min={100}
                            max={100000000}
                            value={data.token_budget}
                            onChange={(e) => setData('token_budget', e.target.value)}
                            placeholder="Use the agent default"
                        />
                        <InputError message={errors.token_budget} />
                    </div>
                    <InputError message={(errors as Record<string, string>).task} />

                    <DialogFooter className="gap-2">
                        <DialogClose asChild>
                            <Button type="button" variant="ghost">
                                Cancel
                            </Button>
                        </DialogClose>
                        <Button type="submit" disabled={processing}>
                            {processing && <LoaderCircle className="animate-spin" />}
                            {task ? 'Save changes' : 'Create task'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
