import { useEffect, useState } from 'react';
import { router, usePage, usePoll } from '@inertiajs/react';

import Sidebar from '@/components/layout/Sidebar';
import Topbar from '@/components/layout/Topbar';

export default function AppLayout({ children }) {
    const page = usePage();
    const { auth, navigation = [], activePeriod, notifications = [] } = page.props;
    const { url } = page;
    const [mobileOpen, setMobileOpen] = useState(false);
    const [isNavigating, setIsNavigating] = useState(false);

    usePoll(30000, {
        only: ['activePeriod', 'notifications'],
    });

    useEffect(() => {
        const removeStartListener = router.on('start', () => setIsNavigating(true));
        const removeFinishListener = router.on('finish', () => setIsNavigating(false));

        return () => {
            removeStartListener();
            removeFinishListener();
        };
    }, []);

    return (
        <div className="min-h-[100dvh] bg-background text-foreground">
            <Sidebar
                navigation={navigation}
                user={auth?.user}
                mobileOpen={mobileOpen}
                onClose={() => setMobileOpen(false)}
            />

            {mobileOpen && (
                <button
                    type="button"
                    className="fixed inset-0 z-30 bg-slate-950/50 lg:hidden"
                    onClick={() => setMobileOpen(false)}
                    aria-label="Tutup navigasi"
                />
            )}

            <div className="min-h-[100dvh] lg:pl-64">
                <Topbar
                    user={auth?.user}
                    activePeriod={activePeriod}
                    notifications={notifications}
                    onOpenMenu={() => setMobileOpen(true)}
                />

                {isNavigating && (
                    <div className="pointer-events-none fixed inset-x-0 top-0 z-[100] h-1 bg-primary/15" role="status" aria-live="polite">
                        <span className="sr-only">Memuat halaman...</span>
                        <div className="app-loading-sweep h-full w-1/3 rounded-r-full bg-primary shadow-[0_0_18px_color-mix(in_oklch,var(--primary),transparent_20%)]" />
                    </div>
                )}

                <main id="main-content" key={url} className="app-page-enter">{children}</main>
            </div>
        </div>
    );
}
