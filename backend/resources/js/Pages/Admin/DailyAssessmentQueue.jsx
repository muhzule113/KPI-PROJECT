import { Head, Link, router } from '@inertiajs/react';
import { AlertTriangle, ArrowLeft, CheckCircle2, ClipboardCheck, RotateCcw, Save, UserRound } from 'lucide-react';
import { useEffect, useState } from 'react';

import { useFeedback } from '@/components/feedback/ActionFeedback';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';

const statusLabels = {
    pending: 'Menunggu',
    approved: 'Disetujui',
    revision_required: 'Perlu revisi',
};

const selectedIds = (answers) => (answers ?? []).filter((answer) => answer.is_fulfilled).map((answer) => String(answer.criterion_id));

const selectedRating = (entry, role) => {
    const actual = role === 'manager' ? (entry.manager_actual_json ?? entry.supervisor_actual_json) : entry.supervisor_actual_json;
    return actual?.rating_code ?? '';
};

const selectedAttendance = (entry, role) => {
    const actual = role === 'manager' ? (entry.manager_actual_json ?? entry.supervisor_actual_json) : entry.supervisor_actual_json;
    return actual?.attendance_status ?? entry.item?.system_meta?.attendance_status ?? '';
};

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

const managerChanged = (entry) => {
    if (entry.review_mode !== 'staff_confirmation' || entry.manager_status !== 'approved') return false;
    if (entry.item.input_type === 'rating') return selectedRating(entry, 'manager') !== selectedRating(entry, 'supervisor');
    if (entry.item.input_type === 'attendance') return selectedAttendance(entry, 'manager') !== selectedAttendance(entry, 'supervisor');
    if (entry.item.formula === 'rubric') return selectedIds(entry.manager_answers).sort().join(',') !== selectedIds(entry.supervisor_answers).sort().join(',');

    if (entry.manager_actual === null || entry.manager_actual === undefined) return false;
    const managerValue = Number(entry.manager_actual);
    const supervisorValue = Number(entry.supervisor_actual ?? entry.employee_actual ?? entry.system_actual ?? entry.item.system_actual);
    return Number.isFinite(managerValue) && Number.isFinite(supervisorValue) && Math.abs(managerValue - supervisorValue) > 0.000001;
};

