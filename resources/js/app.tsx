import { createInertiaApp } from '@inertiajs/react';
import type { ComponentType } from 'react';

import '../css/app.css';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';
const pages = import.meta.glob('./pages/**/*.tsx', { eager: true });

createInertiaApp({
    title: (title) => `${title ? `${title} - ${appName}` : appName}`,
    resolve: (name) => {
        const page = pages[`./pages/${name}.tsx`];

        if (!page) {
            throw new Error(`Unknown page: ${name}`);
        }

        return (page as { default: ComponentType }).default;
    },
    progress: {
        color: '#4B5563',
    },
});
