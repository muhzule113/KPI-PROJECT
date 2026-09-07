import { Head, Link, router } from '@inertiajs/react';
import { CalendarDays } from 'lucide-react';
import { useState } from 'react';

import DataState from '@/components/feedback/DataState';
import { Button } from '@/components/ui/button';
import { DatePicker, formatDateValue } from '@/components/ui/date-picker';
import { formatShortDate } from '@/utils/formatters';

function EmployeeRow({ item, completed = false }) {
    const action = item.primary_action;

    return (
        <li className="flex flex-col gap-4 border-b border-border px-4 py-5 last:border-b-0 sm:flex-row sm:items-center sm:justify-between sm:px-5">
            <div className="min-w-0">
                <p className="break-words font-semibold text-foreground">{item.employee.name ?? 'Karyawan tanpa nama'}</p>
                <p className="mt-1 break-words text-sm text-muted-foreground">
                    {item.employee.position ?? 'Jabatan belum tersedia'}, {item.employee.branch ?? 'Cabang belum tersedia'}
                </p>
                <p className="mt-2 break-words text-sm text-foreground/80">{item.status}</p>
            </div>
            {!completed && action && (
                <Button asChild className="min-h-11 shrink-0 sm:min-w-36">
                    <Link href={action.url}>{action.label}</Link>
                </Button>
            )}
        </li>
    );
}

export default function TeamTasks({
    title = 'Penilaian Tim',
    date = null,
    employees = [],
    completed_employees: completedEmployees = [],
    empty_state: emptyState = null,
    message = null,
}) {
    const [tab, setTab] = useState('pending');
    const [loading, setLoading] = useState(false);
    const rows = tab === 'pending' ? employees : completedEmployees;
    const today = formatDateValue();
    const dateLabel = date ? `${date === today ? 'Hari ini, ' : ''}${formatShortDate(`${date}T00:00:00`)}` : 'Semua tanggal';
    const changeDate = (value) => router.get('/app/team-tasks', { date: value }, {
        replace: true,
        preserveState: false,
        onStart: () => setLoading(true),
        onFinish: () => setLoading(false),
    });

    const emptyTitle = tab === 'pending' ? 'Tidak ada penilaian yang tertunda' : 'Belum ada penilaian selesai';
    const emptyDescription = tab === 'pending'
        ? (emptyState?.description ?? 'Semua tindakan wajib yang sudah disiapkan telah selesai.')
        : 'Nama akan muncul setelah seluruh tindakan wajib yang sudah disiapkan selesai.';

    return (
        <>
            <Head title={title} />
            <main className="mx-auto w-full max-w-4xl space-y-6 px-4 py-6 sm:px-6 lg:py-8">
                <header className="space-y-2">
                    <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{title}</h1>
                    <p className="max-w-2xl text-sm text-muted-foreground">Mulai dari nama paling atas, lalu selesaikan satu karyawan sebelum beralih ke nama berikutnya.</p>
                </header>

                <div className="flex flex-col gap-4 border-y border-border py-4 sm:flex-row sm:items-end sm:justify-between">
                    <div className="grid grid-cols-2 gap-1 rounded-lg border border-border bg-muted/30 p-1" role="tablist" aria-label="Status penilaian tim">
                        <Button type="button" variant={tab === 'pending' ? 'default' : 'ghost'} className="min-h-11" role="tab" aria-selected={tab === 'pending'} onClick={() => setTab('pending')}>Belum selesai</Button>
                        <Button type="button" variant={tab === 'completed' ? 'default' : 'ghost'} className="min-h-11" role="tab" aria-selected={tab === 'completed'} onClick={() => setTab('completed')}>Selesai</Button>
                    </div>
                    <div className="min-w-0 space-y-3 sm:min-w-80">
                        <div className="flex items-center gap-3">
                            <span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary" aria-hidden="true">
                                <CalendarDays className="size-5" />
                            </span>
                            <div className="min-w-0">
                                <label htmlFor="task-date" className="block text-sm font-medium">Filter tanggal</label>
                                <p id="task-date-description" className="truncate text-sm text-muted-foreground">{dateLabel}</p>
                            </div>
                        </div>
                        <div className="flex flex-col gap-2 min-[420px]:flex-row">
                            <DatePicker
                                id="task-date"
                                value={date ?? ''}
                                max={today}
                                aria-describedby="task-date-description"
                                aria-label="Filter tanggal"
                                onChange={changeDate}
                                clearable
                                clearLabel="Semua tanggal"
                                className="min-[420px]:w-auto"
                            />
                        </div>
                    </div>
                </div>

                {loading && <p className="text-sm text-muted-foreground" role="status">Memuat penilaian tim...</p>}
                {message && <div className="rounded-lg border border-border"><DataState variant="error" title="Penilaian tim tidak dapat dimuat" description={message} compact /></div>}
                {!message && !loading && rows.length === 0 && (
                    <div className="rounded-lg border border-border"><DataState title={emptyTitle} description={emptyDescription} /></div>
                )}
                {!message && !loading && rows.length > 0 && (
                    <section aria-label={tab === 'pending' ? 'Penilaian belum selesai' : 'Penilaian selesai'} className="overflow-hidden rounded-lg border border-border bg-card">
                        <ul>{rows.map((item) => <EmployeeRow key={item.employee.id} item={item} completed={tab === 'completed'} />)}</ul>
                    </section>
                )}
            </main>
        </>
    );
}
