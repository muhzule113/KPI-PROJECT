import { Link } from '@inertiajs/react';
import {
    BarChart3,
    BriefcaseBusiness,
    CalendarDays,
    ClipboardCheck,
    ClipboardList,
    Database,
    FileUp,
    HeartHandshake,
    Gauge,
    LayoutDashboard,
    MessageCircleWarning,
    Package,
    ScrollText,
    ShieldCheck,
    Store,
    Target,
    Users,
    Wrench,
    X,
} from 'lucide-react';

import InitialsAvatar from '@/components/common/InitialsAvatar';
import { cn } from '@/lib/utils';

const iconMap = {
    dashboard: LayoutDashboard,
    kpi: BarChart3,
    target: Target,
    assessment: ClipboardCheck,
    database: Database,
    users: Users,
    briefcase: BriefcaseBusiness,
    store: Store,
    calendar: CalendarDays,
    clipboard: ClipboardList,
    package: Package,
    complaint: MessageCircleWarning,
    coaching: HeartHandshake,
    wrench: Wrench,
    feedback: MessageCircleWarning,
    upload: FileUp,
    list: ClipboardList,
    template: Database,
    approval: ShieldCheck,
    audit: ScrollText,
};

function isActivePath(href, currentPath) {
    if (href === '/app') {
        return currentPath === '/app';
    }

    return currentPath.startsWith(href);
}

export default function Sidebar({
    navigation = [],
    user,
    mobileOpen,
    onClose,
}) {
    const currentPath = typeof window === 'undefined' ? '/app' : window.location.pathname;

    return (
        <aside
            aria-label="Navigasi aplikasi"
            className={cn(
                'fixed inset-y-0 left-0 z-40 flex w-64 flex-col bg-sidebar text-sidebar-foreground shadow-xl shadow-slate-950/10 transition-transform duration-200 ease-out motion-reduce:transition-none',
                mobileOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0',
            )}
        >
            <div className="flex h-[76px] items-center justify-between border-b border-sidebar-border/70 px-5">
                <Link href="/app" className="flex min-w-0 items-center gap-3 rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring">
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-sidebar-primary text-sidebar-primary-foreground shadow-sm">
                        <Gauge className="size-5" strokeWidth={2} />
                    </span>
                    <span className="truncate text-sm font-semibold tracking-wide">
                        KPI System
                    </span>
                </Link>

                <button
                    type="button"
                    onClick={onClose}
                    className="rounded-lg p-2 text-sidebar-foreground/70 hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring lg:hidden"
                    aria-label="Tutup navigasi"
                >
                    <X className="size-5" />
                </button>
            </div>

            <div className="scrollbar-hidden flex-1 overflow-y-auto overscroll-contain px-3 py-5">
                <nav className="space-y-6">
                    {navigation.map((group) => (
                        <div key={group.label}>
                            <p className="mb-2 px-3 text-[10px] font-semibold uppercase tracking-[0.16em] text-sidebar-foreground/45">
                                {group.label}
                            </p>
                            <div className="space-y-1">
                                {group.items.map((item) => {
                                    const Icon = iconMap[item.icon] ?? Gauge;
                                    const active = isActivePath(item.href, currentPath);
                                    const className = cn(
                                        'group flex min-h-11 items-center gap-3 rounded-xl px-3 text-sm font-medium text-sidebar-foreground/75 transition-colors hover:bg-sidebar-accent hover:text-sidebar-accent-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-sidebar-ring motion-reduce:transition-none',
                                        active && 'bg-sidebar-primary text-sidebar-primary-foreground shadow-sm hover:bg-sidebar-primary hover:text-sidebar-primary-foreground',
                                    );
                                    const content = (
                                        <>
                                            <Icon className="size-[18px] shrink-0" strokeWidth={1.9} />
                                            <span className="truncate">{item.label}</span>
                                        </>
                                    );

                                    return (
                                        <Link
                                            key={item.href}
                                            href={item.href}
                                            className={className}
                                            aria-current={active ? 'page' : undefined}
                                            onClick={onClose}
                                        >
                                            {content}
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>
                    ))}
                </nav>
            </div>

            <div className="border-t border-sidebar-border/70 p-3">
                <div className="flex items-center gap-3 rounded-xl bg-sidebar-accent/60 px-3 py-3">
                    <InitialsAvatar name={user?.name} size="sm" className="bg-sidebar-primary/20 text-sidebar-primary-foreground ring-sidebar-primary/30" />
                    <div className="min-w-0 flex-1">
                        <p className="truncate text-sm font-semibold text-sidebar-accent-foreground">{user?.name ?? 'Pengguna'}</p>
                        <p className="truncate text-xs text-sidebar-foreground/55">
                            {user?.employee?.position ?? user?.roles?.[0] ?? 'Staf'}
                        </p>
                    </div>
                </div>
            </div>
        </aside>
    );
}
