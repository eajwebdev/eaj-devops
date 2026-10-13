import { type NavItem } from '@/types';
import { Activity, Bot, FolderKanban, LayoutDashboard, RadioTower, Settings, SquareKanban } from 'lucide-react';

/**
 * Main navigation (Plan §63). Only sections whose engines exist are listed —
 * AI War Room, AI Team, Skills and the rest appear as their phases land,
 * so the sidebar never links to a placeholder.
 */
export const mainNavItems: NavItem[] = [
    { title: 'Command Center', url: '/command-center', icon: LayoutDashboard },
    { title: 'Projects', url: '/projects', icon: FolderKanban },
    { title: 'AI War Room', url: '/war-room', icon: RadioTower },
    { title: 'Tasks', url: '/tasks', icon: SquareKanban },
    { title: 'AI Team', url: '/ai-team', icon: Bot },
    { title: 'Activity', url: '/activity', icon: Activity },
];

export const footerNavItems: NavItem[] = [{ title: 'Settings', url: '/settings/profile', icon: Settings }];

export function isActivePath(itemUrl: string, currentUrl: string): boolean {
    const path = currentUrl.split('?')[0];
    const base = itemUrl.startsWith('/settings') ? '/settings' : itemUrl;

    return path === base || path.startsWith(`${base}/`);
}
