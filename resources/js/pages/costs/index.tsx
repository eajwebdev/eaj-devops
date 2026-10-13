import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { AlertTriangle, DollarSign, Gauge, Trash2, WalletCards } from 'lucide-react';
import { type FormEvent } from 'react';

type Scope = 'organization' | 'project' | 'task' | 'agent';
type Period = 'day' | 'month' | 'all_time';

interface Totals {
    input_tokens: number;
    cached_tokens: number;
    output_tokens: number;
    total_tokens: number;
    cost: number;
    unpriced_calls: number;
}

interface Row {
    id: number;
    label: string;
    tokens: number;
    cost: number;
    unpriced_calls: number;
}

interface Budget {
    id: number;
    scope_type: Scope;
    scope_id: number;
    scope_label: string;
    period: Period;
    max_tokens: number;
    max_cost: number | null;
    hard_stop: boolean;
    enabled: boolean;
}

interface Props {
    currency: string;
    summary: Record<'today' | 'week' | 'month', Totals>;
    economics: { average_cost_per_task: number; cost_per_completed_feature: number; tasks: number; completed_features: number };
    breakdowns: Record<'project' | 'task' | 'agent' | 'model', Row[]>;
    budgets: Budget[];
    targets: Record<Scope, { id: number; label: string }[]>;
    alerts: {
        id: number;
        scope: string;
        metric: 'tokens' | 'cost';
        threshold: number;
        used: number;
        limit: number;
        hard_stop: boolean;
        emitted_at: string;
    }[];
    can: { manage: boolean };
}

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Costs', href: '/costs' }];
const money = (value: number) => `$${value.toFixed(value < 1 ? 4 : 2)}`;
const count = (value: number) => new Intl.NumberFormat().format(value);

function SummaryCard({ label, totals }: { label: string; totals: Totals }) {
    return (
        <div className="bg-card border-border rounded-lg border p-4">
            <p className="eyebrow">{label}</p>
            <p className="text-heading mt-2 text-2xl font-semibold tabular-nums">{money(totals.cost)}</p>
            <p className="text-muted-foreground mt-1 text-xs tabular-nums">{count(totals.total_tokens)} tokens</p>
            <div className="text-muted-foreground mt-3 grid grid-cols-3 gap-2 text-[0.68rem] tabular-nums">
                <span>In {count(totals.input_tokens)}</span>
                <span>Cached {count(totals.cached_tokens)}</span>
                <span>Out {count(totals.output_tokens)}</span>
            </div>
            {totals.unpriced_calls > 0 && <p className="text-warning mt-2 text-xs">{totals.unpriced_calls} unpriced call(s) excluded</p>}
        </div>
    );
}

