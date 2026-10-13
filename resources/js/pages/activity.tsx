import { ActivityTimeline } from '@/components/activity-timeline';
import { EmptyState } from '@/components/empty-state';
import { PageHeader } from '@/components/page-header';
import { Panel } from '@/components/panel';
import { useEventFeed } from '@/hooks/use-event-feed';
import AppLayout from '@/layouts/app-layout';
import { isFeedEvent } from '@/lib/events';
import { type BreadcrumbItem, type StudioEvent } from '@/types';
import { Head } from '@inertiajs/react';
import { Activity as ActivityIcon } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Activity', href: '/activity' }];

const MAX_EVENTS = 200;

export default function Activity({ events: initialEvents }: { events: StudioEvent[] }) {
    const events = useEventFeed(initialEvents, { max: MAX_EVENTS, filter: isFeedEvent });

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Activity" />

            <div className="flex flex-1 flex-col gap-6 p-4 md:p-6">
                <PageHeader
                    eyebrow="Audit trail"
                    title="Activity"
                    description="Every persisted event in this organization, newest first. Nothing here is simulated."
                />

                <Panel title={`Latest ${Math.min(events.length, MAX_EVENTS)} events`}>
                    {events.length > 0 ? (
                        <ActivityTimeline events={events} showProject />
                    ) : (
                        <EmptyState icon={ActivityIcon} title="No events yet" description="Events appear here the moment work happens." />
                    )}
                </Panel>
            </div>
        </AppLayout>
    );
}
