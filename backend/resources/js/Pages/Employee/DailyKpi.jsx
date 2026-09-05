import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { ArrowLeft, CalendarDays } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

const statusLabels = {
    draft: 'Draft',
    submitted: 'Menunggu review',
    pending: 'Menunggu',
    approved: 'Disetujui',
    revision_required: 'Perlu revisi',
};

export default function DailyKpi({ date, period, kpi, items = [], message }) {
    const [values, setValues] = useState({});

    useEffect(() => {
        setValues(Object.fromEntries(items.map((item) => [item.id, item.employee_actual ?? ''])));
    }, [items]);

    const changeDate = (event) => {
        router.get('/app/my-kpi/daily', { date: event.target.value }, { preserveState: false, replace: true });
    };

    const save = (submit = false) => {
        router.post('/app/my-kpi/daily', {
            date,
            submit,
            items: items
                .filter((item) => item.editable)
                .map((item) => ({
                    item_id: item.id,
                    actual_decimal: values[item.id] === '' ? null : values[item.id],
                })),
        }, { preserveScroll: true });
    };

    return (
        <>
            <Head title="KPI Harian Saya" />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-[1440px] space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <Link href="/app" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground">
                                <ArrowLeft className="size-3.5" />Dashboard
                            </Link>
                            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">KPI Harian Saya</h1>
                            <p className="mt-1 text-sm text-muted-foreground">Isi indikator milik Anda; indikator operasional lain akan diisi atau divalidasi sumber resminya.</p>
                        </div>
                        <div className="flex items-center gap-2 rounded-xl border border-border/70 bg-card px-3 py-2">
                            <CalendarDays className="size-4 text-primary" />
                            <label htmlFor="daily-date" className="sr-only">Tanggal KPI</label>
                            <Input id="daily-date" type="date" value={date} max={new Date().toISOString().slice(0, 10)} onChange={changeDate} className="w-auto" />
                        </div>
                    </div>

                    {message && (
                        <Card><CardContent className="p-6"><p className="text-sm text-muted-foreground">{message}</p></CardContent></Card>
                    )}

                    {kpi && (
                        <>
                            <div className="grid gap-4 sm:grid-cols-3">
                                <Card><CardContent className="p-5"><p className="text-xs text-muted-foreground">Periode</p><p className="mt-2 font-semibold">{period?.name ?? '—'}</p></CardContent></Card>
                                <Card><CardContent className="p-5"><p className="text-xs text-muted-foreground">Status KPI bulanan</p><p className="mt-2"><Badge variant="outline">{kpi.status}</Badge></p></CardContent></Card>
                                <Card><CardContent className="p-5"><p className="text-xs text-muted-foreground">Total sementara bulan ini</p><p className="mt-2 text-2xl font-semibold">{kpi.final_score ?? '—'}</p></CardContent></Card>
                            </div>

                            <Card className="overflow-hidden">
                                <CardHeader className="border-b border-border/70">
                                    <CardTitle>Indikator untuk {date}</CardTitle>
                                    <CardDescription>Indikator bertanda input dapat disimpan sebagai draft lalu dikirim untuk review.</CardDescription>
                                </CardHeader>
                                <CardContent className="p-0">
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[900px] text-left text-sm">
                                            <thead className="border-b border-border/70 bg-muted/35 text-[11px] uppercase tracking-[0.12em] text-muted-foreground">
                                                <tr><th className="px-5 py-3">Indikator</th><th className="px-5 py-3">Target</th><th className="px-5 py-3">Nilai tercatat</th><th className="px-5 py-3">Review</th><th className="px-5 py-3">Status hari ini</th></tr>
                                            </thead>
                                            <tbody className="divide-y divide-border/70">
                                                {items.map((item) => {
                                                    const reviewed = item.manager_status === 'approved' || item.supervisor_status === 'approved';
                                                    const value = item.effective_actual ?? item.effective_rubric_score;
                                                    const hasValue = value !== null && value !== undefined;
                                                    const suffix = hasValue ? (item.formula === 'rubric' || item.unit === '%' ? '%' : ' ' + item.unit) : '';
                                                    const status = item.manager_status === 'revision_required' || item.supervisor_status === 'revision_required'
                                                        ? 'Perlu revisi'
                                                        : reviewed
                                                            ? 'Sudah direview'
                                                            : statusLabels[item.entry_status] ?? item.entry_status;

                                                    return (
                                                        <tr key={item.id} className="align-top">
                                                            <td className="px-5 py-4"><p className="font-semibold">{item.code}</p><p className="mt-1 max-w-xs text-muted-foreground">{item.name}</p><p className="mt-1 text-xs text-muted-foreground">Bobot {item.weight}%</p></td>
                                                            <td className="px-5 py-4 text-muted-foreground">{item.target ?? '—'} {item.unit}</td>
                                                            <td className="px-5 py-4 text-muted-foreground">
                                                                {item.editable ? (
                                                                    <Input
                                                                        type="number"
                                                                        step="any"
                                                                        value={values[item.id] ?? ''}
                                                                        onChange={(event) => setValues((current) => ({ ...current, [item.id]: event.target.value }))}
                                                                        className="w-36"
                                                                    />
                                                                ) : hasValue ? String(value) + suffix : 'Menunggu sistem/review'}
                                                            </td>
                                                            <td className="px-5 py-4 text-muted-foreground">{item.effective_actual ?? item.effective_rubric_score ?? '—'}{hasValue ? suffix : ''}</td>
                                                            <td className="px-5 py-4"><Badge variant={status === 'Perlu revisi' ? 'destructive' : reviewed ? 'default' : 'outline'}>{status}</Badge></td>
                                                        </tr>
                                                    );
                                                })}
                                            </tbody>
                                        </table>
                                    </div>
                                    {items.some((item) => item.editable) && (
                                        <div className="flex justify-end gap-2 border-t border-border/70 p-4">
                                            <button type="button" className="rounded-lg border px-4 py-2 text-sm font-medium" onClick={() => save(false)}>Simpan draft</button>
                                            <button type="button" className="rounded-lg bg-primary px-4 py-2 text-sm font-medium text-primary-foreground" onClick={() => save(true)}>Submit untuk review</button>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>
                        </>
                    )}
                </div>
            </div>
        </>
    );
}
