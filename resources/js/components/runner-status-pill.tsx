import { StatusPill } from '@/components/status-pill';
import { type Tone } from '@/lib/events';
import { type RunnerStatus } from '@/types';
import { Ban, CircleDot, CirclePause, PlugZap, type LucideIcon } from 'lucide-react';

const map: Record<RunnerStatus, { tone: Tone; icon: LucideIcon; label: string }> = {
    online: { tone: 'success', icon: PlugZap, label: 'Online' },
    stale: { tone: 'warning', icon: CirclePause, label: 'Not responding' },
    offline: { tone: 'neutral', icon: CircleDot, label: 'Offline' },
    revoked: { tone: 'danger', icon: Ban, label: 'Revoked' },
};

export function RunnerStatusPill({ status, className }: { status: RunnerStatus; className?: string }) {
    const s = map[status];

    return <StatusPill tone={s.tone} icon={s.icon} label={s.label} className={className} />;
}
