import { relativeTime } from '@/lib/events';
import { type ProjectConnection } from '@/types';
import { Search } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

interface SymbolHit {
    kind: string;
    name: string;
    path: string | null;
    line: number;
    detail: string | null;
}

const KIND_LABELS: Record<string, string> = {
    class: 'Classes',
    model: 'Models',
    function: 'Functions',
    route: 'Routes',
    table: 'Tables',
    component: 'Components',
    hook: 'Hooks',
    type: 'Types',
    test: 'Tests',
    view: 'Views',
    dependency: 'Dependencies',
    interface: 'Interfaces',
    trait: 'Traits',
    enum: 'Enums',
};

/**
 * Code index (Plan §41): what the context builder will search instead of
 * sending the whole repository to a model (Plan §2.4).
 */
export function CodeIndexPanel({ projectSlug, connection }: { projectSlug: string; connection: ProjectConnection }) {
    const stats = connection.index_stats;
    const [query, setQuery] = useState('');
    const [hits, setHits] = useState<SymbolHit[] | null>(null);
    const [failed, setFailed] = useState(false);
    const controller = useRef<AbortController | null>(null);

    useEffect(() => {
        const q = query.trim();

        if (q.length < 2) {
            setHits(null);

            return;
        }

        const timer = window.setTimeout(() => {
            controller.current?.abort();
            controller.current = new AbortController();
            setFailed(false);

            fetch(route('api.projects.index.search', { project: projectSlug, q }), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
                signal: controller.current.signal,
            })
                .then((r) => (r.ok ? r.json() : Promise.reject(new Error(String(r.status)))))
                .then((body: { data: SymbolHit[] }) => setHits(body.data))
                .catch((e: Error) => e.name !== 'AbortError' && setFailed(true));
        }, 250);

        return () => window.clearTimeout(timer);
    }, [query, projectSlug]);

    if (!stats) {
        return <p className="text-muted-foreground text-sm">Not built yet. The runner builds it right after a successful scan.</p>;
    }

    return (
        <div className="space-y-4">
            <div className="flex items-baseline justify-between gap-2">
                <p className="text-heading text-sm">
                    <span className="tabular font-semibold">{stats.files.toLocaleString()}</span> files ·{' '}
                    <span className="tabular font-semibold">{stats.symbols.toLocaleString()}</span> symbols
                </p>
                {connection.indexed_at && <p className="text-muted-foreground text-xs">{relativeTime(connection.indexed_at)}</p>}
            </div>

            <div className="flex flex-wrap gap-1.5">
                {Object.entries(stats.kinds)
                    .sort((a, b) => b[1] - a[1])
                    .map(([kind, n]) => (
                        <span key={kind} className="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-xs">
                            {KIND_LABELS[kind] ?? kind} <span className="text-heading tabular">{n.toLocaleString()}</span>
                        </span>
                    ))}
            </div>

            <div className="relative">
                <Search className="text-muted-foreground pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2" />
                <input
                    type="search"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    placeholder="Find a class, route, component…"
                    aria-label="Search the code index"
                    className="border-input bg-background focus-visible:ring-ring h-9 w-full rounded-md border pr-3 pl-9 text-sm focus-visible:ring-2 focus-visible:outline-hidden"
                />
            </div>

            {failed && <p className="text-danger text-xs">Search failed. Try again.</p>}
            {hits && hits.length === 0 && <p className="text-muted-foreground text-xs">No symbols match “{query.trim()}”.</p>}
            {hits && hits.length > 0 && (
                <ul className="divide-border max-h-72 divide-y overflow-y-auto">
                    {hits.map((h, i) => (
                        <li key={`${h.path}:${h.line}:${i}`} className="py-1.5">
                            <p className="flex items-center gap-2 text-sm">
                                <span className="bg-secondary text-muted-foreground rounded px-1.5 py-px font-mono text-[0.625rem] uppercase">
                                    {h.kind}
                                </span>
                                <span className="text-heading truncate font-mono text-xs">{h.name}</span>
                            </p>
                            <p className="text-muted-foreground truncate font-mono text-[0.6875rem]">
                                {h.path}:{h.line}
                                {h.detail && ` · ${h.detail}`}
                            </p>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
