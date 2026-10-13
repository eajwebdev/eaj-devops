import '@fontsource-variable/inter';
import '@fontsource/jetbrains-mono/400.css';
import '@fontsource/montserrat/600.css';
import '@fontsource/montserrat/700.css';
import '../css/app.css';

import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';
import { route as routeFn } from 'ziggy-js';
import { initializeTheme } from './hooks/use-appearance';
import { initializeMotionPreferences } from './hooks/use-reduce-motion';

declare global {
    const route: typeof routeFn;
}

const appName = import.meta.env.VITE_APP_NAME || 'EAJ AI Dev Studio';

const brandCrimson = getComputedStyle(document.documentElement).getPropertyValue('--color-brand-crimson').trim();

createInertiaApp({
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.tsx`, import.meta.glob('./pages/**/*.tsx')),
    setup({ el, App, props }) {
        const root = createRoot(el);

        root.render(<App {...props} />);

        document.getElementById('eaj-splash')?.remove();
    },
    progress: {
        color: brandCrimson,
    },
});

// This will set light / dark mode on load...
initializeTheme();
initializeMotionPreferences();
