import { AnimatedNumber } from '@/components/animated-number';
import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';

interface MetricTileProps {
    label: string;
    value: number;
    icon: LucideIcon;
    hint: string;
    attention?: boolean;
}

/**
 * One owner question per tile (Plan §104) — the hint says what the number means.
 */
export function MetricTile({ label, value, icon: Icon, hint, attention = false }: MetricTileProps) {
    return (
        <div className={cn('bg-card border-border rounded-lg border p-4', attention && value > 0 && 'border-warning/50')}>
            <div className="flex items-center justify-between">
                <p className="eyebrow">{label}</p>
                <Icon className={cn('size-4', attention && value > 0 ? 'text-warning' : 'text-muted-foreground')} aria-hidden="true" />
            </div>
            <p className="text-heading mt-3 text-3xl font-semibold">
                <AnimatedNumber value={value} />
            </p>
            <p className="text-muted-foreground mt-1 text-xs">{hint}</p>
        </div>
    );
}
