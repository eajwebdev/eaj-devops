import { createEcho } from '@/lib/echo';
import { type SharedData, type StudioEvent } from '@/types';
import { usePage } from '@inertiajs/react';
import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react';

export type ConnectionStatus = 'connecting' | 'live' | 'reconnecting' | 'offline';
export type RealtimeTransport = 'websocket' | 'polling';

type Listener = (events: StudioEvent[]) => void;

interface RealtimeContextValue {
    status: ConnectionStatus;
    transport: RealtimeTransport;
    lastSyncedAt: Date | null;
}

const RealtimeContext = createContext<RealtimeContextValue | null>(null);
const listeners = new Set<Listener>();
const POLL_MS = 4000;
const RECONCILE_MS = 30000;
const FAILURES_BEFORE_OFFLINE = 3;

/**
 * Reverb is the fast path; the persisted HTTP stream is always the recovery
 * path. Socket reconnects first replay everything after the last durable
 * cursor, so pushes can never make the UI skip state (Plan Section 48.6).
 */
export function RealtimeProvider({ children }: { children: ReactNode }) {
    const { eventCursor, auth, organization } = usePage<SharedData>().props;
    const userId = auth.user?.id;
    const organizationId = organization?.id;
    const [status, setStatus] = useState<ConnectionStatus>('connecting');
    const [transport, setTransport] = useState<RealtimeTransport>('polling');
    const [lastSyncedAt, setLastSyncedAt] = useState<Date | null>(null);
    const cursor = useRef(eventCursor);

    useEffect(() => {
        cursor.current = eventCursor;
    }, [eventCursor, organizationId]);

    useEffect(() => {
        if (!userId || !organizationId) return;

        let stopped = false;
        let failures = 0;
        let timer: number | undefined;
        let controller: AbortController | null = null;
        let socketConnected = false;
        let syncing = false;
        let resyncRequested = false;
        const echo = createEcho();
        setTransport('polling');

        const deliver = (events: StudioEvent[]) => {
            const fresh = events.filter((event) => event.id > cursor.current).sort((a, b) => a.id - b.id);
            if (fresh.length === 0) return;
            cursor.current = Math.max(cursor.current, ...fresh.map((event) => event.id));
            listeners.forEach((listener) => listener(fresh));
        };

        const schedule = () => {
            window.clearTimeout(timer);
            if (!stopped && !document.hidden) {
                timer = window.setTimeout(sync, echo && socketConnected ? RECONCILE_MS : POLL_MS);
            }
        };

        const sync = async () => {
            if (syncing) {
                resyncRequested = true;
                return;
            }
            if (stopped) return;
            syncing = true;
            resyncRequested = false;
            controller?.abort();
            controller = new AbortController();

            try {
                let hasMore = true;
                while (hasMore && !stopped) {
                    const response = await fetch(`/api/events?after=${cursor.current}&limit=200`, {
                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    });
                    if (!response.ok) throw new Error(`Event stream returned ${response.status}`);

                    const body: { data: StudioEvent[]; cursor: number; has_more: boolean } = await response.json();
                    deliver(body.data);
                    cursor.current = Math.max(cursor.current, body.cursor);
                    hasMore = body.has_more;
                }

                failures = 0;
                setLastSyncedAt(new Date());
                // Events are flowing either way; the transport says how (WebSocket push or HTTP catch-up).
                setStatus('live');
                setTransport(echo && socketConnected ? 'websocket' : 'polling');
            } catch (error) {
                if (!stopped && !(error instanceof DOMException && error.name === 'AbortError')) {
                    failures += 1;
                    setStatus(failures >= FAILURES_BEFORE_OFFLINE || !navigator.onLine ? 'offline' : 'reconnecting');
                }
            } finally {
                syncing = false;
                if (resyncRequested) window.setTimeout(sync, 0);
                else schedule();
            }
        };

        if (echo) {
            const channel = echo.private(`organization.${organizationId}`);
            channel
                .listen('.outbox.event', (event: StudioEvent) => {
                    // The socket is a low-latency signal. Read the durable row
                    // before notifying UI consumers so no reconnect race can
                    // advance the cursor past an unseen event.
                    if (event.id > cursor.current) sync();
                })
                .subscribed(() => {
                    socketConnected = true;
                    setStatus('connecting');
                    sync();
                })
                .error(() => {
                    socketConnected = false;
                    setStatus(navigator.onLine ? 'reconnecting' : 'offline');
                    sync();
                });

            const connection = echo.connector.pusher.connection;
            connection.bind('connected', () => {
                socketConnected = true;
                sync();
            });
            connection.bind('connecting', () => setStatus('connecting'));
            connection.bind('unavailable', () => {
                socketConnected = false;
                setStatus('reconnecting');
            });
            connection.bind('failed', () => {
                socketConnected = false;
                setStatus('offline');
            });
            connection.bind('disconnected', () => {
                socketConnected = false;
                setStatus(navigator.onLine ? 'reconnecting' : 'offline');
            });
        }

        const onVisibility = () => {
            if (document.hidden) window.clearTimeout(timer);
            else sync();
        };
        const onOnline = () => sync();
        const onOffline = () => setStatus('offline');
        document.addEventListener('visibilitychange', onVisibility);
        window.addEventListener('online', onOnline);
        window.addEventListener('offline', onOffline);
        sync();

        return () => {
            stopped = true;
            window.clearTimeout(timer);
            controller?.abort();
            echo?.leave(`organization.${organizationId}`);
            echo?.disconnect();
            document.removeEventListener('visibilitychange', onVisibility);
            window.removeEventListener('online', onOnline);
            window.removeEventListener('offline', onOffline);
        };
    }, [userId, organizationId]);

    return <RealtimeContext.Provider value={{ status, transport, lastSyncedAt }}>{children}</RealtimeContext.Provider>;
}

export function useRealtime(): RealtimeContextValue {
    const context = useContext(RealtimeContext);
    if (!context) throw new Error('useRealtime must be used inside <RealtimeProvider>.');

    return context;
}

export function useStudioEvents(handler: Listener) {
    const latest = useRef(handler);
    latest.current = handler;

    useEffect(() => {
        const listener: Listener = (events) => latest.current(events);
        listeners.add(listener);

        return () => {
            listeners.delete(listener);
        };
    }, []);
}
