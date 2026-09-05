import { useEffect } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertCircle,
    BarChart3,
    CalendarDays,
    CheckCircle2,
    ClipboardList,
    Clock3,
    Download,
    TrendingUp,
} from 'lucide-react';

import DataState from '@/components/feedback/DataState';
import MetricCard from '@/components/data-display/MetricCard';
import StatusBadge from '@/components/common/StatusBadge';
import TrendChart from '@/components/data-display/TrendChart';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import InitialsAvatar from '@/components/common/InitialsAvatar';
import { Button } from '@/components/ui/button';
import { formatNumber, formatPercent, formatShortDate } from '@/utils/formatters';

function periodStatus(status) {
    if (status === 'OPEN') {
        return 'Sedang berjalan';
    }

    if (status === 'LOCKED') {
        return 'Dikunci';
    }

    if (status === 'CLOSED') {
        return 'Ditutup';
    }

    return status ?? 'Belum tersedia';
}

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

function SectionHeading({ title, description }) {
    return (
        <div>
            <CardTitle>{title}</CardTitle>
            {description && <CardDescription className="mt-1">{description}</CardDescription>}
        </div>
    );
}

function ScoreValue({ score }) {
    return (
        <span className="text-sm font-semibold text-foreground">
            {score === null || score === undefined ? 'Belum dinilai' : formatPercent(score)}
        </span>
    );
}

