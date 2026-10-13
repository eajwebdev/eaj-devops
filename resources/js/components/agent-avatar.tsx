import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { type AgentRole } from '@/types';
import {
    ClipboardList,
    Container,
    Database,
    FlaskConical,
    GitPullRequest,
    LayoutTemplate,
    Network,
    PenTool,
    Rocket,
    Server,
    ShieldCheck,
    SquareKanban,
    type LucideIcon,
} from 'lucide-react';

/** Agent identity (Plan §7.5): role monogram + Lucide icon, used everywhere an agent appears. */
export const AGENTS: Record<AgentRole, { name: string; icon: LucideIcon }> = {
    PM: { name: 'Project Manager', icon: SquareKanban },
    BA: { name: 'Business Analyst', icon: ClipboardList },
    SA: { name: 'Solution Architect', icon: Network },
    UX: { name: 'UI/UX Designer', icon: PenTool },
    FE: { name: 'Frontend Developer', icon: LayoutTemplate },
    BE: { name: 'Backend Developer', icon: Server },
    DB: { name: 'Database Engineer', icon: Database },
    QA: { name: 'QA Engineer', icon: FlaskConical },
    SE: { name: 'Security Engineer', icon: ShieldCheck },
    SR: { name: 'Senior Reviewer', icon: GitPullRequest },
    DO: { name: 'DevOps Engineer', icon: Container },
    RM: { name: 'Release Manager', icon: Rocket },
};

/**
 * A 36px rounded square in navy-700 with a white monogram (Plan §7.5). Static
 * here: the animated status ring arrives with real agent states in Phase 4.
 */
export function AgentAvatar({ role, size = 'md', hint }: { role: AgentRole; size?: 'sm' | 'md'; hint?: string }) {
    const agent = AGENTS[role];

    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span
                        className={cn(
                            'bg-navy-700 font-display inline-flex shrink-0 items-center justify-center rounded-md font-bold tracking-wider text-white',
                            size === 'md' ? 'size-9 text-xs' : 'size-7 text-[0.625rem]',
                        )}
                        aria-label={agent.name}
                    >
                        {role}
                    </span>
                </TooltipTrigger>
                <TooltipContent>
                    <p className="flex items-center gap-1.5 font-medium">
                        <agent.icon className="size-3.5" /> {agent.name}
                    </p>
                    {hint && <p className="text-muted-foreground mt-0.5 max-w-56">{hint}</p>}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
