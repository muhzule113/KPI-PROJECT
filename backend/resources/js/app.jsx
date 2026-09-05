import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

import { FeedbackProvider } from '@/components/feedback/ActionFeedback';
import AppLayout from '@/layouts/AppLayout';
import './echo';

createInertiaApp({
    title: (title) => `${title} | KPI System`,
    resolve: (name) => resolvePageComponent(
        `./Pages/${name}.jsx`,
        import.meta.glob('./Pages/**/*.jsx'),
    ),
    layout: (_name, page) => page?.props?.auth?.user ? AppLayout : undefined,
    setup({ el, App, props }) {
        createRoot(el).render(
            <FeedbackProvider>
                <App {...props} />
            </FeedbackProvider>,
        );
    },
    progress: false,
});
