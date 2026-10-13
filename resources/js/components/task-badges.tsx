import { StatusPill } from '@/components/status-pill';
import { type Tone } from '@/lib/events';
import { cn } from '@/lib/utils';
import { type TaskPriority, type TaskStatus } from '@/types';
import {
    Ban,
    Bot,
    CheckCircle2,
    ChevronsUp,
    CircleDashed,
    Equal,
    FlaskConical,
    GitPullRequest,
    Hand,
    Inbox,
    MessageSquareWarning,
    Rocket,
    ShieldCheck,
    SignalLow,
    TriangleAlert,
    type LucideIcon,
} from 'lucide-react';

export const STATUS: Record<TaskStatus, { label: string; tone: Tone; icon: LucideIcon }> = {
    BACKLOG: { label: 'Backlog', tone: 'neutral', icon: Inbox },
    READY: { label: 'Ready', tone: 'info', icon: CircleDashed },
    PLANNING: { label: 'Planning', tone: 'brand', icon: Bot },
    IN_PROGRESS: { label: 'In Development', tone: 'brand', icon: Bot },
    TESTING: { label: 'Testing', tone: 'brand', icon: FlaskConical },
    REVIEW: { label: 'Senior Review', tone: 'brand', icon: GitPullRequest },
    SECURITY_REVIEW: { label: 'Security Review', tone: 'brand', icon: ShieldCheck },
    CHANGES_REQUESTED: { label: 'Changes Requested', tone: 'warning', icon: MessageSquareWarning },
    BLOCKED: { label: 'Blocked', tone: 'warning', icon: Hand },
    RELEASE_READY: { label: 'Release Ready', tone: 'success', icon: Rocket },
    DONE: { label: 'Completed', tone: 'success', icon: CheckCircle2 },
    CANCELLED: { label: 'Cancelled', tone: 'neutral', icon: Ban },
};

export function TaskStatusBadge({ status, className }: { status: TaskStatus; className?: string }) {
    const s = STATUS[status];

    return <StatusPill tone={s.tone} icon={s.icon} label={s.label} className={className} />;
}

const PRIORITY: Record<TaskPriority, { label: string; icon: LucideIcon; className: string }> = {
    CRITICAL: { label: 'Critical', icon: TriangleAlert, className: 'text-danger' },
    HIGH: { label: 'High', icon: ChevronsUp, className: 'text-warning' },
    MEDIUM: { label: 'Medium', icon: Equal, className: 'text-info' },
    LOW: { label: 'Low', icon: SignalLow, className: 'text-muted-foreground' },
};

/** Icon + label, never colour alone (Plan §7.2). */
export function PriorityBadge({ priority, compact = false }: { priority: TaskPriority; compact?: boolean }) {
    const p = PRIORITY[priority];

    return (
        <span className={cn('inline-flex items-center gap-1 text-xs font-medium', p.className)} title={`${p.label} priority`}>
            <p.icon className="size-3.5" aria-hidden="true" />
            {compact ? <span className="sr-only">{p.label} priority</span> : p.label}
        </span>
    );
}

export function TypeLabel({ type }: { type: string }) {
    return <span className="text-muted-foreground text-xs capitalize">{type}</span>;
}