function Breakdown({ title, rows }: { title: string; rows: Row[] }) {
    return (
        <Panel title={title}>
            {rows.length ? (
                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-xs">
                            <tr>
                                <th className="pb-2 text-left font-medium">Name</th>
                                <th className="pb-2 text-right font-medium">Tokens</th>
                                <th className="pb-2 text-right font-medium">Cost</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr key={row.id} className="border-border border-t">
                                    <td className="py-2">
                                        {row.label}
                                        {row.unpriced_calls > 0 && (
                                            <span className="text-warning ml-1" title="Some calls have no configured price">
                                                *
                                            </span>
                                        )}
                                    </td>
                                    <td className="py-2 text-right tabular-nums">{count(row.tokens)}</td>
                                    <td className="py-2 text-right tabular-nums">{money(row.cost)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            ) : (
                <EmptyState
                    icon={DollarSign}
                    title="No usage this month"
                    description="Provider-reported usage appears here after the first AI call."
                />
            )}
        </Panel>
    );
}

function BudgetForm({ targets }: { targets: Props['targets'] }) {
    const form = useForm({
        scope_type: 'organization' as Scope,
        scope_id: targets.organization[0]?.id ?? 0,
        period: 'month' as Period,
        max_tokens: 100000,
        max_cost: '' as number | '',
        hard_stop: true as boolean,
        enabled: true as boolean,
    });

    function changeScope(scope: Scope) {
        form.setData((data) => ({ ...data, scope_type: scope, scope_id: targets[scope][0]?.id ?? 0 }));
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(route('costs.budgets.store'), { preserveScroll: true, onSuccess: () => form.reset('max_cost') });
    }

    return (
        <form onSubmit={submit} className="grid gap-3 md:grid-cols-2 xl:grid-cols-6 xl:items-end">
            <label className="space-y-1 text-xs">
                <span className="text-muted-foreground">Scope</span>
                <select
                    className="border-input bg-background h-9 w-full rounded-md border px-2"
                    value={form.data.scope_type}
                    onChange={(e) => changeScope(e.target.value as Scope)}
                >
                    {(['organization', 'project', 'task', 'agent'] as Scope[]).map((scope) => (
                        <option key={scope} value={scope}>
                            {scope[0].toUpperCase() + scope.slice(1)}
                        </option>
                    ))}
                </select>
            </label>
            <label className="space-y-1 text-xs">
                <span className="text-muted-foreground">Target</span>
                <select
                    className="border-input bg-background h-9 w-full rounded-md border px-2"
                    value={form.data.scope_id}
                    onChange={(e) => form.setData('scope_id', Number(e.target.value))}
                >
                    {targets[form.data.scope_type].map((target) => (
                        <option key={target.id} value={target.id}>
                            {target.label}
                        </option>
                    ))}
                </select>
            </label>
            <label className="space-y-1 text-xs">
                <span className="text-muted-foreground">Period</span>
                <select
                    className="border-input bg-background h-9 w-full rounded-md border px-2"
                    value={form.data.period}
                    onChange={(e) => form.setData('period', e.target.value as Period)}
                >
                    <option value="day">Daily</option>
                    <option value="month">Monthly</option>
                    <option value="all_time">All time</option>
                </select>
            </label>
            <div className="space-y-1">
                <Label htmlFor="budget-tokens" className="text-xs">
                    Token cap
                </Label>
                <Input
                    id="budget-tokens"
                    type="number"
                    min={100}
                    value={form.data.max_tokens}
                    onChange={(e) => form.setData('max_tokens', Number(e.target.value))}
                />
            </div>
            <div className="space-y-1">
                <Label htmlFor="budget-cost" className="text-xs">
                    Cost cap (USD)
                </Label>
                <Input
                    id="budget-cost"
                    type="number"
                    min="0.000001"
                    step="0.01"
                    placeholder="Optional"
                    value={form.data.max_cost}
                    onChange={(e) => form.setData('max_cost', e.target.value === '' ? '' : Number(e.target.value))}
                />
            </div>
            <div className="flex items-center gap-3">
                <label className="text-muted-foreground flex items-center gap-2 text-xs">
                    <input type="checkbox" checked={form.data.hard_stop} onChange={(e) => form.setData('hard_stop', e.target.checked)} /> Hard stop
                </label>
                <Button size="sm" disabled={form.processing || form.data.scope_id === 0}>
                    Save
                </Button>
            </div>
        </form>
    );
}

export default function Costs({ currency, summary, economics, breakdowns, budgets, targets, alerts, can }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Costs" />
            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    eyebrow="Phase 9 · Cost control"
                    title="Tokens & costs"
                    description={`Provider-reported usage, immutable ${currency} price snapshots, and enforceable budget policies. Warnings fire at 70%, 90%, and 100%.`}
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <SummaryCard label="Today" totals={summary.today} />
                    <SummaryCard label="This week" totals={summary.week} />
                    <SummaryCard label="This month" totals={summary.month} />
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="bg-card border-border rounded-lg border p-4">
                        <p className="eyebrow">Average cost / task</p>
                        <p className="text-heading mt-2 text-xl font-semibold tabular-nums">{money(economics.average_cost_per_task)}</p>
                        <p className="text-muted-foreground text-xs">Across {economics.tasks} task(s) this month</p>
                    </div>
                    <div className="bg-card border-border rounded-lg border p-4">
                        <p className="eyebrow">Cost / completed feature</p>
                        <p className="text-heading mt-2 text-xl font-semibold tabular-nums">{money(economics.cost_per_completed_feature)}</p>
                        <p className="text-muted-foreground text-xs">Across {economics.completed_features} completed feature(s) this month</p>
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Breakdown title="By project · this month" rows={breakdowns.project} />
                    <Breakdown title="By task · this month" rows={breakdowns.task} />
                    <Breakdown title="By agent · this month" rows={breakdowns.agent} />
                    <Breakdown title="By model · this month" rows={breakdowns.model} />
                </div>

                <Panel title="Budget policies" action={<Gauge className="text-muted-foreground size-4" />}>
                    {can.manage && (
                        <div className="border-border mb-4 border-b pb-4">
                            <BudgetForm targets={targets} />
                        </div>
                    )}
                    {budgets.length ? (
                        <div className="space-y-2">
                            {budgets.map((budget) => (
                                <div
                                    key={budget.id}
                                    className="border-border flex flex-wrap items-center justify-between gap-3 rounded-md border p-3 text-sm"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {budget.scope_label} · {budget.period.replace('_', ' ')}
                                        </p>
                                        <p className="text-muted-foreground text-xs">
                                            {count(budget.max_tokens)} tokens{budget.max_cost !== null && ` · ${money(budget.max_cost)}`} ·{' '}
                                            {budget.hard_stop ? 'hard stop' : 'alerts only'}
                                            {!budget.enabled && ' · disabled'}
                                        </p>
                                    </div>
                                    {can.manage && (
                                        <Button
                                            type="button"
                                            size="icon"
                                            variant="ghost"
                                            aria-label="Delete budget"
                                            onClick={() => router.delete(route('costs.budgets.destroy', budget.id), { preserveScroll: true })}
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                </div>
                            ))}
                        </div>
                    ) : (
                        <EmptyState
                            icon={WalletCards}
                            title="No shared budgets"
                            description="Run and task token caps still apply. Add a policy for project, organization, daily, or monthly control."
                        />
                    )}
                </Panel>

                <Panel title="Budget alerts" action={<AlertTriangle className="text-muted-foreground size-4" />}>
                    {alerts.length ? (
                        <ol className="space-y-2">
                            {alerts.map((alert) => (
                                <li
                                    key={alert.id}
                                    className="border-border flex items-center justify-between gap-3 border-b pb-2 text-sm last:border-0"
                                >
                                    <div>
                                        <span className={alert.threshold >= 90 ? 'text-danger font-medium' : 'text-warning font-medium'}>
                                            {alert.threshold}%
                                        </span>{' '}
                                        <span>
                                            {alert.scope} {alert.metric} budget
                                        </span>
                                        <p className="text-muted-foreground text-xs">
                                            {alert.metric === 'cost'
                                                ? `${money(alert.used)} of ${money(alert.limit)}`
                                                : `${count(alert.used)} of ${count(alert.limit)} tokens`}{' '}
                                            · {alert.hard_stop ? 'hard stop enabled' : 'warning only'}
                                        </p>
                                    </div>
                                </li>
                            ))}
                        </ol>
                    ) : (
                        <p className="text-muted-foreground text-sm">No budget thresholds have been crossed.</p>
                    )}
                </Panel>
            </div>
        </AppLayout>
    );
}
