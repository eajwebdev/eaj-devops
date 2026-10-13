import { EajLockup } from '@/components/brand/eaj-lockup';
import { EajMark } from '@/components/brand/eaj-mark';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import { OrganizationSwitcher } from '@/components/organization-switcher';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { footerNavItems, mainNavItems } from '@/lib/navigation';
import { Link } from '@inertiajs/react';

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild className="hover:bg-transparent">
                            <Link href={route('dashboard')} prefetch aria-label="EAJ AI Dev Studio — Command Center">
                                {/* Expanded: horizontal lockup. Collapsed: mark only (Plan §7.1). */}
                                <EajLockup className="group-data-[collapsible=icon]:hidden" />
                                <EajMark className="hidden size-6 group-data-[collapsible=icon]:block" />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
                <OrganizationSwitcher />
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavMain items={footerNavItems} label="Account" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
