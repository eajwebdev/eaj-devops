import { useReducedMotion } from '@/hooks/use-reduce-motion';
import { useEffect, useRef, useState } from 'react';

/**
 * Numbers tween to their new value over 400ms instead of jumping (Plan §7.6).
 */
export function AnimatedNumber({ value, format = (n) => n.toLocaleString() }: { value: number; format?: (n: number) => string }) {
    const reduced = useReducedMotion();
    const [display, setDisplay] = useState(value);
    const from = useRef(value);

    useEffect(() => {
        if (reduced || from.current === value) {
            from.current = value;
            setDisplay(value);

            return;
        }

        const start = performance.now();
        const initial = from.current;
        let frame = 0;

        const tick = (now: number) => {
            const t = Math.min((now - start) / 400, 1);
            const eased = 1 - Math.pow(1 - t, 3);
            setDisplay(Math.round(initial + (value - initial) * eased));

            if (t < 1) {
                frame = requestAnimationFrame(tick);
            } else {
                from.current = value;
            }
        };

        frame = requestAnimationFrame(tick);

        return () => {
            cancelAnimationFrame(frame);
            from.current = value;
        };
    }, [value, reduced]);

    return <span className="tabular">{format(display)}</span>;
}