export default function DailyAssessmentQueue({ role, title, description, date, kpi_id: kpiId = null, entries = [], message, deadline = null, canAssess = false }) {
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
    const [managerSection, setManagerSection] = useState('staff_confirmation');
    const [editing, setEditing] = useState(() => new Set());
    const [approvingKpi, setApprovingKpi] = useState(null);
    const orderedEntries = [...entries].sort((left, right) => {
        const priority = (entry) => entry.item.input_type === 'attendance' ? 0 : ['system', 'cross_role', 'import'].includes(String(entry.item.source_type).toLowerCase()) ? 2 : 1;
        return priority(left) - priority(right);
    });
    const visibleEntries = role === 'manager' && !kpiId ? orderedEntries.filter((entry) => entry.review_mode === managerSection) : orderedEntries;
    const groups = Array.from(visibleEntries.reduce((map, entry) => {
        const key = String(entry.employee?.id ?? entry.kpi_id ?? entry.employee?.name ?? entry.id);
        const group = map.get(key) ?? { employee: entry.employee, entries: [] };
        group.entries.push(entry);
        map.set(key, group);
        return map;
    }, new Map()).values());
    const pendingCount = visibleEntries.filter((entry) => (role === 'manager' ? entry.manager_status : entry.supervisor_status) !== 'approved').length;
    const [values, setValues] = useState(() => Object.fromEntries(entries.map((entry) => [entry.id, role === 'manager' ? (entry.manager_actual ?? entry.supervisor_actual ?? entry.employee_actual ?? '') : (entry.supervisor_actual ?? '')])));
    const [ratings, setRatings] = useState(() => Object.fromEntries(entries.filter((entry) => entry.item.input_type === 'rating').map((entry) => [entry.id, selectedRating(entry, role)])));
    const [attendanceStatuses, setAttendanceStatuses] = useState(() => Object.fromEntries(entries.filter((entry) => entry.item.input_type === 'attendance').map((entry) => [entry.id, selectedAttendance(entry, role)])));
    const [rubricAnswers, setRubricAnswers] = useState(() => Object.fromEntries(entries.filter((entry) => entry.item.formula === 'rubric').map((entry) => [entry.id, selectedIds(role === 'manager' ? (entry.manager_answers ?? entry.supervisor_answers) : entry.supervisor_answers)])));

    const changeDate = (value) => {
        router.get(role === 'manager' ? '/app/manager-daily-assessments' : '/app/supervisor-daily-assessments', { date: value }, { preserveState: false, replace: true });
    };

    const approveAll = async (group) => {
        if (assessmentDisabled || approvingKpi) return;
        const kpiId = group.entries[0]?.kpi_id;
        const result = await requestAction({
            title: `Setujui semua indikator ${group.employee?.name ?? 'staf'}?`,
            description: 'Semua hasil Supervisor yang siap akan dikonfirmasi tanpa mengubah sumber fakta.',
            confirmLabel: 'Setujui semua',
        });
        if (!result.confirmed) return;
        router.post(`/app/manager-daily-assessments/${kpiId}/approve-all`, { date }, {
            preserveScroll: true,
            onStart: () => setApprovingKpi(kpiId),
            onFinish: () => setApprovingKpi(null),
        });
    };

    const automaticEntries = (group) => group.entries.filter((entry) => entry.item.input_type !== 'attendance'
        && ['system', 'cross_role', 'import'].includes(String(entry.item.source_type).toLowerCase()));
    const automaticReady = (group) => {
        const automatic = automaticEntries(group);
        return automatic.length > 0 && automatic.every((entry) => (entry.system_actual ?? entry.item.system_actual) !== null
            && (entry.system_actual ?? entry.item.system_actual) !== undefined);
    };
    const approveAutomatic = (group) => {
        const groupKpiId = group.entries[0]?.kpi_id;
        router.post(`/app/supervisor-daily-assessments/${groupKpiId}/approve-all`, { date }, {
            preserveScroll: true,
            onStart: () => setApprovingKpi(groupKpiId),
            onFinish: () => setApprovingKpi(null),
        });
    };

    const toggleEditor = (entryId) => setEditing((current) => {
        const next = new Set(current);
        next.has(entryId) ? next.delete(entryId) : next.add(entryId);
        return next;
    });

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

        const payload = { decision, note, ...(kpiId ? { kpi_id: kpiId } : {}) };
        if (entry.item.input_type === 'rating') {
            const ratingCode = ratings[entry.id] ?? '';
            if (!ratingCode) return;
            payload.actual_json = { rating_code: ratingCode };
            const rating = (entry.item.manual_rating_options ?? []).find((option) => option.code === ratingCode);
            if (role === 'supervisor' && Number(rating?.score) < Number(entry.item.target)) {
                const result = await requestAction({
                    title: 'Catatan penilaian diperlukan',
                    description: 'Predikat berada di bawah target indikator.',
                    confirmLabel: 'Simpan penilaian',
                    prompt: { name: 'note', label: 'Catatan penilaian', required: true, minLength: 3, placeholder: 'Jelaskan bagian yang perlu diperbaiki...' },
                });
                if (!result.confirmed) return;
                payload.note = result.value;
            } else if (role === 'manager' && ratingCode !== selectedRating(entry, 'supervisor')) {
                const result = await requestAction({
                    title: 'Revisi predikat Manager?',
                    description: 'Perubahan predikat Manager wajib memiliki alasan.',
                    confirmLabel: 'Simpan revisi',
                    prompt: { name: 'reason', label: 'Alasan revisi', required: true, minLength: 3, placeholder: 'Jelaskan dasar perubahan predikat...' },
                });
                if (!result.confirmed) return;
                payload.note = result.value;
            }
        } else if (entry.item.input_type === 'attendance') {
            const attendanceStatus = attendanceStatuses[entry.id] ?? '';
            if (!attendanceStatus) return;
            payload.actual_json = { attendance_status: attendanceStatus };
            const previous = selectedAttendance(entry, 'supervisor');
            if (role !== 'manager' && ['permission', 'sick_leave', 'absent'].includes(attendanceStatus)) {
                const result = await requestAction({
                    title: 'Simpan status kehadiran?',
                    description: 'Status ini membutuhkan catatan agar dapat diaudit.',
                    confirmLabel: 'Simpan status',
                    prompt: { name: 'reason', label: 'Catatan kehadiran', required: true, minLength: 3, placeholder: 'Contoh: izin keluarga / surat sakit...' },
                });
                if (!result.confirmed) return;
                payload.note = result.value;
            } else if (role === 'manager' && attendanceStatus !== previous) {
                const result = await requestAction({
                    title: 'Revisi status kehadiran Manager?',
                    description: 'Perubahan status Manager wajib memiliki alasan.',
                    confirmLabel: 'Simpan revisi',
                    prompt: { name: 'reason', label: 'Alasan revisi', required: true, minLength: 3, placeholder: 'Jelaskan dasar perubahan status...' },
                });
                if (!result.confirmed) return;
                payload.note = result.value;
            }
        } else if (entry.item.formula === 'rubric') {
            const selected = rubricAnswers[entry.id] ?? [];
            payload.answers = (entry.item.rubric?.criteria ?? []).map((criterion) => ({
                criterion_id: criterion.id,
                is_fulfilled: selected.includes(String(criterion.id)),
                notes: null,
            }));
            if (role === 'manager') {
                const previous = selectedIds(entry.supervisor_answers).sort().join(',');
                const next = [...selected].sort().join(',');
                if (previous !== next) {
                    const result = await requestAction({
                        title: 'Revisi rubrik Manager?',
                        description: 'Perubahan hasil checklist Manager wajib memiliki alasan.',
                        confirmLabel: 'Simpan revisi',
                        prompt: { name: 'reason', label: 'Alasan revisi', required: true, minLength: 3, placeholder: 'Jelaskan dasar perubahan checklist...' },
                    });
                    if (!result.confirmed) return;
                    payload.note = result.value;
                }
            }
        } else if (values[entry.id] !== '' && values[entry.id] !== undefined && values[entry.id] !== null) {
            payload.actual_decimal = values[entry.id];
            if (role === 'manager') {
                const baseline = Number(entry.supervisor_actual ?? entry.employee_actual ?? entry.system_actual ?? entry.item.system_actual);
                if (Number.isFinite(baseline) && Math.abs(Number(values[entry.id]) - baseline) > 0.000001) {
                    const result = await requestAction({
                        title: 'Koreksi nilai aktual?',
                        description: 'Koreksi hanya boleh untuk fakta karyawan dan wajib memiliki alasan serta evidence.',
                        confirmLabel: 'Simpan koreksi',
                        prompt: { name: 'evidence', label: 'Alasan dan referensi evidence', required: true, minLength: 3, placeholder: 'Contoh: alasan; bukti/nomor dokumen...' },
                    });
                    if (!result.confirmed) return;
                    payload.note = result.value;
                    payload.actual_json = { evidence: result.value };
                }
            }
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
                            <Link href={kpiId ? '/app/team-tasks' : '/app'} className="mb-3 inline-flex min-h-11 items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"><ArrowLeft className="size-3.5" />{kpiId ? 'Penilaian Tim' : 'Dashboard'}</Link>
                            <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{title}</h1>
                            <p className="mt-1 max-w-3xl text-sm text-muted-foreground">{description}</p>
                        </div>
                        {!kpiId && <div>
                            <label htmlFor="queue-date" className="sr-only">Tanggal penilaian</label>
                            <DatePicker id="queue-date" value={date} onChange={changeDate} aria-label="Tanggal penilaian" />
                        </div>}
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

                    {role === 'manager' && !kpiId && (
                        <div className="grid grid-cols-2 gap-2 rounded-lg border border-border/70 bg-card p-1" aria-label="Bagian penilaian harian">
                            <Button type="button" variant={managerSection === 'staff_confirmation' ? 'default' : 'ghost'} className="min-h-11" aria-pressed={managerSection === 'staff_confirmation'} onClick={() => setManagerSection('staff_confirmation')}>Staf</Button>
                            <Button type="button" variant={managerSection === 'supervisor_assessment' ? 'default' : 'ghost'} className="min-h-11" aria-pressed={managerSection === 'supervisor_assessment'} onClick={() => setManagerSection('supervisor_assessment')}>Supervisor</Button>
                        </div>
                    )}

                    <Card className="overflow-hidden">
                        <CardHeader className="border-b border-border/70">
                            <CardTitle>{kpiId ? (groups[0]?.employee?.name ?? 'Penilaian karyawan') : `Antrean ${role === 'manager' ? 'penilaian Manager' : 'review Supervisor'} - ${date}`}</CardTitle>
                            <CardDescription>{deadlineExpired ? 'Deadline sudah lewat. Seluruh aksi penilaian dinonaktifkan.' : assessmentDisabled ? 'Penilaian tidak tersedia untuk tanggal atau periode ini.' : pendingCount ? `${pendingCount} indikator menunggu diproses pada ${groups.length} karyawan.` : visibleEntries.length ? `Semua indikator dari ${groups.length} karyawan sudah diputuskan. Anda tetap dapat menyimpan koreksi.` : 'Belum ada data harian yang siap diproses.'}</CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4 p-4 sm:p-5">
                            {visibleEntries.length ? groups.map((group) => (
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
                                            <div className="flex flex-wrap items-center gap-2">
                                                {!kpiId && <Badge variant="outline">{group.entries.length} indikator</Badge>}
                                                {role === 'supervisor' && automaticReady(group) && (
                                                    <Button type="button" size="sm" className="min-h-11" disabled={assessmentDisabled || Boolean(approvingKpi)} onClick={() => approveAutomatic(group)}>
                                                        <CheckCircle2 />{approvingKpi === group.entries[0]?.kpi_id ? 'Mengonfirmasi...' : 'Konfirmasi otomatis'}
                                                    </Button>
                                                )}
                                                {role === 'manager' && managerSection === 'staff_confirmation' && group.entries.some((entry) => entry.manager_status !== 'approved') && (
                                                    <Button type="button" size="sm" className="min-h-11" disabled={assessmentDisabled || Boolean(approvingKpi)} onClick={() => approveAll(group)}>
                                                        <CheckCircle2 />{approvingKpi === group.entries[0]?.kpi_id ? 'Menyetujui...' : 'Setujui semua'}
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    </CardHeader>
                                    <CardContent className="divide-y divide-border/70 p-0">
                                        {group.entries.map((entry) => {
                                            const criteria = entry.item.rubric?.criteria ?? [];
                                            const selected = rubricAnswers[entry.id] ?? [];
                                            const isSystem = ['system', 'cross_role', 'import'].includes(String(entry.item.source_type).toLowerCase());
                                            const systemMeta = entry.item.system_meta ?? {};
                                            const currentStatus = role === 'manager' ? entry.manager_status : entry.supervisor_status;
                                            const inputType = entry.item.input_type ?? (entry.item.formula === 'rubric' ? 'rubric' : 'numeric');
                                            const staffConfirmation = role === 'manager' && entry.review_mode === 'staff_confirmation';
                                            const reviewedValue = entry.item.formula === 'rubric' || inputType === 'rating'
                                                ? (staffConfirmation ? entry.supervisor_score : (role === 'manager' ? (entry.manager_score ?? entry.supervisor_score) : entry.supervisor_score))
                                                : (staffConfirmation ? (entry.supervisor_actual ?? entry.employee_actual) : (role === 'manager' ? (entry.manager_actual ?? entry.supervisor_actual ?? entry.employee_actual) : entry.supervisor_actual));
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
                                                        <div className="mb-2 flex flex-wrap items-center gap-2"><span className="text-[11px] font-medium uppercase tracking-[0.12em] text-muted-foreground">Keputusan</span><Badge variant={currentStatus === 'revision_required' ? 'destructive' : currentStatus === 'approved' ? 'default' : 'outline'}>{staffConfirmation ? (currentStatus === 'approved' ? (managerChanged(entry) ? 'Diubah Manager' : 'Disetujui') : 'Menunggu tinjauan') : (statusLabels[currentStatus] ?? currentStatus)}</Badge></div>
                                                        {staffConfirmation && !editing.has(entry.id) ? (
                                                            <div className="flex flex-wrap gap-2">
                                                                {currentStatus !== 'approved' && <Button type="button" size="sm" className="min-h-11" disabled={assessmentDisabled} onClick={() => assess(entry, 'approved')}><CheckCircle2 />Setujui</Button>}
                                                                <Button type="button" size="sm" variant="outline" className="min-h-11" disabled={assessmentDisabled} onClick={() => toggleEditor(entry.id)}>Ubah</Button>
                                                            </div>
                                                        ) : inputType === 'rating' ? (
                                                            <div className="space-y-2">
                                                                <p className="text-xs text-muted-foreground">Gunakan kriteria berikut sebagai panduan, lalu pilih satu predikat untuk keseluruhan KPI.</p>
                                                                {criteria.length > 0 && <ul className="list-disc space-y-1 pl-4 text-xs text-muted-foreground">{criteria.map((criterion) => <li key={criterion.id}>{criterion.criterion_text}</li>)}</ul>}
                                                                <div className="flex flex-wrap gap-2">
                                                                    {(entry.item.manual_rating_options ?? []).map((option) => <Button key={option.code} type="button" size="sm" variant={ratings[entry.id] === option.code ? 'default' : 'outline'} disabled={assessmentDisabled} onClick={() => setRatings((current) => ({ ...current, [entry.id]: option.code }))}>{option.label} ({option.score}%)</Button>)}
                                                                </div>
                                                                <div className="flex flex-wrap gap-2 pt-1"><Button type="button" size="sm" disabled={assessmentDisabled || !ratings[entry.id]} onClick={() => assess(entry, 'approved')}><CheckCircle2 />{currentStatus === 'approved' ? 'Simpan koreksi' : 'Simpan penilaian'}</Button>{role !== 'manager' && <Button type="button" size="sm" variant="outline" disabled={assessmentDisabled} onClick={() => assess(entry, 'revision_required')}><RotateCcw />Revisi</Button>}</div>
                                                            </div>
                                                        ) : inputType === 'attendance' ? (
                                                            <div className="space-y-2">
                                                                <p className="text-xs text-muted-foreground">Status ini menjadi sumber resmi rekap absensi dan KPI kehadiran.</p>
                                                                <select value={attendanceStatuses[entry.id] ?? ''} disabled={assessmentDisabled} onChange={(event) => setAttendanceStatuses((current) => ({ ...current, [entry.id]: event.target.value }))} className="h-9 w-full rounded-md border border-input bg-background px-3 text-sm" aria-label={`Status kehadiran ${group.employee?.name ?? ''}`}>
                                                                    <option value="">Pilih status kehadiran</option>
                                                                    {(entry.item.attendance_options ?? []).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                                                                </select>
                                                                <div className="flex flex-wrap gap-2 pt-1"><Button type="button" size="sm" disabled={assessmentDisabled || !attendanceStatuses[entry.id]} onClick={() => assess(entry, 'approved')}><CheckCircle2 />{currentStatus === 'approved' ? 'Simpan koreksi' : 'Simpan status'}</Button>{role !== 'manager' && <Button type="button" size="sm" variant="outline" disabled={assessmentDisabled} onClick={() => assess(entry, 'revision_required')}><RotateCcw />Revisi</Button>}</div>
                                                            </div>
                                                        ) : inputType === 'rubric' ? (
                                                            <div className="space-y-2">
                                                                <p className="flex items-center gap-1.5 text-xs font-medium text-muted-foreground"><ClipboardCheck className="size-3.5" />Checklist penilaian</p>
                                                                {criteria.map((criterion) => <label key={criterion.id} className="flex gap-2 text-xs"><input type="checkbox" disabled={assessmentDisabled} checked={selected.includes(String(criterion.id))} onChange={(event) => setRubricAnswers((current) => { const next = new Set(current[entry.id] ?? []); event.target.checked ? next.add(String(criterion.id)) : next.delete(String(criterion.id)); return { ...current, [entry.id]: [...next] }; })} className="mt-0.5 size-3.5 accent-primary" />{criterion.criterion_text}</label>)}
                                                                <div className="flex flex-wrap gap-2 pt-1"><Button type="button" size="sm" disabled={assessmentDisabled} onClick={() => assess(entry, 'approved')}><CheckCircle2 />{currentStatus === 'approved' ? 'Simpan koreksi' : 'Simpan penilaian'}</Button>{role !== 'manager' && <Button type="button" size="sm" variant="outline" disabled={assessmentDisabled} onClick={() => assess(entry, 'revision_required')}><RotateCcw />Revisi</Button>}</div>
                                                            </div>
                                                        ) : isSystem ? (
                                                            <div className="space-y-2">
                                                                <p className="text-xs text-muted-foreground">{systemReady ? 'Periksa sumber operasional, lalu konfirmasi angka sistem.' : 'Belum dapat dikonfirmasi karena angka sistem belum tersedia.'}</p>
                                                                <div className="flex flex-wrap gap-2"><Button type="button" size="sm" disabled={assessmentDisabled || !systemReady} onClick={() => assess(entry, 'approved')}><CheckCircle2 />{currentStatus === 'approved' ? 'Simpan konfirmasi' : 'Konfirmasi sistem'}</Button>{role !== 'manager' && <Button type="button" size="sm" variant="outline" disabled={assessmentDisabled} onClick={() => assess(entry, 'revision_required')}><RotateCcw />Revisi</Button>}</div>
                                                            </div>
                                                        ) : (
                                                            <div className="flex flex-wrap gap-2">
                                                                <Input type="number" step="any" disabled={assessmentDisabled} value={values[entry.id] ?? ''} onChange={(event) => setValues((current) => ({ ...current, [entry.id]: event.target.value }))} aria-label={`Nilai ${entry.item.code} ${group.employee?.name ?? ''}`} placeholder={`Nilai (${entry.item.unit ?? 'angka'})`} className="max-w-48" />
                                                                <Button type="button" size="sm" disabled={assessmentDisabled} onClick={() => assess(entry, 'approved')}><Save />{currentStatus === 'approved' ? 'Simpan koreksi' : 'Setujui nilai'}</Button>
                                                                {role !== 'manager' && <Button type="button" size="sm" variant="outline" disabled={assessmentDisabled} onClick={() => assess(entry, 'revision_required')}><RotateCcw />Revisi</Button>}
                                                            </div>
                                                        )}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </CardContent>
                                </Card>
                            )) : <div className="p-10 text-center"><p className="font-medium">{kpiId ? 'Penilaian karyawan ini selesai' : 'Belum ada data'}</p><p className="mt-1 text-sm text-muted-foreground">{kpiId ? 'Tidak ada lagi tindakan wajib yang menunggu.' : role === 'manager' && managerSection === 'staff_confirmation' ? 'Supervisor belum menyelesaikan penilaian, penugasan Manager belum tersedia, atau tidak ada data pada tanggal ini.' : role === 'manager' ? 'Tidak ada KPI Supervisor yang ditugaskan pada tanggal ini.' : 'Data akan muncul setelah sistem menyiapkan entri KPI untuk tanggal ini.'}</p>{kpiId && <Button asChild className="mt-4 min-h-11"><Link href="/app/team-tasks">Kembali ke Penilaian Tim</Link></Button>}</div>}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
