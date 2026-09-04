import { useState } from 'react';
import {
    Bell,
    CalendarDays,
    ChevronDown,
    LogOut,
    Menu,
} from 'lucide-react';

import InitialsAvatar from '@/components/common/InitialsAvatar';
import { cn } from '@/lib/utils';

function notificationTime(value) {
    if (!value) {
        return 'Waktu tidak tersedia';
    }

    return new Intl.DateTimeFormat('id-ID', {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    }).format(new Date(value));
}

export default function Topbar({
    user,
    activePeriod,
    notifications = [],
    onOpenMenu,
}) {
    const [notificationOpen, setNotificationOpen] = useState(false);
    const [profileOpen, setProfileOpen] = useState(false);
    const unreadCount = notifications.filter((notification) => !notification.is_read).length;
    const csrfToken = typeof document === 'undefined'
        ? ''
        : document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';

    return (
        <header className="sticky top-0 z-30 flex min-h-[76px] items-center justify-between gap-4 border-b border-border/80 bg-background/95 px-4 backdrop-blur-sm sm:px-6 lg:px-8">
            <div className="flex min-w-0 items-center gap-3">
                <button
                    type="button"
                    onClick={onOpenMenu}
                    className="inline-flex size-11 shrink-0 items-center justify-center rounded-xl border border-border bg-card text-foreground shadow-sm hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring lg:hidden"
                    aria-label="Buka navigasi"
                >
                    <Menu className="size-5" />
                </button>
                <div className="min-w-0">
                    <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                        <span>Dashboard</span>
                        <span aria-hidden="true" className="text-border">/</span>
                        <span className="truncate text-foreground">Ringkasan</span>
                    </div>
                    <p className="mt-1 truncate text-sm font-semibold text-foreground sm:text-base">KPI System</p>
                </div>
            </div>

            <div className="flex shrink-0 items-center gap-1.5 sm:gap-2">
                <div className="hidden items-center gap-2 rounded-xl border border-border bg-card px-3 py-2 text-left sm:flex">
                    <CalendarDays className="size-4 text-primary" strokeWidth={1.8} />
                    <div>
                        <p className="text-[10px] font-medium uppercase tracking-wide text-muted-foreground">Periode aktif</p>
                        <p className="max-w-36 truncate text-xs font-semibold text-foreground">
                            {activePeriod?.name ?? 'Belum tersedia'}
                        </p>
                    </div>
                </div>

                <div className="relative">
                    <button
                        type="button"
                        onClick={() => setNotificationOpen((open) => !open)}
                        onKeyDown={(event) => event.key === 'Escape' && setNotificationOpen(false)}
                        className="relative inline-flex size-11 items-center justify-center rounded-xl text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label={unreadCount ? `${unreadCount} notifikasi belum dibaca` : 'Notifikasi'}
                        aria-expanded={notificationOpen}
                        aria-haspopup="menu"
                    >
                        <Bell className="size-[18px]" />
                        {unreadCount > 0 && (
                            <span className="absolute right-1.5 top-1.5 inline-flex min-w-4 items-center justify-center rounded-full bg-rose-500 px-1 text-[10px] font-bold leading-4 text-white ring-2 ring-background">
                                {unreadCount > 9 ? '9+' : unreadCount}
                            </span>
                        )}
                    </button>

                    {notificationOpen && (
                        <div className="absolute right-0 top-14 z-50 w-[min(22rem,calc(100vw-2rem))] overflow-hidden rounded-2xl border border-border bg-popover shadow-xl shadow-slate-950/10" role="menu">
                            <div className="flex items-center justify-between border-b border-border px-4 py-3">
                                <div>
                                    <p className="text-sm font-semibold text-popover-foreground">Notifikasi</p>
                                    <p className="text-xs text-muted-foreground">Informasi terbaru untuk akun ini</p>
                                </div>
                                {unreadCount > 0 && <span className="text-xs font-medium text-primary">{unreadCount} baru</span>}
                            </div>
                            <div className="max-h-80 overflow-y-auto p-2">
                                {notifications.length === 0 ? (
                                    <p className="px-3 py-6 text-center text-sm text-muted-foreground">Belum ada notifikasi untuk akun ini.</p>
                                ) : notifications.map((notification) => {
                                    const content = (
                                        <>
                                            <span className={cn('mt-1 size-2 shrink-0 rounded-full', notification.is_read ? 'bg-border' : 'bg-primary')} />
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-medium text-popover-foreground">{notification.title}</span>
                                                <span className="mt-0.5 block line-clamp-2 text-xs leading-5 text-muted-foreground">{notification.body}</span>
                                                <span className="mt-1 block text-[11px] text-muted-foreground/80">{notificationTime(notification.created_at)}</span>
                                            </span>
                                        </>
                                    );

                                    return notification.action_url ? (
                                        <a
                                            key={notification.id}
                                            href={notification.action_url}
                                            className="flex gap-3 rounded-xl px-3 py-3 hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                            role="menuitem"
                                            onClick={() => setNotificationOpen(false)}
                                        >
                                            {content}
                                        </a>
                                    ) : (
                                        <div key={notification.id} className="flex gap-3 rounded-xl px-3 py-3" role="menuitem">
                                            {content}
                                        </div>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                </div>

                <div className="relative ml-1 border-l border-border pl-2 sm:ml-2 sm:pl-3">
                    <button
                        type="button"
                        onClick={() => setProfileOpen((open) => !open)}
                        onKeyDown={(event) => event.key === 'Escape' && setProfileOpen(false)}
                        className="flex items-center gap-2 rounded-xl p-1.5 text-left hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        aria-label="Buka menu pengguna"
                        aria-expanded={profileOpen}
                        aria-haspopup="menu"
                    >
                        <InitialsAvatar name={user?.name} size="sm" />
                        <span className="hidden max-w-28 truncate text-sm font-semibold text-foreground md:block">{user?.name ?? 'Pengguna'}</span>
                        <ChevronDown className="hidden size-4 text-muted-foreground sm:block" />
                    </button>

                    {profileOpen && (
                        <div className="absolute right-0 top-14 z-50 w-56 rounded-2xl border border-border bg-popover p-2 shadow-xl shadow-slate-950/10" role="menu">
                            <div className="border-b border-border px-3 pb-3 pt-2">
                                <p className="truncate text-sm font-semibold text-popover-foreground">{user?.name ?? 'Pengguna'}</p>
                                <p className="truncate text-xs text-muted-foreground">{user?.email ?? ''}</p>
                            </div>
                            <a href="/app" className="mt-2 flex min-h-11 items-center rounded-xl px-3 text-sm text-popover-foreground hover:bg-muted focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring" role="menuitem">
                                Kembali ke dashboard
                            </a>
                            <form method="POST" action="/logout">
                                <input type="hidden" name="_token" value={csrfToken} />
                                <button type="submit" className="flex min-h-11 w-full items-center gap-2 rounded-xl px-3 text-sm text-rose-600 hover:bg-rose-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-rose-500 dark:hover:bg-rose-950/30" role="menuitem">
                                    <LogOut className="size-4" />
                                    Keluar
                                </button>
                            </form>
                        </div>
                    )}
                </div>
            </div>
        </header>
    );
}
