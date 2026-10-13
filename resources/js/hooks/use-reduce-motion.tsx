import { useCallback, useEffect, useState } from 'react';

const STORAGE_KEY = 'reduce-motion';
const systemQuery = () => window.matchMedia('(prefers-reduced-motion: reduce)');

const readSetting = (): boolean => {
    try {
        return localStorage.getItem(STORAGE_KEY) === 'true';
    } catch {
        return false;
    }
};

/**
 * Global motion housekeeping (Plan §7.6): honour the in-app "Reduce motion"
 * setting, and pause every loop animation while the tab is hidden.
 */
export function initializeMotionPreferences() {
    document.documentElement.classList.toggle('reduce-motion', readSetting());

    const syncVisibility = () => document.documentElement.classList.toggle('tab-hidden', document.hidden);
    document.addEventListener('visibilitychange', syncVisibility);
    syncVisibility();
}

/**
 * True when motion should be reduced — either the OS preference or the in-app setting.
 */
export function useReducedMotion(): boolean {
    const [reduced, setReduced] = useState(() => readSetting() || systemQuery().matches);

    useEffect(() => {
        const query = systemQuery();
        const update = () => setReduced(readSetting() || query.matches);

        query.addEventListener('change', update);
        window.addEventListener('eaj:reduce-motion', update);

        return () => {
            query.removeEventListener('change', update);
            window.removeEventListener('eaj:reduce-motion', update);
        };
    }, []);

    return reduced;
}

export function useReduceMotionSetting() {
    const [enabled, setEnabled] = useState(readSetting);

    const update = useCallback((value: boolean) => {
        setEnabled(value);

        try {
            localStorage.setItem(STORAGE_KEY, String(value));
        } catch {
            // Storage unavailable: the setting still applies for this page view.
        }

        document.documentElement.classList.toggle('reduce-motion', value);
        window.dispatchEvent(new Event('eaj:reduce-motion'));
    }, []);

    return { enabled, update };
}
