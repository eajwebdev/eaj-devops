import { EajMark } from '@/components/brand/eaj-mark';
import { cn } from '@/lib/utils';

interface EajLockupProps {
    orientation?: 'horizontal' | 'stacked';
    className?: string;
}

/**
 * Monogram + product wordmark (Plan §7.1): "EAJ" in crimson, "AI Dev Studio"
 * in white (dark) / navy (light), in the logo's wide geometric caps.
 */
export function EajLockup({ orientation = 'horizontal', className }: EajLockupProps) {
    const stacked = orientation === 'stacked';

    return (
        <span className={cn('inline-flex items-center', stacked ? 'flex-col gap-3' : 'gap-2.5', className)}>
            <EajMark className={stacked ? 'size-14' : 'size-7'} />
            <span
                className={cn(
                    'font-display leading-none font-semibold whitespace-nowrap uppercase',
                    stacked ? 'text-sm tracking-[0.3em]' : 'text-[0.6875rem] tracking-[0.2em]',
                )}
            >
                <span className="text-brand-text font-bold">EAJ</span> <span className="text-heading">AI Dev Studio</span>
            </span>
        </span>
    );
}
