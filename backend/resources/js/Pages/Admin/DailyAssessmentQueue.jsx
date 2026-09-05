import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CalendarDays, CheckCircle2, ClipboardCheck, RotateCcw, Save, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';

import { useFeedback } from '@/components/feedback/ActionFeedback';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

const statusLabels = {
    pending: 'Menunggu',
    approved: 'Disetujui',
    revision_required: 'Perlu revisi',
};

const selectedIds = (answers) => (answers ?? []).filter((answer) => answer.is_fulfilled).map((answer) => String(answer.criterion_id));

const formatValue = (value, unit, formula) => {
    if (value === null || value === undefined || value === '') return 'Belum tersedia';

    const number = Number(value);
    const formatted = Number.isFinite(number) ? number.toLocaleString('id-ID', { maximumFractionDigits: 2 }) : String(value);
    const suffix = formula === 'rubric' || unit === '%' ? '%' : unit ? ` ${unit}` : '';

    return `${formatted}${suffix}`;
};

const formatDeadline = (value) => {
    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) return value;

    return parsed.toLocaleString('id-ID', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
};

export default function DailyAssessmentQueue({ role, title, description, date, entries = [], message, deadline = null, canAssess = false }) {
    const { requestAction } = useFeedback();
    const [currentTime, setCurrentTime] = useState(() => Date.now());
    useEffect(() => {
        if (!deadline || !canAssess) return undefined;

        const timeout = window.setTimeout(
            () => setCurrentTime(Date.now()),
            Math.max(new Date(deadline).getTime() - Date.now(), 0) + 1,
        );

        return () => window.clearTimeout(timeout);
    }, [deadline, canAssess]);

    const deadlineExpired = Boolean(deadline && new Date(deadline).getTime() <= currentTime);
    const assessmentDisabled = !canAssess || deadlineExpired;
    const roleLabel = role === 'manager' ? 'Manager' : 'Supervisor';
    const groups = Array.from(entries.reduce((map, entry) => {
        const key = String(entry.employee?.id ?? entry.kpi_id ?? entry.employee?.name ?? entry.id);
        const group = map.get(key) ?? { employee: entry.employee, entries: [] };
        group.entries.push(entry);
        map.set(key, group);
        return map;
    }, new Map()).values());
    const pendingCount = entries.filter((entry) => (role === 'manager' ? entry.manager_status : entry.supervisor_status) !== 'approved').length;
    const [values, setValues] = useState(() => Object.fromEntries(entries.map((entry) => [entry.id, role === 'manager' ? (entry.manager_actual ?? entry.supervisor_actual ?? '') : (entry.supervisor_actual ?? '')])));
    const [rubricAnswers, setRubricAnswers] = useState(() => Object.fromEntries(entries.filter((entry) => entry.item.formula === 'rubric').map((entry) => [entry.id, selectedIds(role === 'manager' ? entry.manager_answers : entry.supervisor_answers)])));

    const changeDate = (event) => {
        router.get(role === 'manager' ? '/app/manager-daily-assessments' : '/app/supervisor-daily-assessments', { date: event.target.value }, { preserveState: false, replace: true });
    };

    const assess = async (entry, decision) => {
        if (assessmentDisabled) return;

        let note = null;
        if (decision === 'revision_required') {
            const result = await requestAction({
                title: 'Minta revisi entri harian?',
                description: `Entri ${entry.item.code} akan ditandai untuk diperiksa kembali.`,
                confirmLabel: 'Minta revisi',
                variant: 'destructive',
                prompt: { name: 'reason', label: 'Alasan revisi', required: true, minLength: 3, placeholder: 'Jelaskan data yang perlu diperiksa...' },
            });
            if (!result.confirmed) return;
            note = result.value;
        }

        const payload = { decision, note };
        if (entry.item.formula === 'rubric') {
            const selected = rubricAnswers[entry.id] ?? [];
            payload.answers = (entry.item.rubric?.criteria ?? []).map((criterion) => ({
                criterion_id: criterion.id,
                is_fulfilled: selected.includes(String(criterion.id)),
                notes: null,
            }));
        } else if (values[entry.id] !== '' && values[entry.id] !== undefined && values[entry.id] !== null) {
            payload.actual_decimal = values[entry.id];
        }

        const base = role === 'manager' ? '/app/manager-daily-assessments' : '/app/supervisor-daily-assessments';
        router.post(`${base}/${entry.id}/assess`, payload, { preserveScroll: true });
    };

    return (
        <>
            <Head title={title} />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-[1500px] space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <Link href="/app" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"><ArrowLeft className="size-3.5" />Dashboard</Link>
                            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{title}</h1>
                            <p className="mt-1 max-w-3xl text-sm text-muted-foreground">{description}</p>
                        </div>
                        <div className="flex items-center gap-2 rounded-xl border border-border/70 bg-card px-3 py-2">
                            <CalendarDays className="size-4 text-primary" />
                            <label htmlFor="queue-date" className="sr-only">Tanggal penilaian</label>
                            <Input id="queue-date" type="date" value={date} onChange={changeDate} className="w-auto" />
                        </div>
                    </div>

                    {message && <Card role="alert"><CardContent className="p-6"><p className="text-sm text-muted-foreground">{message}</p></CardContent></Card>}

                    {deadline && (
                        <div role="alert" className={`flex items-start gap-3 rounded-xl border px-4 py-3 text-sm ${deadlineExpired ? 'border-destructive/30 bg-destructive/10 text-destructive' : 'border-primary/20 bg-primary/5 text-foreground'}`}>
                            <AlertTriangle className="mt-0.5 size-4 shrink-0" />
                            <div>
                                <p className="font-medium">{deadlineExpired ? 'Deadline penilaian sudah lewat.' : `Deadline penilaian ${roleLabel}: ${formatDeadline(deadline)}`}</p>
                                <p className="mt-1 text-xs opacity-80">{deadlineExpired ? 'Tombol penilaian dan revisi dinonaktifkan. Hubungi administrator jika perlu membuka koreksi resmi.' : 'Setelah deadline ini lewat, penilaian tidak dapat disimpan.'}</p>
                            </div>
                        </div>
                    )}

                    <Card className="overflow-hidden">
                        <CardHeader className="border-b border-border/70">
                            <CardTitle>Antrean {role === 'manager' ? 'penilaian Manager' : 'review Supervisor'} - {date}</CardTitle>
                            <CardDescription>{deadlineExpired ? 'Deadline sudah lewat. Seluruh aksi penilaian dinonaktifkan.' : assessmentDisabled ? 'Penilaian tidak tersedia untuk tanggal atau periode ini.' : pendingCount ? `${pendingCount} indikator menunggu diproses pada ${groups.length} karyawan.` : entries.length ? `Semua indikator dari ${groups.length} karyawan sudah diputuskan. Anda tetap dapat menyimpan koreksi.` : 'Belum ada data harian yang siap diproses.'}</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4 p-4 sm:p-5">
                            {entries.length ? groups.map((group) => (
                                <Card key={String(group.employee?.id ?? group.entries[0].id)} className="overflow-hidden border-border/80 shadow-none">
                                    <CardHeader className="border-b border-border/70 bg-muted/20 px-4 py-4 sm:px-5">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div className="flex items-center gap-3">
                                                <div className="flex size-10 items-center justify-center rounded-full bg-primary/10 text-primary"><UserRound className="size-5" /></div>
                                                <div>
                                                    <CardTitle className="text-base">{group.employee?.name ?? 'Karyawan tanpa nama'}</CardTitle>
                                                    <CardDescription>{group.employee?.position ?? 'Jabatan belum tersedia'} - {group.employee?.branch ?? 'Cabang belum tersedia'}</CardDescription>
                                                </div>
                                            </div>
                                            <Badge variant="outline">{group.entries.length} indikator</Badge>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="divide-y divide-border/70 p-0">
                                        {group.entries.map((entry) => {
                                            const criteria = entry.item.rubric?.criteria ?? [];
                                            const selected = rubricAnswers[entry.id] ?? [];
                                            const isSystem = ['system', 'cross_role', 'import'].includes(String(entry.item.source_type).toLowerCase());
                                            const systemMeta = entry.item.system_meta ?? {};
                                            const currentStatus = role === 'manager' ? entry.manager_status : entry.supervisor_status;
                                            const reviewedValue = entry.item.formula === 'rubric'
                                                ? (role === 'manager' ? (entry.manager_score ?? entry.supervisor_score) : entry.supervisor_score)
                                                : (role === 'manager' ? (entry.manager_actual ?? entry.supervisor_actual) : entry.supervisor_actual);
                                            const systemValue = entry.system_actual ?? entry.item.system_actual;
                                            const displayValue = isSystem ? (systemValue ?? reviewedValue) : reviewedValue;
                                            const hasSystemValue = systemValue !== null && systemValue !== undefined;
                                            const hasManualValue = values[entry.id] !== '' && values[entry.id] !== undefined && values[entry.id] !== null;
                                            const systemReady = !isSystem || hasSystemValue || hasManualValue;
                                            const systemDetail = systemMeta.completed_tickets !== undefined
                                                ? `${systemMeta.completed_tickets} tiket selesai pada periode ini.`
                                                : 'Dihitung otomatis dari data operasional.';

                                            return (
                                                <div key={entry.id} className="grid gap-4 p-4 sm:p-5 lg:grid-cols-[minmax(220px,1.1fr)_minmax(150px,.65fr)_minmax(230px,1fr)_minmax(300px,1.45fr)]">
                                                    <div>
                                                        <div className="flex flex-wrap items-center gap-2"><p className="font-semibold">{entry.item.code}</p><Badge variant={isSystem ? 'secondary' : 'outline'}>{isSystem ? 'Otomatis sistem' : 'Review manual'}</Badge></div>
                                                        <p className="mt-1 text-sm text-muted-foreground">{entry.item.name}</p>
                                                    </div>
                                                    <div>
                                                        <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-muted-foreground">Target</p>
                                                        <p className="mt-1 font-medium">{entry.item.target ?? 'Belum ditentukan'}{entry.item.target !== null && entry.item.target !== undefined && entry.item.unit ? ` ${entry.item.unit}` : ''}</p>
                                                    </div>
                                                    <div className="rounded-lg border border-border/70 bg-muted/20 px-3 py-2.5">
                                                        <p className="text-[11px] font-medium uppercase tracking-[0.12em] text-muted-foreground">{isSystem ? 'Nilai sistem / rekap periode' : 'Nilai dari review sebelumnya'}</p>
                                                        <p className="mt-1 font-semibold">{formatValue(displayValue, entry.item.unit, entry.item.formula)}</p>
                                                        <p className="mt-1 text-xs text-muted-foreground">{isSystem ? (hasSystemValue ? systemDetail : 'Menunggu data operasional tersinkron.') : 'Nilai ini dapat dikoreksi sesuai kewenangan Anda.'}</p>
                                                    </div>
                                                    <div>
                                                        <div className="mb-2 flex flex-wrap items-center gap-2"><span className="text-[11px] font-medium uppercase tracking-[0.12em] text-muted-foreground">Keputusan</span><Badge variant={currentStatus === 'revision_required' ? 'destructive' : currentStatus === 'approved' ? 'default' : 'outline'}>{statusLabels[currentStatus] ?? currentStatus}</Badge></div>
                                                        {entry.item.formula === 'rubric' ? (
                                                            <div className="space-y-2">
                                                                <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><ClipboardCheck className="size-3.5" />Checklist penilaian</p>
                                                                {criteria.map((criterion) => <label key={criterion.id} className="flex gap-2 text-xs"><input type="checkbox" disabled={assessmentDisabled} checked={selected.includes(String(criterion.id))} onChange={(event) => setRubricAnswers((current) => { const next = new Set(current[entry.id] ?? []); event.target.checked ? next.add(String(criterion.id)) : next.delete(String(criterion.id)); return { ...current, [entry.id]: [...next] }; })} className="mt-0.5 size-3.5 accent-primary" />{criterion.criterion_text}</label>)}
                                                                <div className="flex flex-wrap gap-2 pt-1"><Button type="button" size="sm" disabled={assessmentDisabled} onClick={() => assess(entry, 'approved')}><CheckCircle2 />{currentStatus === 'approved' ? 'Simpan koreksi' : 'Simpan penilaian'}</Button><Button type="button" size="sm" variant="outline" disabled={assessmentDisabled} onClick={() => assess(entry, 'revision_required')}><RotateCcw />Revisi</Button></div>
                                                            </div>
                                                        ) : isSystem ? (
                                                            <div className="space-y-2">
                                                                <p className="text-xs text-muted-foreground">{systemReady ? 'Periksa sumber operasional, lalu konfirmasi angka sistem.' : 'Belum dapat dikonfirmasi karena angka sistem belum tersedia.'}</p>
                                                                <div className="flex flex-wrap gap-2"><Button type="button" size="sm" disabled={assessmentDisabled || !systemReady} onClick={() => assess(entry, 'approved')}><CheckCircle2 />{currentStatus === 'approved' ? 'Simpan konfirmasi' : 'Konfirmasi sistem'}</Button><Button type="button" size="sm" variant="outline" disabled={assessmentDisabled} onClick={() => assess(entry, 'revision_required')}><RotateCcw />Revisi</Button></div>
                                                            </div>
                                                        ) : (
                                                            <div className="flex flex-wrap gap-2">
                                                                <Input type="number" step="any" disabled={assessmentDisabled} value={values[entry.id] ?? ''} onChange={(event) => setValues((current) => ({ ...current, [entry.id]: event.target.value }))} aria-label={`Nilai ${entry.item.code} ${group.employee?.name ?? ''}`} placeholder={`Nilai (${entry.item.unit ?? 'angka'})`} className="max-w-48" />
                                                                <Button type="button" size="sm" disabled={assessmentDisabled} onClick={() => assess(entry, 'approved')}><Save />{currentStatus === 'approved' ? 'Simpan koreksi' : 'Setujui nilai'}</Button>
                                                                <Button type="button" size="sm" variant="outline" disabled={assessmentDisabled} onClick={() => assess(entry, 'revision_required')}><RotateCcw />Revisi</Button>
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </CardContent>
                                </Card>
                            )) : <div className="p-10 text-center"><p className="font-medium">Belum ada data</p><p className="mt-1 text-sm text-muted-foreground">Data akan muncul setelah sistem menyiapkan entri KPI dan tahap review sebelumnya selesai.</p></div>}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