export default function Dashboard({
    activePeriod,
    metrics,
    trend = [],
    topPerformers = [],
    recentKpis = [],
    attentionKpis = [],
    notifications = [],
    canExport = false,
    filters = {},
    error = null,
}) {
    useEffect(() => {
        if (!window.Echo) {
            return undefined;
        }

        const channel = window.Echo.private('kpi-updates');
        channel.listen('.kpi.updated', () => {
            router.reload({
                only: ['activePeriod', 'metrics', 'trend', 'topPerformers', 'recentKpis', 'attentionKpis', 'notifications'],
            });
        });

        return () => window.Echo.leave('private-kpi-updates');
    }, []);

    if (error) {
        return (
            <>
                <Head title="Dashboard" />
                <div className="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    <Card>
                        <DataState variant="error" title="Dashboard tidak dapat dimuat" description={error} />
                    </Card>
                </div>
            </>
        );
    }

    if (!metrics) {
        return (
            <>
                <Head title="Dashboard" />
                <div className="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    <Card>
                        <DataState variant="loading" title="Memuat ringkasan KPI" description="Data dashboard sedang disiapkan dari server." />
                    </Card>
                </div>
            </>
        );
    }

    const evaluatedDescription = metrics.evaluated > 0
        ? `${formatNumber(metrics.evaluated, 0)} KPI sudah memiliki skor final.`
        : 'Skor final belum tersedia.';
    const exportParams = new URLSearchParams();
    if (filters.period_id) exportParams.set('period_id', filters.period_id);
    if (filters.position_id) exportParams.set('position_id', filters.position_id);
    const exportUrl = (format) => `/app/reports/kpi.${format}${exportParams.toString() ? `?${exportParams}` : ''}`;
    const updateFilter = (key, value) => {
        const next = {};
        const periodId = key === 'period_id' ? value : filters.period_id;
        const positionId = key === 'position_id' ? value : filters.position_id;
        if (periodId) next.period_id = periodId;
        if (positionId) next.position_id = positionId;

        router.get('/app', next, { preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="Dashboard" />

            <div className="mx-auto w-full max-w-[1440px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                <div className="mb-7 flex flex-col justify-between gap-5 sm:flex-row sm:items-end">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-primary">Ringkasan KPI</p>
                        <h1 className="mt-2 text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">Dashboard</h1>
                        <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                            Pantau capaian, penilaian terbaru, dan item yang membutuhkan perhatian.
                        </p>
                    </div>

                    <div className="flex flex-wrap items-center gap-3">
                        {canExport && (
                            <div className="flex flex-wrap gap-2">
                                {['csv', 'xlsx', 'pdf'].map((format) => (
                                    <Button key={format} asChild variant="outline">
                                        <a href={exportUrl(format)}>
                                            {format === 'csv' && <Download className="size-4" />}
                                            Export {format.toUpperCase()}
                                        </a>
                                    </Button>
                                ))}
                            </div>
                        )}
                        <div className="inline-flex w-fit items-center gap-3 rounded-2xl border border-border bg-card px-4 py-3 shadow-sm shadow-slate-950/[0.025]">
                            <span className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                                <CalendarDays className="size-5" strokeWidth={1.8} />
                            </span>
                            <div>
                                <p className="text-[11px] font-medium text-muted-foreground">Periode aktif</p>
                                <p className="mt-0.5 text-sm font-semibold text-foreground">{activePeriod?.name ?? 'Belum tersedia'}</p>
                                <p className="mt-0.5 text-xs text-muted-foreground">{periodStatus(activePeriod?.status)}</p>
                            </div>
                        </div>
                    </div>

                    {canExport && (filters.periods?.length > 0 || filters.positions?.length > 0) && (
                        <div className="mt-4 flex flex-wrap gap-3">
                            {filters.periods?.length > 0 && (
                                <label className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                    Periode
                                    <select
                                        value={filters.period_id ?? ''}
                                        onChange={(event) => updateFilter('period_id', event.target.value)}
                                        className="h-9 rounded-lg border border-input bg-background px-2.5 text-sm font-normal text-foreground outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    >
                                        {filters.periods.map((period) => <option key={period.value} value={period.value}>{period.label}</option>)}
                                    </select>
                                </label>
                            )}
                            {filters.positions?.length > 0 && (
                                <label className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                                    Jabatan
                                    <select
                                        value={filters.position_id ?? ''}
                                        onChange={(event) => updateFilter('position_id', event.target.value)}
                                        className="h-9 rounded-lg border border-input bg-background px-2.5 text-sm font-normal text-foreground outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    >
                                        <option value="">Semua jabatan</option>
                                        {filters.positions.map((position) => <option key={position.value} value={position.value}>{position.label}</option>)}
                                    </select>
                                </label>
                            )}
                        </div>
                    )}
                </div>

                <section aria-labelledby="metric-heading">
                    <h2 id="metric-heading" className="sr-only">Ringkasan metrik KPI</h2>
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                        <MetricCard
                            label="Total KPI"
                            value={metrics.total}
                            description={evaluatedDescription}
                            icon={ClipboardList}
                            tone="primary"
                        />
                        <MetricCard
                            label="Rata-rata pencapaian"
                            value={metrics.average}
                            description={activePeriod?.name ? `Skor final pada ${activePeriod.name}.` : 'Periode KPI belum tersedia.'}
                            icon={TrendingUp}
                            tone="primary"
                            percentage
                        />
                        <MetricCard
                            label="KPI tercapai"
                            value={metrics.achieved}
                            description={metrics.evaluated > 0 ? 'Skor final minimal 80.' : 'Belum ada penilaian final.'}
                            icon={CheckCircle2}
                            tone="success"
                        />
                        <MetricCard
                            label="Belum tercapai"
                            value={metrics.not_achieved}
                            description="Skor final di bawah 80."
                            icon={AlertCircle}
                            tone="danger"
                        />
                    </div>
                </section>

                <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(19rem,0.85fr)]">
                    <Card className="border-border/80 shadow-sm shadow-slate-950/[0.025]">
                        <CardHeader className="border-b border-border/70 px-5 py-5 sm:px-6">
                            <SectionHeading
                                title="Trend pencapaian"
                                description="Rata-rata skor final pada periode yang tersedia."
                            />
                        </CardHeader>
                        <CardContent className="px-4 py-5 sm:px-6">
                            <TrendChart data={trend} />
                        </CardContent>
                    </Card>

                    <Card className="border-border/80 shadow-sm shadow-slate-950/[0.025]">
                        <CardHeader className="border-b border-border/70 px-5 py-5 sm:px-6">
                            <SectionHeading
                                title="Performa terbaik"
                                description={activePeriod?.name ?? 'Periode aktif'}
                            />
                        </CardHeader>
                        <CardContent className="px-5 py-4 sm:px-6">
                            {topPerformers.length === 0 ? (
                                <DataState
                                    compact
                                    title="Belum ada peringkat"
                                    description="Peringkat muncul setelah skor final tersedia."
                                />
                            ) : (
                                <ol className="space-y-2">
                                    {topPerformers.map((performer, index) => (
                                        <li key={performer.employee_id} className="flex items-center gap-3 rounded-xl px-2 py-3 hover:bg-muted/60">
                                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-xs font-semibold text-muted-foreground">
                                                {index + 1}
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-semibold text-foreground">{performer.name}</p>
                                                <p className="mt-0.5 truncate text-xs text-muted-foreground">{performer.position ?? 'Posisi belum tersedia'}</p>
                                            </div>
                                            <span className="shrink-0 text-sm font-semibold text-primary">{formatPercent(performer.average)}</span>
                                        </li>
                                    ))}
                                </ol>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <div className="mt-6 grid gap-6 xl:grid-cols-[minmax(0,1.35fr)_minmax(19rem,0.65fr)]">
                    <Card className="border-border/80 shadow-sm shadow-slate-950/[0.025]">
                        <CardHeader className="border-b border-border/70 px-5 py-5 sm:px-6">
                            <SectionHeading title="KPI terbaru" description="Perubahan terakhir pada periode aktif." />
                        </CardHeader>
                        <CardContent className="px-3 py-3 sm:px-4">
                            {recentKpis.length === 0 ? (
                                <DataState
                                    compact
                                    title="Belum ada KPI pada periode ini"
                                    description="Data akan tampil setelah KPI dibuat atau diperbarui."
                                />
                            ) : (
                                <div className="divide-y divide-border/70">
                                    {recentKpis.map((kpi) => (
                                        <div key={kpi.id} className="flex items-center gap-3 px-2 py-4 sm:px-3">
                                            <InitialsAvatar name={kpi.name} size="sm" />
                                            <div className="min-w-0 flex-1">
                                                <p className="truncate text-sm font-semibold text-foreground">{kpi.name}</p>
                                                <p className="mt-0.5 truncate text-xs text-muted-foreground">{kpi.position ?? 'Posisi belum tersedia'}</p>
                                            </div>
                                            <div className="hidden shrink-0 sm:block">
                                                <StatusBadge status={kpi.status} />
                                            </div>
                                            <div className="shrink-0 text-right">
                                                <ScoreValue score={kpi.score} />
                                                <p className="mt-0.5 text-[11px] text-muted-foreground">{formatShortDate(kpi.updated_at)}</p>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    <Card className="border-border/80 shadow-sm shadow-slate-950/[0.025]">
                        <CardHeader className="border-b border-border/70 px-5 py-5 sm:px-6">
                            <SectionHeading title="Perlu perhatian" description="Item yang perlu ditindaklanjuti." />
                        </CardHeader>
                        <CardContent className="px-3 py-3 sm:px-4">
                            {attentionKpis.length === 0 ? (
                                <DataState
                                    compact
                                    title="Tidak ada item prioritas"
                                    description="Belum ada KPI yang cocok dengan status revisi, approval, atau skor di bawah 80."
                                />
                            ) : (
                                <div className="space-y-2">
                                    {attentionKpis.map((kpi) => (
                                        <div key={kpi.id} className="rounded-xl px-2 py-3 hover:bg-muted/60 sm:px-3">
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-semibold text-foreground">{kpi.name}</p>
                                                    <p className="mt-0.5 truncate text-xs text-muted-foreground">{kpi.position ?? 'Posisi belum tersedia'}</p>
                                                </div>
                                                <ScoreValue score={kpi.score} />
                                            </div>
                                            <div className="mt-2">
                                                <StatusBadge status={kpi.status} />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </div>

                <Card className="mt-6 border-border/80 shadow-sm shadow-slate-950/[0.025]">
                    <CardHeader className="border-b border-border/70 px-5 py-5 sm:px-6">
                        <SectionHeading title="Aktivitas terbaru" description="Notifikasi yang dikirim ke akun ini." />
                    </CardHeader>
                    <CardContent className="px-3 py-3 sm:px-4">
                        {notifications.length === 0 ? (
                            <DataState
                                compact
                                title="Belum ada aktivitas"
                                description="Notifikasi baru untuk akun ini akan muncul di sini."
                            />
                        ) : (
                            <div className="grid gap-2 md:grid-cols-2">
                                {notifications.map((notification) => {
                                    const item = (
                                        <>
                                            <span className="mt-1 flex size-8 shrink-0 items-center justify-center rounded-lg bg-muted text-muted-foreground">
                                                {notification.type === 'warning' ? <AlertCircle className="size-4" /> : <Clock3 className="size-4" />}
                                            </span>
                                            <span className="min-w-0">
                                                <span className="block truncate text-sm font-semibold text-foreground">{notification.title}</span>
                                                <span className="mt-1 block line-clamp-2 text-xs leading-5 text-muted-foreground">{notification.body}</span>
                                                <span className="mt-2 block text-[11px] text-muted-foreground/80">{notificationTime(notification.created_at)}</span>
                                            </span>
                                        </>
                                    );

                                    if (notification.action_url?.startsWith('/')) {
                                        return <Link key={notification.id} href={notification.action_url} className="flex gap-3 rounded-xl px-3 py-3 hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">{item}</Link>;
                                    }

                                    return notification.action_url ? (
                                        <a key={notification.id} href={notification.action_url} className="flex gap-3 rounded-xl px-3 py-3 hover:bg-muted/60 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring">
                                            {item}
                                        </a>
                                    ) : (
                                        <div key={notification.id} className="flex gap-3 rounded-xl px-3 py-3">
                                            {item}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <p className="mt-6 flex items-center gap-2 text-xs text-muted-foreground">
                    <BarChart3 className="size-4 text-primary" />
                    Metrik dihitung dari skor KPI yang tersedia pada server.
                </p>
            </div>
        </>
    );
}
