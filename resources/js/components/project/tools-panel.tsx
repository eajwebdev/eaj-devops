import { Checkbox } from '@/components/ui/checkbox';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import { ShieldAlert } from 'lucide-react';
import { useState } from 'react';

export interface ProjectToolView {
    id: number;
    key: string;
    kind: 'install' | 'format' | 'static' | 'test' | 'build' | 'e2e';
    kind_label: string;
    ecosystem: 'php' | 'js' | 'python';
    label: string;
    command: string;
    supports_fix: boolean;
    scoped: boolean;
    blocked: string | null;
    enabled: boolean;
    available: boolean;
}

/**
 * The project's approved command set (Plan §84): every tool the runner found in
 * the project's own files, and whether agents may run it. The runner builds the
 * real command on the PC; the Studio only ever names a tool.
 */
export function ToolsPanel({ projectSlug, tools, canUpdate }: { projectSlug: string; tools: ProjectToolView[]; canUpdate: boolean }) {
    const [busy, setBusy] = useState<number | null>(null);

    if (tools.length === 0) {
        return <p className="text-muted-foreground text-sm">No tools detected yet. They appear after the next scan with an up-to-date runner.</p>;
    }

    const toggle = (tool: ProjectToolView, enabled: boolean) => {
        setBusy(tool.id);
        router.patch(route('projects.tools.update', [projectSlug, tool.id]), { enabled }, { preserveScroll: true, onFinish: () => setBusy(null) });
    };

    return (
        <ul className="divide-border -my-2 divide-y">
            {tools.map((tool) => {
                const id = `tool-${tool.id}`;

                return (
                    <li key={tool.id} className={cn('flex items-start gap-3 py-2.5', !tool.available && 'opacity-60')}>
                        <Checkbox
                            id={id}
                            className="mt-0.5"
                            checked={tool.enabled && !tool.blocked}
                            disabled={!canUpdate || busy === tool.id || !!tool.blocked || !tool.available}
                            onCheckedChange={(checked) => toggle(tool, checked === true)}
                            aria-describedby={`${id}-command`}
                        />
                        <div className="min-w-0 flex-1">
                            <label htmlFor={id} className="text-heading text-sm font-medium">
                                {tool.label}
                            </label>
                            <span className="text-muted-foreground ml-2 text-xs">
                                {tool.kind_label}
                                {tool.supports_fix && ' · fixes files'}
                                {!tool.available && ' · not found in the last scan'}
                            </span>
                            <p id={`${id}-command`} className="text-muted-foreground font-mono text-xs break-all">
                                {tool.command}
                            </p>
                            {tool.blocked && (
                                <p className="text-warning mt-1 flex items-start gap-1 text-xs">
                                    <ShieldAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                                    Blocked by the runner: {tool.blocked}
                                </p>
                            )}
                        </div>
                    </li>
                );
            })}
        </ul>
    );
}
