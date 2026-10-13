import { type Tone } from '@/lib/events';
import {
    AlertTriangle,
    BookOpen,
    CheckCircle2,
    Clock3,
    FlaskConical,
    GitPullRequest,
    Hammer,
    Loader,
    PauseCircle,
    Route,
    Users,
    Wrench,
    XCircle,
    type LucideIcon,
} from 'lucide-react';

/** Agent states (Plan §32). Mirrors App\Enums\AgentStatus. */
export type AgentStatus =
    | 'IDLE'
    | 'QUEUED'
    | 'INITIALIZING'
    | 'PLANNING'
    | 'READING'
    | 'WORKING'
    | 'TESTING'
    | 'WAITING_FOR_TOOL'
    | 'WAITING_FOR_AGENT'
    | 'REVIEWING'
    | 'FIXING'
    | 'BLOCKED'
    | 'PAUSED'
    | 'FAILED'
    | 'COMPLETED'
    | 'CANCELLED';

/** Status is icon + label, never colour alone (§7.2). `active` = animates in the War Room (§7.6). */
export const AGENT_STATUS: Record<AgentStatus, { tone: Tone; icon: LucideIcon; label: string; active: boolean }> = {
    IDLE: { tone: 'neutral', icon: PauseCircle, label: 'Idle', active: false },
    QUEUED: { tone: 'info', icon: Clock3, label: 'Queued', active: false },
    INITIALIZING: { tone: 'brand', icon: Loader, label: 'Initializing', active: true },
    PLANNING: { tone: 'brand', icon: Route, label: 'Planning', active: true },
    READING: { tone: 'brand', icon: BookOpen, label: 'Reading', active: true },
    WORKING: { tone: 'brand', icon: Hammer, label: 'Working', active: true },
    TESTING: { tone: 'brand', icon: FlaskConical, label: 'Testing', active: true },
    WAITING_FOR_TOOL: { tone: 'info', icon: Wrench, label: 'Waiting for tool', active: false },
    WAITING_FOR_AGENT: { tone: 'info', icon: Users, label: 'Waiting for agent', active: false },
    REVIEWING: { tone: 'brand', icon: GitPullRequest, label: 'Reviewing', active: true },
    FIXING: { tone: 'warning', icon: Wrench, label: 'Fixing', active: true },
    BLOCKED: { tone: 'warning', icon: AlertTriangle, label: 'Blocked', active: false },
    PAUSED: { tone: 'warning', icon: PauseCircle, label: 'Paused', active: false },
    FAILED: { tone: 'danger', icon: XCircle, label: 'Failed', active: false },
    COMPLETED: { tone: 'success', icon: CheckCircle2, label: 'Completed', active: false },
    CANCELLED: { tone: 'neutral', icon: XCircle, label: 'Cancelled', active: false },
};
