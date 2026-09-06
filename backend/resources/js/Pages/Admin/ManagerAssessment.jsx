import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, RotateCcw } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useFeedback } from '@/components/feedback/ActionFeedback';

const statusLabels = {
    verified: 'Terverifikasi',
    assessed: 'Dinilai Manager',
    pending: 'Menunggu Nilai',
};

export default function ManagerAssessment({ kpi }) {
    const { requestAction } = useFeedback();
    const [decisions, setDecisions] = useState(() => Object.fromEntries(
        kpi.items.map((item) => [item.id, item.manager_decision ?? 'valid']),
    ));
    const [notes, setNotes] = useState(() => Object.fromEntries(
        kpi.items.map((item) => [item.id, item.manager_note ?? '']),
    ));
    const actions = kpi.available_actions ?? [];

    const assessItem = (item) => {
        router.post(`/app/employee-kpis/${kpi.id}/items/${item.id}/assessment`, {
            decision: decisions[item.id] || 'valid',
            note: notes[item.id] || null,
        }, { preserveScroll: true });
    };

    const approve = async () => {
        const result = await requestAction({
            title: 'Sahkan rekap KPI?',
            description: 'Periksa hasil dan bukti. Skor karyawan tersedia setelah publikasi Admin KPI.',
            confirmLabel: 'Sahkan KPI',
        });
        if (result.confirmed) router.post(`/app/employee-kpis/${kpi.id}/assessment/approve`, { note: result.value });
    };

    const returnToSupervisor = async () => {
        const result = await requestAction({
            title: 'Kembalikan ke Supervisor?',
            description: 'Alasan wajib diisi agar Supervisor dapat menindaklanjuti.',
            confirmLabel: 'Kembalikan',
            variant: 'destructive',
            prompt: { name: 'reason', label: 'Alasan pengembalian', required: true, minLength: 3 },
        });
        if (result.confirmed) router.post(`/app/employee-kpis/${kpi.id}/assessment/return`, { reason: result.value });
    };

    return (
        <>
            <Head title={`Penilaian Manager ${kpi.employee.name}`} />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-[1440px] space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <Link href="/app/employee-kpis" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"><ArrowLeft className="size-3.5" />Kembali ke penilaian KPI</Link>
                            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Penilaian Manager: {kpi.employee.name}</h1>
                            <p className="mt-1 text-sm text-muted-foreground">{kpi.employee.position} · {kpi.employee.branch} · {kpi.period}</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {actions.includes('return') && <Button type="button" variant="outline" onClick={returnToSupervisor}><RotateCcw />Kembalikan indikator bermasalah</Button>}
                            {actions.includes('approve') && <Button type="button" onClick={approve}><CheckCircle2 />Sahkan KPI</Button>}
                        </div>
                    </div>

                    <Card>
                        <CardHeader className="border-b border-border/70">
                            <CardTitle>Hasil penilaian dan bukti</CardTitle>
                            <CardDescription>Rekap berasal dari fakta dan penilaian harian. Tandai indikator yang perlu koreksi beserta alasan, atau sahkan rekap yang sudah lengkap.</CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[1050px] text-left text-sm">
                                    <thead className="border-b border-border/70 bg-muted/35 text-[11px] uppercase tracking-[0.12em] text-muted-foreground"><tr><th className="px-5 py-3">Indikator</th><th className="px-5 py-3">Target</th><th className="px-5 py-3">Actual sistem</th><th className="px-5 py-3">Pencapaian</th><th className="px-5 py-3">Skor bobot</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Keputusan Manager</th></tr></thead>
                                    <tbody className="divide-y divide-border/70">
                                        {kpi.items.map((item) => {
                                            return (
                                                <tr key={item.id} className="align-top">
                                                    <td className="px-5 py-4"><p className="font-semibold text-foreground">{item.code}</p><p className="mt-1 max-w-xs text-muted-foreground">{item.name}</p><p className="mt-1 text-xs text-muted-foreground">Bobot {item.weight}%</p></td>
                                                    <td className="px-5 py-4 text-muted-foreground">{item.target ?? '—'} {item.unit}</td>
                                                    <td className="px-5 py-4 font-medium text-foreground">{item.actual ?? '—'}</td>
                                                    <td className="px-5 py-4 text-muted-foreground">{item.achievement ?? '—'}%</td>
                                                    <td className="px-5 py-4 text-muted-foreground">{item.weighted_score ?? '—'}</td>
                                                    <td className="px-5 py-4"><Badge variant="outline">{statusLabels[item.status] ?? item.status}</Badge></td>
                                                    <td className="px-5 py-4">
                                                        {actions.includes('decide') && <div className="min-w-72 space-y-2">
                                                            <select
                                                                aria-label={`Keputusan ${item.code}`}
                                                                value={decisions[item.id] ?? 'valid'}
                                                                onChange={(event) => setDecisions((current) => ({ ...current, [item.id]: event.target.value }))}
                                                                className="flex h-9 w-full rounded-lg border border-input bg-background px-2 text-sm text-foreground"
                                                            >
                                                                <option value="valid">Valid</option>
                                                                <option value="needs_correction">Perlu koreksi</option>
                                                                <option value="data_exception">Data Exception</option>
                                                            </select>
                                                            <textarea
                                                                aria-label={`Catatan ${item.code}`}
                                                                value={notes[item.id] ?? ''}
                                                                onChange={(event) => setNotes((current) => ({ ...current, [item.id]: event.target.value }))}
                                                                className="min-h-16 w-full rounded-lg border border-input bg-background px-2 py-1.5 text-xs text-foreground"
                                                                placeholder="Alasan wajib untuk indikator yang perlu koreksi"
                                                            />
                                                            <Button type="button" size="sm" onClick={() => assessItem(item)}>Simpan keputusan</Button>
                                                        </div>}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
