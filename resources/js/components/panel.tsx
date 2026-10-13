import { cn } from '@/lib/utils';
import { type ReactNode } from 'react';

interface PanelProps {
    title: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}

export function Panel({ title, action, children, className }: PanelProps) {
    return (
        <section className={cn('bg-card border-border flex min-w-0 flex-col rounded-lg border', className)}>
            <header className="flex items-center justify-between gap-2 px-4 pt-4 pb-3">
                <h2 className="eyebrow">{title}</h2>
                {action}
            </header>
            <div className="min-w-0 flex-1 px-4 pb-4">{children}</div>
        </section>
    );
}
