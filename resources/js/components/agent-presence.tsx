import { AgentAvatar } from '@/components/agent-avatar';
import { type AgentStatus } from '@/components/agent-status';
import { AnimatedNumber } from '@/components/animated-number';
import { cn } from '@/lib/utils';
import { type AgentRole } from '@/types';

export type PresenceStatus = AgentStatus | 'STALLED';

export interface PresenceProgress {
    file: string | null;
    passed: number | null;
    failed: number | null;
    total: number | null;
}

/**
 * The avatar's status ring (Plan §7.5–7.6). Every visual is bound to the
 * persisted state the server reports — nothing here runs on a timer. Loops
 * carry data-motion="loop" so reduced-motion and hidden-tab rules stop them,
 * and `animate` is false while the card is off-screen (IntersectionObserver).
 */
export function AgentStatusRing({ role, status, animate }: { role: AgentRole; status: PresenceStatus; animate: boolean }) {
    const loop = animate ? { 'data-motion': 'loop' } : {};
    const crimsonRing = ['INITIALIZING', 'PLANNING', 'READING', 'WORKING', 'TESTING', 'REVIEWING'].includes(status);

    return (
        <span className="relative inline-flex p-1" aria-hidden="true">
            {/* Base ring: colour + style per state (always paired with a text label elsewhere). */}
            <span
                // A one-time attention pulse / shake replays only when the state changes (key).
                key={status}
                className={cn(
                    'absolute inset-0 rounded-[12px] border-2',
                    status === 'IDLE' && 'border-navy-500',
                    status === 'COMPLETED' && 'border-success',
                    status === 'QUEUED' && cn('border-info border-dashed', animate && 'motion-dash'),
                    crimsonRing && 'border-brand-crimson',
                    (status === 'INITIALIZING' || status === 'PLANNING') && animate && 'motion-breathe',
                    status === 'FIXING' && 'border-warning',
                    (status === 'WAITING_FOR_TOOL' || status === 'WAITING_FOR_AGENT') && 'border-info',
                    (status === 'BLOCKED' || status === 'PAUSED') && 'border-warning motion-attention',
                    status === 'FAILED' && 'border-danger motion-shake',
                    status === 'STALLED' && 'border-danger border-dashed',
                    status === 'CANCELLED' && 'border-navy-500 border-dashed',
                )}
                {...(status === 'QUEUED' || status === 'INITIALIZING' || status === 'PLANNING' ? loop : {})}
            />

            {/* WORKING: crimson arc orbits (1.6s) with a soft glow pulse (2.4s). REVIEWING: half speed. FIXING: warning colour. */}
            {animate && (status === 'WORKING' || status === 'REVIEWING' || status === 'FIXING') && (
                <span
                    {...loop}
                    className={cn(
                        'absolute inset-0 rounded-[12px] border-2 border-transparent',
                        status === 'FIXING' ? 'border-t-warning' : 'border-t-brand-crimson',
                        status === 'REVIEWING' ? 'motion-orbit-slow' : 'motion-orbit',
                    )}
                />
            )}
            {animate && status === 'WORKING' && (
                <span {...loop} className="bg-brand-crimson/40 motion-glow absolute -inset-1 -z-10 rounded-[14px] blur-[2px]" />
            )}

            <AgentAvatar role={role} />

            {status === 'COMPLETED' && (
                <span className="bg-success absolute -right-1 -bottom-1 flex size-4 items-center justify-center rounded-full ring-2 ring-[var(--card)]">
                    <svg viewBox="0 0 12 12" className="size-2.5">
                        <path
                            d="M2.5 6.2 5 8.5l4.5-5"
                            fill="none"
                            stroke="white"
                            strokeWidth="1.8"
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            className="motion-draw"
                        />
                    </svg>
                </span>
            )}
        </span>
    );
}

/**
 * The live detail under an agent: a scanning bar over the file being read,
 * a counting test tally, or the waiting ellipsis (Plan §7.6). Renders only
 * what the last persisted activity report contained.
 */
export function AgentProgress({ status, progress, animate }: { status: PresenceStatus; progress: PresenceProgress; animate: boolean }) {
    const loop = animate ? { 'data-motion': 'loop' } : {};

    if (status === 'READING' && progress.file) {
        return (
            <span className="bg-muted relative mt-2 flex overflow-hidden rounded px-2 py-1 font-mono text-[0.6875rem]">
                {animate && <span {...loop} className="bg-brand-crimson/20 motion-scan absolute inset-y-0 left-0 w-1/3" />}
                <span key={progress.file} className="motion-fade relative min-w-0 truncate" title={progress.file}>
                    {progress.file}
                </span>
            </span>
        );
    }

    if ((status === 'TESTING' || status === 'FIXING') && progress.total) {
        const passed = progress.passed ?? 0;
        const failed = progress.failed ?? Math.max(0, progress.total - passed);
        const segments = Math.min(progress.total, 24);
        const filled = Math.round((passed / progress.total) * segments);

        return (
            <span className="mt-2 block">
                <span className="tabular flex items-center justify-between text-[0.6875rem]">
                    <span className="text-heading">
                        <AnimatedNumber value={passed} />/{progress.total} passed
                    </span>
                    {status === 'FIXING' && failed > 0 && (
                        <span className="text-warning">
                            <AnimatedNumber value={failed} /> failing
                        </span>
                    )}
                </span>
                <span className="mt-1 flex gap-px" aria-hidden="true">
                    {Array.from({ length: segments }, (_, i) => (
                        <span
                            key={i}
                            className={cn(
                                'h-1.5 flex-1 rounded-[1px] transition-colors duration-200',
                                i < filled ? 'bg-success' : status === 'FIXING' ? 'bg-warning/40' : 'bg-muted',
                            )}
                        />
                    ))}
                </span>
            </span>
        );
    }

    return null;
}

/** Three dots — "working" (animated) or "waiting" (static ellipsis, §7.6: no orbit, it is not working). */
export function ActivityDots({ working, animate }: { working: boolean; animate: boolean }) {
    return working ? (
        <span {...(animate ? { 'data-motion': 'loop' } : {})} className={cn('ml-1 inline-flex', animate && 'motion-breathe')} aria-hidden="true">
            •••
        </span>
    ) : (
        <span className="ml-0.5" aria-hidden="true">
            …
        </span>
    );
}
