import { EAJ_MARK_A, EAJ_MARK_E, EAJ_MARK_VIEWBOX } from '@/components/brand/eaj-mark';
import { useReducedMotion } from '@/hooks/use-reduce-motion';
import { cn } from '@/lib/utils';

interface ScanProgressProps {
    title: string;
    /** Real step text reported by the runner — never invented. */
    step: string | null;
    waitingForRunner: boolean;
    className?: string;
}

/**
 * Long operations (Plan §7.7): the monogram outline with a crimson stroke
 * tracing it, plus the real step text from the PC. It only renders while a
 * persisted command is queued or running.
 */
export function ScanProgress({ title, step, waitingForRunner, className }: ScanProgressProps) {
    const reduced = useReducedMotion();

    return (
        <div className={cn('flex items-center gap-4', className)} role="status" aria-live="polite">
            <svg viewBox={EAJ_MARK_VIEWBOX} className="size-12 shrink-0" aria-hidden="true">
                <path d={`${EAJ_MARK_E} ${EAJ_MARK_A}`} className="fill-none stroke-[var(--border)]" strokeWidth={1.5} />
                {!waitingForRunner && (
                    <path
                        d={`${EAJ_MARK_E} ${EAJ_MARK_A}`}
                        className="stroke-brand-crimson fill-none"
                        strokeWidth={1.5}
                        strokeLinecap="round"
                        pathLength={100}
                        strokeDasharray="22 78"
                        data-motion="loop"
                        style={reduced ? undefined : { animation: 'eaj-trace 1.8s linear infinite' }}
                    />
                )}
            </svg>
            <div className="min-w-0">
                <p className="text-heading text-sm font-medium">{title}</p>
                <p className="text-muted-foreground truncate text-xs">
                    {waitingForRunner ? 'Queued — waiting for the runner on that PC to pick it up' : (step ?? 'Started on the runner…')}
                </p>
            </div>
        </div>
    );
}
