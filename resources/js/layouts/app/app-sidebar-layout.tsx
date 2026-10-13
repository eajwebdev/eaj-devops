import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { FlashStatus } from '@/components/flash-status';
import { HaltedBanner } from '@/components/operations/halted-banner';
import { RealtimeProvider } from '@/components/realtime/realtime-provider';
import { type BreadcrumbItem } from '@/types';

export default function AppSidebarLayout({ children, breadcrumbs = [] }: { children: React.ReactNode; breadcrumbs?: BreadcrumbItem[] }) {
    return (
        <RealtimeProvider>
            <AppShell variant="sidebar">
                <AppSidebar />
                <AppContent variant="sidebar">
                    <AppSidebarHeader breadcrumbs={breadcrumbs} />
                    <HaltedBanner />
                    {children}
                </AppContent>
            </AppShell>
            <FlashStatus />
        </RealtimeProvider>
    );
}
