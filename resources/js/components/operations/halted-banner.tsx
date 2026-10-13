import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { OctagonX } from 'lucide-react';

export function HaltedBanner() {
    const { operations } = usePage<SharedData>().props;

    if (!operations?.halted) {
        return null;
    }

    return (
        <div role="alert" className="border-danger/30 bg-danger/10 text-foreground flex items-start gap-3 border-b px-4 py-2.5 text-sm md:px-6">
            <OctagonX className="text-danger mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <p>
                <span className="text-danger font-semibold">AI operations are stopped{operations.scope === 'global' ? ' platform-wide' : ''}.</span>{' '}
                {operations.reason && <span className="text-muted-foreground">Reason: {operations.reason}. </span>}
                <span className="text-muted-foreground">
                    No new steps will start until {operations.scope === 'global' ? 'a Super Admin' : 'an organization owner'} resumes.
                </span>
            </p>
        </div>
    );
}
