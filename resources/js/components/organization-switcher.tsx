import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Building2, Check, ChevronsUpDown } from 'lucide-react';

export function OrganizationSwitcher() {
    const { organization, organizations } = usePage<SharedData>().props;

    if (!organization) {
        return null;
    }

    const canSwitch = organizations.length > 1;

    const label = (
        <>
            <Building2 />
            <span className="min-w-0 flex-1 text-left">
                <span className="text-heading block truncate text-sm font-medium">{organization.name}</span>
                {organization.role && <span className="text-muted-foreground block truncate text-xs">{organization.role}</span>}
            </span>
        </>
    );

    if (!canSwitch) {
        return (
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" className="cursor-default hover:bg-transparent" tooltip={organization.name}>
                        {label}
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        );
    }

    return (
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton size="lg" tooltip={organization.name}>
                            {label}
                            <ChevronsUpDown className="ml-auto" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="w-(--radix-dropdown-menu-trigger-width) min-w-56" align="start">
                        <DropdownMenuLabel className="eyebrow">Organizations</DropdownMenuLabel>
                        {organizations.map((org) => (
                            <DropdownMenuItem
                                key={org.id}
                                onSelect={() => org.id !== organization.id && router.post(route('organizations.switch', org.id))}
                            >
                                <span className="flex-1 truncate">{org.name}</span>
                                {org.id === organization.id && <Check className="size-4" />}
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    );
}
