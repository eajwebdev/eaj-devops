import { cn } from '@/lib/utils';
import { Check, Copy } from 'lucide-react';
import { useState } from 'react';

export function CopyCommand({ command, className }: { command: string; className?: string }) {
    const [copied, setCopied] = useState(false);

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(command);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard blocked: the command is still selectable.
        }
    };

    return (
        <div className={cn('bg-muted border-border group flex items-start gap-2 rounded-md border px-3 py-2', className)}>
            <code className="text-heading min-w-0 flex-1 font-mono text-xs break-all select-all">{command}</code>
            <button
                type="button"
                onClick={copy}
                className="text-muted-foreground hover:text-foreground shrink-0"
                aria-label={copied ? 'Copied' : 'Copy command'}
            >
                {copied ? <Check className="text-success size-4" /> : <Copy className="size-4" />}
            </button>
        </div>
    );
}
