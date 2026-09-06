import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CalendarDays } from 'lucide-react';
import { useMemo } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

export default function SupervisorAttendance({ date, period, rows = [], statusOptions = [], message }) {
    const recordedCount = useMemo(() => rows.filter((row) => row.status).length, [rows]);

    const changeDate = (event) => {
        router.get('/app/supervisor-attendance', { date: event.target.value }, { preserveState: false, replace: true });
    };

    return (
        <>
            <Head title="Absensi Tim" />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-[1200px] space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <Link href="/app" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"><ArrowLeft className="size-3.5" />Dashboard</Link>
                            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Absensi Tim</h1>
                            <p className="mt-1 max-w-2xl text-sm text-muted-foreground">Hasil absensi berasal dari Review KPI Harian. Halaman ini hanya menampilkan rekap tim.</p>
                        </div>
                        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-border/70 bg-card px-3 py-2">
                            <CalendarDays className="size-4 text-primary" />
                            <label htmlFor="attendance-date" className="sr-only">Tanggal absensi</label>
                            <Input id="attendance-date" type="date" value={date} max={new Date().toISOString().slice(0, 10)} onChange={changeDate} className="w-auto" />
                        </div>
                    </div>

                    {message && <Card role="alert"><CardContent className="p-6"><p className="text-sm text-muted-foreground">{message}</p></CardContent></Card>}

                    <Card className="overflow-hidden">
                        <CardHeader className="border-b border-border/70 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <CardTitle>{period?.name ?? 'Periode KPI'} - {date}</CardTitle>
                                <CardDescription className="mt-1">{recordedCount} dari {rows.length} anggota sudah memiliki status.</CardDescription>
                            </div>
                        </CardHeader>
                        <CardContent className="p-0">
                            {rows.length ? (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[760px] text-left text-sm">
                                        <thead className="border-b border-border/70 bg-muted/35 text-[11px] uppercase tracking-[0.12em] text-muted-foreground">
                                            <tr><th className="px-5 py-3">Karyawan</th><th className="px-5 py-3">Status</th><th className="px-5 py-3">Catatan</th></tr>
                                        </thead>
                                        <tbody className="divide-y divide-border/70">
                                            {rows.map((row) => {
                                                return (
                                                    <tr key={row.employee_id} className="align-middle">
                                                        <td className="px-5 py-4"><p className="font-semibold">{row.name}</p><p className="mt-1 text-xs text-muted-foreground">{row.employee_number} · {row.position ?? 'Karyawan'}</p></td>
                                                        <td className="px-5 py-4"><Badge variant={row.status ? 'default' : 'outline'}>{row.status_label ?? 'Belum dicatat'}</Badge></td>
                                                        <td className="px-5 py-4 text-sm text-muted-foreground">{row.note || '—'}</td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            ) : <div className="p-10 text-center"><p className="font-medium">Belum ada anggota pada snapshot Supervisor.</p><p className="mt-1 text-sm text-muted-foreground">Pastikan assignment Supervisor dan periode KPI sudah aktif.</p></div>}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
