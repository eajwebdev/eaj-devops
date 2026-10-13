import { Breadcrumbs } from '@/components/breadcrumbs';
import { DemoBadge } from '@/components/demo-badge';
import { EmergencyStop } from '@/components/operations/emergency-stop';
import { RealtimeStatusIndicator } from '@/components/realtime/realtime-status-indicator';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { type BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    return (
        <header className="border-sidebar-border/50 flex h-14 shrink-0 items-center gap-2 border-b px-4 transition-[width,height] ease-linear md:px-6">
            <div className="flex min-w-0 flex-1 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
            <div className="flex shrink-0 items-center gap-2">
                <DemoBadge />
                <RealtimeStatusIndicator />
                <EmergencyStop />
            </div>
        </header>
    );
}
