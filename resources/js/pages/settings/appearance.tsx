import { Head } from '@inertiajs/react';

import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import { useReduceMotionSetting } from '@/hooks/use-reduce-motion';
import { type BreadcrumbItem } from '@/types';

import AppLayout from '@/layouts/app-layout';
import SettingsLayout from '@/layouts/settings/layout';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Appearance settings',
        href: '/settings/appearance',
    },
];

export default function Appearance() {
    const reduceMotion = useReduceMotionSetting();

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Appearance settings" />

            <SettingsLayout>
                <div className="space-y-6">
                    <HeadingSmall title="Appearance settings" description="Theme for the Studio on this device. Dark is the EAJ default." />
                    <AppearanceTabs />
                </div>

                <div className="space-y-4">
                    <HeadingSmall
                        title="Motion"
                        description="Agents animate only while real work is happening. Reduce motion replaces loops with static status rings and text."
                    />
                    <label className="flex items-center gap-3 text-sm">
                        <input
                            type="checkbox"
                            className="accent-brand-crimson size-4"
                            checked={reduceMotion.enabled}
                            onChange={(e) => reduceMotion.update(e.target.checked)}
                        />
                        Reduce motion
                    </label>
                </div>
            </SettingsLayout>
        </AppLayout>
    );
}
