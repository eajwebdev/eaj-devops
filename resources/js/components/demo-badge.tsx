import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';

/**
 * Permanent crimson DEMO pill on every screen in demo mode, so simulated data
 * can never be mistaken for real work (Plan §7.7, §58).
 */
export function DemoBadge() {
    const { demoMode, organization } = usePage<SharedData>().props;

    if (!demoMode && !organization?.is_demo) {
        return null;
    }

    return (
        <span
            className="bg-brand-crimson font-display rounded-full px-2.5 py-0.5 text-[0.625rem] font-bold tracking-[0.2em] text-white uppercase"
            title="Demo mode — activity on screen may be simulated"
        >
            Demo
        </span>
    );
}
