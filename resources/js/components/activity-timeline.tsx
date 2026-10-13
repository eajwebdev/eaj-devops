import { useReducedMotion } from '@/hooks/use-reduce-motion';
import { describeEvent, isFeedEvent, relativeTime, toneText } from '@/lib/events';
import { cn } from '@/lib/utils';
import { type StudioEvent } from '@/types';
import { Link } from '@inertiajs/react';
import { AnimatePresence, motion } from 'motion/react';
import { useEffect, useState } from 'react';

interface ActivityTimelineProps {
    /** Oldest first, as delivered by the stream; rendered newest first. */
    events: StudioEvent[];
    showProject?: boolean;
    showAgent?: boolean;
    absoluteTime?: boolean;
    className?: string;
}

const toneBorder = {
    success: 'border-success text-success',
    warning: 'border-warning text-warning',
    danger: 'border-danger text-danger',
    info: 'border-info text-info',
    neutral: 'border-border text-muted-foreground',
    brand: 'border-brand-crimson text-brand-crimson',
};

/**
 * Live activity (Plan §26). New events slide in from the top (y −8px → 0,
 * 240ms); they arrive in batches per sync, so the list never jitters.
 */
export function ActivityTimeline({ events, showProject = false, showAgent = false, absoluteTime = false, className }: ActivityTimelineProps) {
    const reduced = useReducedMotion();
    const now = useNow(30_000);
    const ordered = events.filter(isFeedEvent).reverse();

    return (
        <ol className={cn('relative space-y-1', className)}>
            <AnimatePresence initial={false}>
                {ordered.map((event) => {
                    const d = describeEvent(event);
                    const agent = event.payload.agent as { role?: string } | undefined;

                    return (
                        <motion.li
                            key={event.id}
                            layout={!reduced}
                            initial={reduced ? { opacity: 0 } : { opacity: 0, y: -8 }}
                            animate={{ opacity: 1, y: 0 }}
                            transition={{ duration: reduced ? 0.1 : 0.24, ease: [0.22, 1, 0.36, 1] }}
                            className="hover:bg-muted/60 flex gap-3 rounded-md px-2 py-2"
                        >
                            {showAgent && agent?.role ? (
                                <span
                                    className={cn(
                                        'mt-0.5 inline-flex h-5 min-w-7 shrink-0 items-center justify-center rounded border px-1 font-mono text-[0.62rem] font-semibold',
                                        toneBorder[d.tone],
                                    )}
                                >
                                    {agent.role}
                                </span>
                            ) : (
                                <d.icon className={cn('mt-0.5 size-4 shrink-0', toneText[d.tone])} aria-hidden="true" />
                            )}
                            <div className="min-w-0 flex-1">
                                <p className="text-heading text-sm leading-snug">{d.title}</p>
                                {d.detail && <p className="text-muted-foreground truncate text-xs">{d.detail}</p>}
                                <p className="text-muted-foreground mt-0.5 flex flex-wrap items-center gap-x-1.5 text-xs">
                                    <time
                                        className={absoluteTime ? 'font-mono' : undefined}
                                        dateTime={event.occurred_at}
                                        title={new Date(event.occurred_at).toLocaleString()}
                                    >
                                        {absoluteTime ? eventTime(event.occurred_at) : relativeTime(event.occurred_at, now)}
                                    </time>
                                    {event.actor && <span>· {event.actor.name}</span>}
                                    {showProject && event.project && (
                                        <span>
                                            ·{' '}
                                            <Link href={route('projects.show', event.project.slug)} className="font-mono hover:underline">
                                                {event.project.key}
                                            </Link>
                                        </span>
                                    )}
                                </p>
                            </div>
                        </motion.li>
                    );
                })}
            </AnimatePresence>
        </ol>
    );
}

function eventTime(value: string): string {
    return new Intl.DateTimeFormat(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false,
    }).format(new Date(value));
}

function useNow(intervalMs: number): number {
    const [now, setNow] = useState(() => Date.now());

    useEffect(() => {
        const timer = window.setInterval(() => setNow(Date.now()), intervalMs);

        return () => window.clearInterval(timer);
    }, [intervalMs]);

    return now;
}
