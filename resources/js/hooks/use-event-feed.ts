import { useStudioEvents } from '@/components/realtime/realtime-provider';
import { type StudioEvent } from '@/types';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef, useState } from 'react';

/**
 * Returns a trigger that re-reads the given page props from the server,
 * debounced so a burst of events causes one request. Numbers on screen are
 * always server-computed, never guessed client-side.
 */
export function useDebouncedReload(only: string[], delayMs = 800): () => void {
    const timer = useRef<number | undefined>(undefined);
    const props = useRef(only);
    props.current = only;

    useEffect(() => () => window.clearTimeout(timer.current), []);

    return useCallback(() => {
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(() => router.reload({ only: props.current }), delayMs);
    }, [delayMs]);
}

/** Union by event id, oldest first, capped to the newest `max`. */
export function mergeEvents(current: StudioEvent[], incoming: StudioEvent[], max: number): StudioEvent[] {
    const byId = new Map(current.map((e) => [e.id, e]));
    incoming.forEach((e) => byId.set(e.id, e));

    return [...byId.values()].sort((a, b) => a.id - b.id).slice(-max);
}

interface EventFeedOptions {
    max: number;
    filter?: (event: StudioEvent) => boolean;
    /** Called after new events arrive, e.g. to re-read server-computed numbers. */
    onIncoming?: (events: StudioEvent[]) => void;
}

/**
 * A list of persisted events seeded from page props and kept current from the
 * live stream. Fresh props (e.g. after a redirect back) are merged, not
 * replaced, and duplicates from overlapping sources collapse by id.
 */
export function useEventFeed(initial: StudioEvent[], { max, filter, onIncoming }: EventFeedOptions): StudioEvent[] {
    const [events, setEvents] = useState(() => mergeEvents([], initial, max));
    const options = useRef({ max, filter, onIncoming });
    options.current = { max, filter, onIncoming };

    useEffect(() => {
        setEvents((current) => mergeEvents(current, initial, options.current.max));
    }, [initial]);

    useStudioEvents((incoming) => {
        const { max, filter, onIncoming } = options.current;
        const relevant = filter ? incoming.filter(filter) : incoming;

        if (relevant.length === 0) {
            return;
        }

        setEvents((current) => mergeEvents(current, relevant, max));
        onIncoming?.(relevant);
    });

    return events;
}
