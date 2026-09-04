import { createInertiaApp } from '@inertiajs/react';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import { createRoot } from 'react-dom/client';

import { FeedbackProvider } from '@/components/feedback/ActionFeedback';
import './echo';

createInertiaApp({
    title: (title) => `${title} | KPI System`,
    resolve: (name) => resolvePageComponent(
        `./Pages/${name}.jsx`,
        import.meta.glob('./Pages/**/*.jsx'),
    ),
    setup({ el, App, props }) {
        createRoot(el).render(
            <FeedbackProvider>
                <App {...props} />
            </FeedbackProvider>,
        );
    },
    progress: false,
});
