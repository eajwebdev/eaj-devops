import { toneSurface, type Tone } from '@/lib/events';
import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';

/**
 * Status is never conveyed by colour alone — always icon + label (Plan §7.2).
 */
export function StatusPill({ tone, icon: Icon, label, className }: { tone: Tone; icon: LucideIcon; label: string; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset',
                toneSurface[tone],
                className,
            )}
        >
            <Icon className="size-3.5" aria-hidden="true" />
            {label}
        </span>
    );
}
