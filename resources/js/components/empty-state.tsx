import { EajMark } from '@/components/brand/eaj-mark';
import { cn } from '@/lib/utils';
import { type LucideIcon } from 'lucide-react';
import { type ReactNode } from 'react';

interface EmptyStateProps {
    icon: LucideIcon;
    title: string;
    description: ReactNode;
    action?: ReactNode;
    className?: string;
}

/**
 * Explains why something is empty and what to do next (Plan §9). Carries
 * the faint EA watermark — empty states only (Plan §7.3).
 */
export function EmptyState({ icon: Icon, title, description, action, className }: EmptyStateProps) {
    return (
        <div className={cn('border-border relative overflow-hidden rounded-lg border border-dashed px-6 py-10 text-center', className)}>
            <EajMark variant="mono" className="eaj-watermark" aria-hidden="true" />
            <div className="bg-muted mx-auto flex size-10 items-center justify-center rounded-md">
                <Icon className="text-muted-foreground size-5" aria-hidden="true" />
            </div>
            <h3 className="mt-4 text-sm font-semibold">{title}</h3>
            <p className="text-muted-foreground mx-auto mt-1 max-w-sm text-sm">{description}</p>
            {action && <div className="mt-5 flex justify-center">{action}</div>}
        </div>
    );
}
