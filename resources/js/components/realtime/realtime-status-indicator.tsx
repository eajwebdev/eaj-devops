import { useRealtime, type ConnectionStatus } from '@/components/realtime/realtime-provider';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

const copy: Record<ConnectionStatus, { label: string; dot: string; detail: string }> = {
    connecting: { label: 'Connecting', dot: 'bg-info', detail: 'Loading the latest events…' },
    live: { label: 'Live', dot: 'bg-success', detail: 'Receiving persisted events.' },
    reconnecting: { label: 'Reconnecting', dot: 'bg-info', detail: 'The event stream did not answer. Retrying with back-off.' },
    offline: {
        label: 'Offline — showing last known state',
        dot: 'bg-danger',
        detail: 'Nothing on screen is lost; it will catch up when the connection returns.',
    },
};

/**
 * Top-bar connection state (Plan §7.6): solid when connected, pulsing while
 * reconnecting, danger when offline.
 */
export function RealtimeStatusIndicator() {
    const { status, transport, lastSyncedAt } = useRealtime();
    const state = copy[status];
    const pulsing = status === 'reconnecting' || status === 'connecting';

    return (
        <TooltipProvider delayDuration={200}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <span
                        role="status"
                        aria-live="polite"
                        className="text-muted-foreground inline-flex items-center gap-2 rounded-full px-2 py-1 text-xs"
                    >
                        <span className="relative flex size-2">
                            {pulsing && (
                                <span data-motion="loop" className={cn('motion-ping absolute inline-flex size-full rounded-full', state.dot)} />
                            )}
                            <span className={cn('relative inline-flex size-2 rounded-full', state.dot)} />
                        </span>
                        <span className={cn(status === 'offline' ? 'inline' : 'hidden sm:inline')}>
                            {state.label}
                            {status === 'live' && transport === 'polling' && <span className="text-muted-foreground"> · HTTP</span>}
                        </span>
                    </span>
                </TooltipTrigger>
                <TooltipContent side="bottom" className="max-w-64">
                    <p>
                        {status === 'live'
                            ? transport === 'websocket'
                                ? 'Receiving persisted events over WebSockets, reconciled with the HTTP catch-up stream.'
                                : 'Receiving persisted events by HTTP catch-up every 4s (WebSocket not connected — nothing is missed).'
                            : state.detail}
                    </p>
                    {lastSyncedAt && <p className="text-muted-foreground mt-1">Last sync {lastSyncedAt.toLocaleTimeString()}</p>}
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>
    );
}
