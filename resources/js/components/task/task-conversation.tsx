import { Button } from '@/components/ui/button';
import { relativeTime } from '@/lib/events';
import { cn } from '@/lib/utils';
import { type TaskDetail } from '@/types';
import { useForm } from '@inertiajs/react';
import { LoaderCircle, MessageSquare, MessageSquareWarning, Send, Wand2 } from 'lucide-react';
import { type FormEventHandler } from 'react';

const KIND = {
    comment: { label: 'Comment', icon: MessageSquare, className: '' },
    instruction: { label: 'Instruction for the AI team', icon: Wand2, className: 'border-l-brand-crimson border-l-2' },
    changes_requested: { label: 'Changes requested', icon: MessageSquareWarning, className: 'border-l-warning border-l-2' },
};

/**
 * The conversation on a task (Plan §95 "send new instruction", "request
 * changes"). Instructions are what the agents read from Phase 4 on.
 */
export function TaskConversation({ task, projectSlug, canManage }: { task: TaskDetail; projectSlug: string; canManage: boolean }) {
    const { data, setData, post, processing, reset } = useForm<{ body: string; kind: 'comment' | 'instruction' }>({ body: '', kind: 'comment' });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('tasks.comments.store', [projectSlug, task.number]), { preserveScroll: true, onSuccess: () => reset('body') });
    };

    return (
        <div className="space-y-4">
            {task.comments.length === 0 ? (
                <p className="text-muted-foreground text-sm">No comments yet.</p>
            ) : (
                <ol className="space-y-3">
                    {task.comments.map((c) => {
                        const k = KIND[c.kind];

                        return (
                            <li key={c.id} className={cn('bg-muted/50 rounded-md px-3 py-2', k.className)}>
                                <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                    <k.icon className="size-3.5" />
                                    <span className="text-heading font-medium">{c.author ?? 'Someone'}</span>
                                    {c.kind !== 'comment' && <span>· {k.label}</span>}
                                    {c.created_at && <span>· {relativeTime(c.created_at)}</span>}
                                </p>
                                <p className="text-foreground mt-1 text-sm whitespace-pre-wrap">{c.body}</p>
                            </li>
                        );
                    })}
                </ol>
            )}

            {canManage && (
                <form onSubmit={submit} className="space-y-2">
                    <textarea
                        aria-label="Write a comment"
                        rows={3}
                        maxLength={10000}
                        value={data.body}
                        onChange={(e) => setData('body', e.target.value)}
                        placeholder={
                            data.kind === 'instruction'
                                ? 'e.g. Reuse the existing export button style. Do not change the payment module.'
                                : 'Write a comment…'
                        }
                        className="border-input bg-background focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                    />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <label className="text-muted-foreground flex items-center gap-2 text-xs">
                            <input
                                type="checkbox"
                                className="accent-brand-crimson size-4"
                                checked={data.kind === 'instruction'}
                                onChange={(e) => setData('kind', e.target.checked ? 'instruction' : 'comment')}
                            />
                            Instruction for the AI team
                        </label>
                        <Button size="sm" disabled={processing || !data.body.trim()}>
                            {processing ? <LoaderCircle className="animate-spin" /> : <Send />}
                            {data.kind === 'instruction' ? 'Send instruction' : 'Comment'}
                        </Button>
                    </div>
                </form>
            )}
        </div>
    );
}
