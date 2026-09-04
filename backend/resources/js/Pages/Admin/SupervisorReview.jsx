import { Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, ClipboardCheck, Send, RotateCcw } from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { useFeedback } from '@/components/feedback/ActionFeedback';
import AppLayout from '@/layouts/AppLayout';

const itemStatus = {
    verified: 'Terverifikasi',
    assessed: 'Dinilai',
    revision_required: 'Perlu Revisi',
    draft: 'Draft',
    not_started: 'Belum dimulai',
};

export default function SupervisorReview(props) {
    return (
        <AppLayout>
            <SupervisorReviewContent {...props} />
        </AppLayout>
    );
}

function SupervisorReviewContent({ kpi }) {
    const { requestAction } = useFeedback();
    const [rubricAnswers, setRubricAnswers] = useState({});

    const verify = async (item, decision) => {
        let reason = null;

        if (decision === 'revision_required') {
            const result = await requestAction({
                title: 'Minta revisi indikator?',
                description: `Tambahkan alasan untuk ${item.code} agar karyawan dapat memperbaikinya.`,
                confirmLabel: 'Minta revisi',
                prompt: {
                    name: 'reason',
                    label: 'Alasan revisi',
                    placeholder: 'Contoh: lampirkan bukti pendukung...',
                },
            });

            if (!result.confirmed) return;
            reason = result.value;
        }

        router.post(`/app/supervisor-reviews/${kpi.id}/items/${item.id}/decision`, { decision, reason }, { preserveScroll: true });
    };

    const submitRubric = (item) => {
        const criteria = item.rubric?.criteria ?? [];
        const selected = rubricAnswers[item.id] ?? [];
        const answers = criteria.map((criterion) => ({
            criterion_id: criterion.id,
            is_fulfilled: selected.includes(String(criterion.id)),
            notes: null,
        }));
        router.post(`/app/supervisor-reviews/${kpi.id}/items/${item.id}/rubric`, { answers }, { preserveScroll: true });
    };

    const sendRevision = async () => {
        const result = await requestAction({
            title: 'Minta revisi KPI?',
            description: 'Karyawan akan menerima permintaan revisi untuk KPI ini.',
            confirmLabel: 'Kirim permintaan',
            prompt: {
                name: 'reason',
                label: 'Alasan revisi',
                placeholder: 'Jelaskan bagian yang perlu diperbaiki...',
                required: true,
                minLength: 5,
            },
        });

        if (result.confirmed) {
            router.post(`/app/supervisor-reviews/${kpi.id}/revision`, { reason: result.value }, { preserveScroll: true });
        }
    };

    const forward = async () => {
        const result = await requestAction({
            title: 'Teruskan KPI ke manager?',
            description: 'KPI ini akan masuk ke antrean approval manager.',
            confirmLabel: 'Teruskan ke manager',
            prompt: {
                name: 'notes',
                label: 'Catatan untuk manager (opsional)',
                placeholder: 'Tambahkan catatan bila diperlukan...',
            },
        });

        if (result.confirmed) {
            router.post(`/app/supervisor-reviews/${kpi.id}/forward`, { notes: result.value }, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={`Review KPI ${kpi.employee.name}`} />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-[1440px] space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <Link href="/app/supervisor-reviews" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"><ArrowLeft className="size-3.5" />Kembali ke review tim</Link>
                            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Review KPI: {kpi.employee.name}</h1>
                            <p className="mt-1 text-sm text-muted-foreground">{kpi.employee.position} · {kpi.employee.branch} · {kpi.period}</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button type="button" variant="outline" onClick={sendRevision}><RotateCcw />Minta revisi</Button>
                            <Button type="button" onClick={forward}><Send />Teruskan ke manager</Button>
                        </div>
                    </div>

                    <div className="grid gap-4 sm:grid-cols-3">
                        <Card><CardContent className="p-5"><p className="text-xs text-muted-foreground">Status KPI</p><p className="mt-2 text-lg font-semibold"><Badge variant="outline">{kpi.status}</Badge></p></CardContent></Card>
                        <Card><CardContent className="p-5"><p className="text-xs text-muted-foreground">Progress</p><p className="mt-2 text-2xl font-semibold">{kpi.progress}%</p></CardContent></Card>
                        <Card><CardContent className="p-5"><p className="text-xs text-muted-foreground">Skor sementara</p><p className="mt-2 text-2xl font-semibold">{kpi.final_score ?? '—'}</p></CardContent></Card>
                    </div>

                    <Card className="overflow-hidden">
                        <CardHeader className="border-b border-border/70"><CardTitle>Indikator KPI</CardTitle></CardHeader>
                        <CardContent className="p-0">
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[900px] text-left text-sm">
                                    <thead className="border-b border-border/70 bg-muted/35 text-[11px] uppercase tracking-[0.12em] text-muted-foreground"><tr><th className="px-5 py-3">Indikator</th><th className="px-5 py-3">Target</th><th className="px-5 py-3">Aktual</th><th className="px-5 py-3">Pencapaian</th><th className="px-5 py-3">Status</th><th className="px-5 py-3 text-right">Aksi</th></tr></thead>
                                    <tbody className="divide-y divide-border/70">
                                        {kpi.items.map((item) => {
                                            const criteria = item.rubric?.criteria ?? [];
                                            const selected = rubricAnswers[item.id] ?? [];
                                            const locked = ['verified', 'assessed'].includes(item.status);

                                            return (
                                                <tr key={item.id} className="align-top">
                                                    <td className="px-5 py-4"><p className="font-semibold text-foreground">{item.code}</p><p className="mt-1 max-w-xs text-muted-foreground">{item.name}</p><p className="mt-1 text-xs text-muted-foreground">Bobot {item.weight}%</p></td>
                                                    <td className="px-5 py-4 text-muted-foreground">{item.target ?? '—'} {item.unit}</td>
                                                    <td className="px-5 py-4 font-medium text-foreground">{item.actual ?? '—'}</td>
                                                    <td className="px-5 py-4 text-muted-foreground">{item.achievement ?? '—'}%</td>
                                                    <td className="px-5 py-4"><Badge variant={locked ? 'default' : item.status === 'revision_required' ? 'destructive' : 'outline'}>{itemStatus[item.status] ?? item.status}</Badge></td>
                                                    <td className="px-5 py-4">
                                                        {!locked && item.formula === 'rubric' ? (
                                                            <div className="min-w-56 space-y-2">
                                                                <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><ClipboardCheck className="size-3.5" />Kriteria terpenuhi</p>
                                                                {criteria.map((criterion) => <label key={criterion.id} className="flex gap-2 text-xs text-foreground"><input type="checkbox" checked={selected.includes(String(criterion.id))} onChange={(event) => setRubricAnswers((current) => ({ ...current, [item.id]: event.target.checked ? [...selected, String(criterion.id)] : selected.filter((id) => id !== String(criterion.id)) }))} className="mt-0.5 size-3.5 accent-primary" />{criterion.criterion_text}</label>)}
                                                                <Button type="button" size="sm" onClick={() => submitRubric(item)}><CheckCircle2 />Simpan rubrik</Button>
                                                            </div>
                                                        ) : !locked ? (
                                                            <div className="flex justify-end gap-1.5"><Button type="button" size="icon-sm" title="Tandai valid" aria-label="Tandai valid" onClick={() => verify(item, 'valid')}><CheckCircle2 /></Button><Button type="button" variant="outline" size="icon-sm" title="Minta revisi" aria-label="Minta revisi" onClick={() => verify(item, 'revision_required')}><RotateCcw /></Button></div>
                                                        ) : <span className="text-xs text-muted-foreground">Sudah diproses</span>}
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
