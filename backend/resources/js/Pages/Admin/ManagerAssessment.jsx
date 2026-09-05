import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, ClipboardCheck, RotateCcw, Save } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useFeedback } from '@/components/feedback/ActionFeedback';

const statusLabels = {
    verified: 'Terverifikasi',
    assessed: 'Dinilai Manager',
    pending: 'Menunggu Nilai',
};

export default function ManagerAssessment({ kpi }) {
    const { requestAction } = useFeedback();
    const [values, setValues] = useState(() => Object.fromEntries(
        kpi.items.filter((item) => item.formula !== 'rubric').map((item) => [item.id, item.actual ?? '']),
    ));
    const [rubricAnswers, setRubricAnswers] = useState(() => Object.fromEntries(
        kpi.items
            .filter((item) => item.formula === 'rubric')
            .map((item) => [item.id, (item.assessment?.answers ?? []).filter((answer) => answer.is_fulfilled).map((answer) => String(answer.criterion_id))]),
    ));

    const assessItem = (item) => {
        const value = values[item.id];
        if (value === '' || value === null || value === undefined) return;

        router.post(`/app/employee-kpis/${kpi.id}/items/${item.id}/assessment`, {
            actual_decimal: value,
        }, { preserveScroll: true });
    };

    const assessRubric = (item) => {
        const selected = rubricAnswers[item.id] ?? [];
        router.post(`/app/employee-kpis/${kpi.id}/items/${item.id}/rubric`, {
            answers: (item.rubric?.criteria ?? []).map((criterion) => ({
                criterion_id: criterion.id,
                is_fulfilled: selected.includes(String(criterion.id)),
                notes: null,
            })),
        }, { preserveScroll: true });
    };

    const approve = async () => {
        const result = await requestAction({
            title: 'Approve & kunci KPI?',
            description: 'Pastikan seluruh indikator sudah dinilai. KPI akan menjadi final.',
            confirmLabel: 'Approve & kunci',
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
                            <Button type="button" variant="outline" onClick={returnToSupervisor}><RotateCcw />Kembalikan</Button>
                            <Button type="button" onClick={approve}><CheckCircle2 />Approve & kunci</Button>
                        </div>
                    </div>

                    <Card>
                        <CardHeader className="border-b border-border/70">
                            <CardTitle>Nilai seluruh indikator</CardTitle>
                            <CardDescription>Indikator numerik diisi dengan nilai aktual. Indikator rubric dinilai melalui checklist. Sistem menghitung pencapaian dan skor berbobot.</CardDescription>
                        </CardHeader>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[1050px] text-left text-sm">
                                    <thead className="border-b border-border/70 bg-muted/35 text-[11px] uppercase tracking-[0.12em] text-muted-foreground"><tr><th className="px-5 py-3">Indikator</th><th className="px-5 py-3">Target</th><th className="px-5 py-3">Nilai saat ini</th><th className="px-5 py-3">Pencapaian</th><th className="px-5 py-3">Skor bobot</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Penilaian Manager</th></tr></thead>
                                    <tbody className="divide-y divide-border/70">
                                        {kpi.items.map((item) => {
                                            const criteria = item.rubric?.criteria ?? [];
                                            const selected = rubricAnswers[item.id] ?? [];

                                            return (
                                                <tr key={item.id} className="align-top">
                                                    <td className="px-5 py-4"><p className="font-semibold text-foreground">{item.code}</p><p className="mt-1 max-w-xs text-muted-foreground">{item.name}</p><p className="mt-1 text-xs text-muted-foreground">Bobot {item.weight}%</p></td>
                                                    <td className="px-5 py-4 text-muted-foreground">{item.target ?? '—'} {item.unit}</td>
                                                    <td className="px-5 py-4 font-medium text-foreground">{item.actual ?? '—'}</td>
                                                    <td className="px-5 py-4 text-muted-foreground">{item.achievement ?? '—'}%</td>
                                                    <td className="px-5 py-4 text-muted-foreground">{item.weighted_score ?? '—'}</td>
                                                    <td className="px-5 py-4"><Badge variant="outline">{statusLabels[item.status] ?? item.status}</Badge></td>
                                                    <td className="px-5 py-4">
                                                        {item.formula === 'rubric' ? (
                                                            <div className="min-w-72 space-y-2">
                                                                {criteria.map((criterion) => <label key={criterion.id} className="flex gap-2 text-xs text-foreground"><input type="checkbox" checked={selected.includes(String(criterion.id))} onChange={(event) => setRubricAnswers((current) => { const next = new Set(current[item.id] ?? []); event.target.checked ? next.add(String(criterion.id)) : next.delete(String(criterion.id)); return { ...current, [item.id]: [...next] }; })} className="mt-0.5 size-3.5 accent-primary" />{criterion.criterion_text}</label>)}
                                                                <Button type="button" size="sm" onClick={() => assessRubric(item)}><ClipboardCheck />Simpan checklist</Button>
                                                            </div>
                                                        ) : (
                                                            <div className="flex min-w-64 gap-2">
                                                                <Input type="number" step="any" value={values[item.id] ?? ''} onChange={(event) => setValues((current) => ({ ...current, [item.id]: event.target.value }))} aria-label={`Nilai aktual ${item.code}`} placeholder={`Nilai (${item.unit})`} />
                                                                <Button type="button" size="icon" title="Simpan penilaian" aria-label={`Simpan penilaian ${item.code}`} onClick={() => assessItem(item)}><Save /></Button>
                                                            </div>
                                                        )}
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
