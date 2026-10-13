import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/react';
import { CheckCircle2, X } from 'lucide-react';
import { AnimatePresence, motion } from 'motion/react';
import { useEffect, useState } from 'react';

export function FlashStatus() {
    const { flash } = usePage<SharedData>().props;
    const [message, setMessage] = useState<string | null>(flash.status);

    useEffect(() => {
        setMessage(flash.status);

        if (!flash.status) {
            return;
        }

        const timer = window.setTimeout(() => setMessage(null), 6000);

        return () => window.clearTimeout(timer);
    }, [flash.status]);

    return (
        <div
            className="pointer-events-none fixed inset-x-4 bottom-4 z-50 flex justify-center sm:inset-x-auto sm:right-6 sm:bottom-6"
            aria-live="polite"
        >
            <AnimatePresence>
                {message && (
                    <motion.div
                        initial={{ opacity: 0, y: 8 }}
                        animate={{ opacity: 1, y: 0 }}
                        exit={{ opacity: 0 }}
                        transition={{ duration: 0.24, ease: [0.22, 1, 0.36, 1] }}
                        className="bg-popover text-popover-foreground border-border pointer-events-auto flex max-w-md items-start gap-3 rounded-lg border px-4 py-3 text-sm shadow-lg"
                    >
                        <CheckCircle2 className="text-success mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <p className="flex-1">{message}</p>
                        <button
                            type="button"
                            onClick={() => setMessage(null)}
                            className="text-muted-foreground hover:text-foreground"
                            aria-label="Dismiss"
                        >
                            <X className="size-4" />
                        </button>
                    </motion.div>
                )}
            </AnimatePresence>
        </div>
    );
}
