import { CopyCommand } from '@/components/copy-command';
import { type GitState, type StackProfile as Profile } from '@/types';
import { AlertTriangle } from 'lucide-react';

const short = (v: string | null | undefined) =>
    v
        ? v
              .replace(/^[\^~v]/, '')
              .split('.')
              .slice(0, 3)
              .join('.')
        : null;
const list = (v: string[] | undefined) => (v && v.length ? v.join(', ') : null);

/**
 * The detected stack in the shape of Plan §65 ("Project Found"). Every row is
 * read from a real file by the runner; absent rows mean "not detected".
 */
export function StackProfile({ stack, git, warnings }: { stack: Profile; git: GitState | null; warnings: string[] }) {
    const rows: [string, string | null][] = [
        [
            'Framework',
            stack.backend ? [stack.backend.framework, short(stack.backend.version ?? stack.backend.constraint)].filter(Boolean).join(' ') : null,
        ],
        ['PHP', stack.backend?.php ?? null],
        ['Structure', stack.backend?.structure ?? null],
        [
            'Frontend',
            stack.frontend
                ? [stack.frontend.framework, short(stack.frontend.version), stack.bridge ? `+ ${stack.bridge}` : null].filter(Boolean).join(' ')
                : stack.bridge,
        ],
        [
            'Router',
            stack.frontend?.router === 'app'
                ? 'App Router'
                : stack.frontend?.router === 'pages'
                  ? 'Pages Router'
                  : stack.frontend?.router === 'both'
                    ? 'App + Pages Router'
                    : (stack.frontend?.router ?? null),
        ],
        ['Language', list(stack.languages) ?? stack.language],
        ['Bundler', stack.bundler],
        ['CSS', list(stack.css)],
        ['Database', stack.database],
        ['Tests', list(stack.tests)],
        ['E2E', list(stack.e2e)],
        ['Static analysis', list(stack.staticAnalysis)],
        ['Formatters', list(stack.formatters)],
        ['Auth', list(stack.auth)],
        ['Payments', list(stack.payments)],
        ['Webhook routes', list(stack.webhookRoutes)],
        ['Browser env prefixes', list(stack.envPrefixes)],
        ['Package manager', [stack.packageManager, stack.node ? `Node ${stack.node}` : null].filter(Boolean).join(' · ') || null],
        ['Docker', list(stack.docker)],
        ['CI', list(stack.ci)],
        ['Git', git ? (git.enabled ? `${git.branch ?? 'detached'}${git.hasCommits ? '' : ' (no commits)'}` : 'Not a repository') : null],
    ];

    return (
        <div className="space-y-5">
            <dl className="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2">
                {rows
                    .filter(([, value]) => value)
                    .map(([label, value]) => (
                        <div key={label} className="min-w-0">
                            <dt className="text-muted-foreground text-xs">{label}</dt>
                            <dd className="text-heading truncate text-sm" title={value ?? undefined}>
                                {value}
                            </dd>
                        </div>
                    ))}
            </dl>

            {warnings.length > 0 && (
                <ul className="space-y-1.5">
                    {warnings.map((w) => (
                        <li key={w} className="bg-warning/10 text-foreground flex items-start gap-2 rounded-md px-3 py-2 text-xs">
                            <AlertTriangle className="text-warning mt-px size-3.5 shrink-0" aria-hidden="true" />
                            {w}
                        </li>
                    ))}
                </ul>
            )}

            {stack.commands && stack.commands.length > 0 && (
                <div>
                    <p className="eyebrow mb-2">Commands for this project's versions</p>
                    <div className="grid gap-1.5">
                        {stack.commands.map((c) => (
                            <div key={c.label + c.command} className="grid grid-cols-[8.5rem_1fr] items-center gap-2 text-xs">
                                <span className="text-muted-foreground truncate">{c.label}</span>
                                <CopyCommand command={c.command} className="py-1" />
                            </div>
                        ))}
                    </div>
                    <p className="text-muted-foreground mt-2 text-xs">
                        Suggestions only — running them through the approved command runner arrives in Phase 7.
                    </p>
                </div>
            )}

            {stack.envShape && Object.keys(stack.envShape).length > 0 && (
                <details className="text-xs">
                    <summary className="text-muted-foreground cursor-pointer">
                        Environment shape ({Object.keys(stack.envShape).length} keys — values hidden)
                    </summary>
                    <dl className="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 font-mono sm:grid-cols-2">
                        {Object.entries(stack.envShape).map(([k, v]) => (
                            <div key={k} className="flex min-w-0 gap-2">
                                <dt className="text-heading truncate">{k}</dt>
                                <dd className={v.startsWith('<') ? 'text-muted-foreground' : 'text-heading'}>{v}</dd>
                            </div>
                        ))}
                    </dl>
                </details>
            )}
        </div>
    );
}
