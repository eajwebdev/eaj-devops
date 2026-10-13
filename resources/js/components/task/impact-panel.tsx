import { AgentAvatar } from '@/components/agent-avatar';
import { relativeTime } from '@/lib/events';
import { cn } from '@/lib/utils';
import { type ImpactLevel, type TaskImpact } from '@/types';
import { Database, FileCode2, FlaskConical, Info, LayoutTemplate, Server, ShieldCheck, type LucideIcon } from 'lucide-react';

const AREAS: { key: keyof TaskImpact['areas']; label: string; icon: LucideIcon }[] = [
    { key: 'backend', label: 'Backend', icon: Server },
    { key: 'frontend', label: 'Frontend', icon: LayoutTemplate },
    { key: 'database', label: 'Database', icon: Database },
    { key: 'security', label: 'Security', icon: ShieldCheck },
];

const LEVEL: Record<ImpactLevel, { label: string; className: string; bars: number }> = {
    none: { label: 'None', className: 'text-muted-foreground', bars: 0 },
    low: { label: 'Low', className: 'text-info', bars: 1 },
    medium: { label: 'Medium', className: 'text-warning', bars: 2 },
    high: { label: 'High', className: 'text-danger', bars: 3 },
};

const COMPLEXITY: Record<TaskImpact['complexity'], string> = { S: 'Small', M: 'Medium', L: 'Large', XL: 'Extra large' };

/**
 * Change-impact analysis (Plan §67). Every line shows the evidence it came
 * from, so the owner can judge it before any work starts.
 */
export function ImpactPanel({ impact, analyzedAt }: { impact: TaskImpact; analyzedAt: string | null }) {
    return (
        <div className="space-y-5">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                {AREAS.map(({ key, label, icon: Icon }) => {
                    const area = impact.areas[key];
                    const level = LEVEL[area.level];

                    return (
                        <div key={key} className="border-border rounded-md border p-2.5" title={area.reasons.join(' · ') || 'No impact found'}>
                            <p className="text-muted-foreground flex items-center gap-1.5 text-xs">
                                <Icon className="size-3.5" />
                                {label}
                            </p>
                            <p className={cn('mt-1 flex items-center gap-1.5 text-sm font-medium', level.className)}>
                                <span className="flex gap-0.5" aria-hidden="true">
                                    {[1, 2, 3].map((b) => (
                                        <span key={b} className={cn('h-2.5 w-1 rounded-sm', b <= level.bars ? 'bg-current' : 'bg-muted')} />
                                    ))}
                                </span>
                                {level.label}
                            </p>
                            {area.reasons[0] && <p className="text-muted-foreground mt-0.5 truncate text-[0.6875rem]">{area.reasons[0]}</p>}
                        </div>
                    );
                })}
            </div>

            <div className="flex flex-wrap items-end gap-x-8 gap-y-3">
                <div>
                    <p className="eyebrow">Complexity</p>
                    <p className="text-heading text-sm">
                        <span className="font-mono font-semibold">{impact.complexity}</span> · {COMPLEXITY[impact.complexity]}
                    </p>
                </div>
                <div>
                    <p className="eyebrow">Token estimate</p>
                    <p className="text-heading tabular text-sm">≈ {impact.token_estimate.toLocaleString()}</p>
                </div>
                <div>
                    <p className="eyebrow">Estimated cost</p>
                    <p className="text-heading tabular text-sm">
                        {impact.estimated_cost
                            ? `â‰ˆ $${impact.estimated_cost.amount.toFixed(4)} ${impact.estimated_cost.currency}`
                            : 'Add model pricing to estimate'}
                    </p>
                    {impact.estimated_cost && impact.estimated_cost.priced_models < impact.estimated_cost.total_models && (
                        <p className="text-warning text-xs">Partial estimate; some routed models are unpriced.</p>
                    )}
                </div>
                <div>
                    <p className="eyebrow mb-1">Recommended team</p>
                    <div className="flex flex-wrap gap-1.5">
                        {impact.agents.map((a) => (
                            <AgentAvatar key={a.role} role={a.role} size="sm" hint={a.reason} />
                        ))}
                    </div>
                </div>
            </div>

            {impact.notes.length > 0 && (
                <ul className="space-y-1.5">
                    {impact.notes.map((n) => (
                        <li key={n} className="bg-info/10 flex items-start gap-2 rounded-md px-3 py-2 text-xs">
                            <Info className="text-info mt-px size-3.5 shrink-0" />
                            {n}
                        </li>
                    ))}
                </ul>
            )}

            <div className="grid gap-4 md:grid-cols-2">
                <div>
                    <p className="eyebrow mb-2">Expected files ({impact.expected_files.length})</p>
                    {impact.expected_files.length ? (
                        <ul className="space-y-1.5">
                            {impact.expected_files.map((f) => (
                                <li key={f.path} className="text-xs">
                                    <p className="text-heading flex items-center gap-1.5 font-mono break-all">
                                        <FileCode2 className="text-muted-foreground size-3.5 shrink-0" />
                                        {f.path}
                                    </p>
                                    <p className="text-muted-foreground pl-5">{f.reasons.join(' · ')}</p>
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-muted-foreground text-xs">
                            Nothing in the code index matches yet — the request may need new files or clearer wording.
                        </p>
                    )}
                </div>
                <div>
                    <p className="eyebrow mb-2">Expected tests ({impact.expected_tests.length})</p>
                    {impact.expected_tests.length ? (
                        <ul className="space-y-1.5">
                            {impact.expected_tests.map((t) => (
                                <li key={t.path} className="text-heading flex items-center gap-1.5 font-mono text-xs break-all">
                                    <FlaskConical className="text-muted-foreground size-3.5 shrink-0" />
                                    {t.path}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-muted-foreground text-xs">No related tests found — new tests will be written for this change (§87).</p>
                    )}
                </div>
            </div>

            <p className="text-muted-foreground text-xs">
                {impact.basis}
                {analyzedAt && ` Analyzed ${relativeTime(analyzedAt)}.`}
            </p>
        </div>
    );
}
