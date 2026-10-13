import { cn } from '@/lib/utils';
import { type HealthReport, type HealthStatus } from '@/types';
import { CheckCircle2, CircleAlert, XCircle, type LucideIcon } from 'lucide-react';

const status: Record<HealthStatus, { icon: LucideIcon; className: string; label: string }> = {
    ok: { icon: CheckCircle2, className: 'text-success', label: 'OK' },
    degraded: { icon: CircleAlert, className: 'text-warning', label: 'Degraded' },
    down: { icon: XCircle, className: 'text-danger', label: 'Down' },
};

export function HealthPanel({ health }: { health: HealthReport }) {
    return (
        <ul className="divide-border divide-y">
            {health.checks.map((check) => {
                const s = status[check.status];

                return (
                    <li key={check.key} className="flex items-start gap-3 py-2.5 first:pt-0 last:pb-0">
                        <s.icon className={cn('mt-0.5 size-4 shrink-0', s.className)} aria-hidden="true" />
                        <div className="min-w-0 flex-1">
                            <p className="text-heading flex items-center justify-between gap-2 text-sm">
                                {check.label}
                                <span className={cn('text-xs font-medium', s.className)}>{s.label}</span>
                            </p>
                            <p className="text-muted-foreground text-xs">{check.detail}</p>
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
