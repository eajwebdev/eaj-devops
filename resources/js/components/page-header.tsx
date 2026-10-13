import { type ReactNode } from 'react';

interface PageHeaderProps {
    eyebrow?: string;
    title: string;
    description?: ReactNode;
    actions?: ReactNode;
}

export function PageHeader({ eyebrow, title, description, actions }: PageHeaderProps) {
    return (
        <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div className="min-w-0 space-y-2">
                {eyebrow && <p className="eyebrow">{eyebrow}</p>}
                <h1 className="truncate text-2xl font-semibold tracking-tight">{title}</h1>
                <span className="brand-rule" aria-hidden="true" />
                {description && <p className="text-muted-foreground max-w-2xl pt-1 text-sm">{description}</p>}
            </div>
            {actions && <div className="flex shrink-0 items-center gap-2">{actions}</div>}
        </div>
    );
}
