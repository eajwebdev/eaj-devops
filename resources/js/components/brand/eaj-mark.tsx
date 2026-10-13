import { cn } from '@/lib/utils';
import { type SVGAttributes } from 'react';

/**
 * EA monogram geometry (Plan §7.1). Stand-in drawn in the logo's style —
 * when the official vector redraw of eaj-primary.png lands, replace these two
 * paths and their copies in resources/views/app.blade.php (splash),
 * public/favicon.svg and resources/brand/*.svg.
 */
export const EAJ_MARK_VIEWBOX = '0 0 64 64';
export const EAJ_MARK_E = 'M7 14H15V50H7Z M15 14H31L27 21H15Z M15 28.5H27L24 35.5H15Z M15 43H27L31 50H15Z';
export const EAJ_MARK_A = 'M42 14H49L40 50H33Z M42 14H49L58 50H51Z M41 37H50L51.5 42H39.5Z';

type MarkVariant = 'color' | 'mono';

interface EajMarkProps extends SVGAttributes<SVGSVGElement> {
    /** color: E follows the theme (white on dark, navy on light) + crimson A. mono: currentColor (use below 24px). */
    variant?: MarkVariant;
    title?: string;
}

export function EajMark({ variant = 'color', title = 'EAJ', className, ...props }: EajMarkProps) {
    const mono = variant === 'mono';

    return (
        <svg
            viewBox={EAJ_MARK_VIEWBOX}
            xmlns="http://www.w3.org/2000/svg"
            role="img"
            aria-label={title}
            className={cn('shrink-0', className)}
            {...props}
        >
            <path d={EAJ_MARK_E} className={mono ? 'fill-current' : 'fill-[var(--mark-letter)]'} />
            <path d={EAJ_MARK_A} className={mono ? 'fill-current' : 'fill-brand-crimson'} />
        </svg>
    );
}
