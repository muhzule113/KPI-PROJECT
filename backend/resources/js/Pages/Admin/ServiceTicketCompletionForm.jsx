import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Loader2, Plus, Trash2 } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

function FieldError({ message }) {
    return message ? <p className="mt-1.5 text-xs font-medium text-destructive">{message}</p> : null;
}

export default function ServiceTicketCompletionForm({
    ticket,
    checklist_options: checklistOptions = [],
    result_options: resultOptions = [],
}) {
    const existingChecklist = ticket.qc_checklist ?? {};
    const existingEvidence = (ticket.technical_evidence ?? []).map((evidence) => ({
        type: evidence.type ?? 'service_note',
        reference: evidence.reference ?? '',
    }));
    const initialResultStatus = resultOptions.some((option) => option.value === ticket.result_status)
        ? ticket.result_status
        : resultOptions[0]?.value ?? 'success';
    const form = useForm({
        result_status: initialResultStatus,
        diagnosis_notes: ticket.diagnosis_notes ?? '',
        action_notes: ticket.action_notes ?? '',
        qc_checklist: Object.fromEntries(checklistOptions.map((option) => [option.value, existingChecklist[option.value] === true])),
        technical_evidence: existingEvidence.length > 0 ? existingEvidence : [{ type: 'service_note', reference: '' }],
        unrepairable_reason: '',
        customer_declined_reason: '',
        customer_consent_confirmed: ticket.customer_consent_status === 'approved',
        customer_consent_notes: ticket.customer_consent_notes ?? '',
        row_version: ticket.row_version,
    });

    const updateChecklist = (key, value) => {
        form.setData('qc_checklist', { ...form.data.qc_checklist, [key]: value });
    };

    const updateEvidence = (index, field, value) => {
        form.setData('technical_evidence', form.data.technical_evidence.map((evidence, evidenceIndex) => (
            evidenceIndex === index ? { ...evidence, [field]: value } : evidence
        )));
    };

    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            technical_evidence: data.technical_evidence.filter((evidence) => evidence.type.trim() && (evidence.reference.trim() || evidence.file)),
        }));
        form.post(`/app/service-tickets/${ticket.id}/complete`, { preserveScroll: true, forceFormData: true });
    };

    const consentApproved = ticket.customer_consent_status === 'approved';
    const requiresReason = ['unrepairable', 'customer_declined'].includes(form.data.result_status);
    const evidenceError = Object.entries(form.errors).find(([key]) => key.startsWith('technical_evidence.'))?.[1];

    return (
        <>
            <Head title={`Selesaikan Tiket ${ticket.ticket_number}`} />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-4xl space-y-6">
                    <div>
                        <Link href="/app/service-tickets" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                            <ArrowLeft className="size-3.5" />
                            Kembali ke tiket servis
                        </Link>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">Selesaikan Tiket Servis</h1>
                            <Badge variant="outline">{ticket.status_label}</Badge>
                        </div>
                        <p className="mt-1.5 text-sm text-muted-foreground">Lengkapi hasil servis dan pemeriksaan QC sebelum tiket masuk status Selesai.</p>
                    </div>

                    <Card className="shadow-sm shadow-slate-950/5">
                        <CardHeader className="border-b border-border/70">
                            <CardTitle>{ticket.ticket_number}</CardTitle>
                            <CardDescription>{ticket.customer_name} · {ticket.device}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="grid gap-4 rounded-xl border border-border/70 bg-muted/20 p-4 text-sm sm:grid-cols-2">
                                <div><p className="text-xs text-muted-foreground">Keluhan awal</p><p className="mt-1 text-foreground">{ticket.initial_complaint || '—'}</p></div>
                                <div><p className="text-xs text-muted-foreground">Kebutuhan customer</p><p className="mt-1 text-foreground">{ticket.customer_needs || '—'}</p></div>
                                <div><p className="text-xs text-muted-foreground">Pelayan penerima</p><p className="mt-1 text-foreground">{ticket.pelayan_name}</p></div>
                                <div><p className="text-xs text-muted-foreground">Teknisi</p><p className="mt-1 text-foreground">{ticket.technician_name}</p></div>
                                <div className="sm:col-span-2">
                                    <p className="text-xs text-muted-foreground">Persetujuan customer</p>
                                    <p className={`mt-1 font-medium ${consentApproved ? 'text-emerald-600' : 'text-amber-600'}`}>
                                        {consentApproved ? 'Sudah disetujui' : 'Belum disetujui'}
                                    </p>
                                    {ticket.customer_consent_notes && <p className="mt-1 text-xs text-muted-foreground">{ticket.customer_consent_notes}</p>}
                                </div>
                            </div>

                            <form onSubmit={submit} className="mt-6 space-y-6">
                                {form.errors.completion && <div className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">{form.errors.completion}</div>}

                                <div>
                                    <label htmlFor="result_status" className="mb-2 block text-sm font-medium text-foreground">Hasil servis</label>
                                    <select
                                        id="result_status"
                                        value={form.data.result_status}
                                        onChange={(event) => form.setData('result_status', event.target.value)}
                                        className="flex h-10 w-full rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none transition-colors focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    >
                                        {resultOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                                    </select>
                                    <FieldError message={form.errors.result_status} />
                                </div>

                                <div className="grid gap-5 sm:grid-cols-2">
                                    <div>
                                        <label htmlFor="diagnosis_notes" className="mb-2 block text-sm font-medium text-foreground">Catatan diagnosa</label>
                                        <textarea
                                            id="diagnosis_notes"
                                            value={form.data.diagnosis_notes}
                                            onChange={(event) => form.setData('diagnosis_notes', event.target.value)}
                                            rows={5}
                                            className="flex min-h-28 w-full resize-y rounded-lg border border-input bg-transparent px-3 py-2 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                            placeholder="Tuliskan hasil pemeriksaan dan temuan kerusakan."
                                        />
                                        <FieldError message={form.errors.diagnosis_notes} />
                                    </div>
                                    <div>
                                        <label htmlFor="action_notes" className="mb-2 block text-sm font-medium text-foreground">Tindakan servis</label>
                                        <textarea
                                            id="action_notes"
                                            value={form.data.action_notes}
                                            onChange={(event) => form.setData('action_notes', event.target.value)}
                                            rows={5}
                                            className="flex min-h-28 w-full resize-y rounded-lg border border-input bg-transparent px-3 py-2 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                            placeholder="Tuliskan tindakan yang sudah dilakukan."
                                        />
                                        <FieldError message={form.errors.action_notes} />
                                    </div>
                                </div>

                                <div className="rounded-xl border border-border/70">
                                    <div className="border-b border-border/70 bg-muted/25 px-4 py-3">
                                        <p className="text-sm font-semibold text-foreground">Checklist QC</p>
                                        <p className="mt-1 text-xs text-muted-foreground">Untuk hasil Berhasil diperbaiki, semua komponen harus dinyatakan lulus.</p>
                                    </div>
                                    <div className="grid gap-3 p-4 sm:grid-cols-2">
                                        {checklistOptions.map((option) => (
                                            <label key={option.value} className="flex items-center gap-3 rounded-lg border border-border/70 px-3 py-3 text-sm text-foreground">
                                                <input
                                                    type="checkbox"
                                                    checked={form.data.qc_checklist[option.value] === true}
                                                    onChange={(event) => updateChecklist(option.value, event.target.checked)}
                                                    className="size-4 rounded border-input text-primary focus:ring-primary"
                                                />
                                                {option.label}
                                            </label>
                                        ))}
                                    </div>
                                    <FieldError message={form.errors.qc_checklist} />
                                </div>

                                {requiresReason && (
                                    <div>
                                        <label htmlFor="result_reason" className="mb-2 block text-sm font-medium text-foreground">
                                            {form.data.result_status === 'unrepairable' ? 'Alasan tidak dapat diperbaiki' : 'Alasan customer menolak perbaikan'}
                                        </label>
                                        <textarea
                                            id="result_reason"
                                            value={form.data.result_status === 'unrepairable' ? form.data.unrepairable_reason : form.data.customer_declined_reason}
                                            onChange={(event) => form.setData(form.data.result_status === 'unrepairable' ? 'unrepairable_reason' : 'customer_declined_reason', event.target.value)}
                                            rows={3}
                                            className="flex min-h-20 w-full resize-y rounded-lg border border-input bg-transparent px-3 py-2 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                            placeholder="Tuliskan alasan yang mendasari hasil servis."
                                        />
                                        <FieldError message={form.errors.unrepairable_reason || form.errors.customer_declined_reason} />
                                    </div>
                                )}

                                {!consentApproved && form.data.result_status === 'success' && (
                                    <div className="rounded-xl border border-amber-300/60 bg-amber-50/60 p-4 dark:border-amber-500/40 dark:bg-amber-950/20">
                                        <label className="flex items-start gap-3 text-sm text-foreground">
                                            <input
                                                type="checkbox"
                                                checked={form.data.customer_consent_confirmed}
                                                onChange={(event) => form.setData('customer_consent_confirmed', event.target.checked)}
                                                className="mt-0.5 size-4 rounded border-input text-primary focus:ring-primary"
                                            />
                                            <span>
                                                <span className="font-medium">Customer sudah menyetujui hasil dan biaya servis</span>
                                                <span className="mt-1 block text-xs text-muted-foreground">Persetujuan ini wajib untuk hasil servis berhasil diperbaiki.</span>
                                            </span>
                                        </label>
                                        <textarea
                                            value={form.data.customer_consent_notes}
                                            onChange={(event) => form.setData('customer_consent_notes', event.target.value)}
                                            rows={2}
                                            className="mt-3 flex min-h-16 w-full resize-y rounded-lg border border-input bg-background px-3 py-2 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                            placeholder="Catatan persetujuan customer (opsional)"
                                        />
                                        <FieldError message={form.errors.customer_consent_confirmed || form.errors.customer_consent_notes} />
                                    </div>
                                )}

                                <div className="rounded-xl border border-border/70">
                                    <div className="flex items-center justify-between gap-3 border-b border-border/70 bg-muted/25 px-4 py-3">
                                        <div>
                                            <p className="text-sm font-semibold text-foreground">Evidence servis</p>
                                            <p className="mt-1 text-xs text-muted-foreground">Isi referensi atau upload foto/PDF. Di HP, tombol file dapat membuka kamera.</p>
                                        </div>
                                        <Button type="button" variant="outline" size="sm" onClick={() => form.setData('technical_evidence', [...form.data.technical_evidence, { type: 'service_note', reference: '' }])}>
                                            <Plus />
                                            Tambah
                                        </Button>
                                    </div>
                                    <div className="space-y-3 p-4">
                                        {form.data.technical_evidence.map((evidence, index) => (
                                            <div key={index} className="grid gap-2 rounded-lg border border-border/60 p-3 sm:grid-cols-[10rem_1fr_auto]">
                                                <Input value={evidence.type} onChange={(event) => updateEvidence(index, 'type', event.target.value)} placeholder="Tipe bukti" aria-label={`Tipe evidence ${index + 1}`} />
                                                <div className="space-y-2">
                                                    <Input value={evidence.reference} onChange={(event) => updateEvidence(index, 'reference', event.target.value)} placeholder="Referensi dokumen / catatan" aria-label={`Referensi evidence ${index + 1}`} />
                                                    <input
                                                        type="file"
                                                        accept="image/*,.pdf"
                                                        capture="environment"
                                                        onChange={(event) => updateEvidence(index, 'file', event.target.files?.[0] ?? null)}
                                                        aria-label={`Upload file evidence ${index + 1}`}
                                                        className="block w-full text-xs text-muted-foreground file:mr-3 file:rounded-md file:border-0 file:bg-primary file:px-3 file:py-1.5 file:text-xs file:font-medium file:text-primary-foreground hover:file:bg-primary/90"
                                                    />
                                                    {evidence.file?.name && <p className="text-xs text-muted-foreground">File dipilih: {evidence.file.name}</p>}
                                                </div>
                                                <Button type="button" variant="ghost" size="icon" aria-label={`Hapus evidence ${index + 1}`} onClick={() => form.setData('technical_evidence', form.data.technical_evidence.filter((_, evidenceIndex) => evidenceIndex !== index))}>
                                                    <Trash2 />
                                                </Button>
                                            </div>
                                        ))}
                                        <FieldError message={form.errors.technical_evidence || evidenceError} />
                                    </div>
                                </div>

                                <div className="flex flex-col-reverse gap-2 border-t border-border/70 pt-5 sm:flex-row sm:justify-end">
                                    <Button asChild type="button" variant="outline"><Link href="/app/service-tickets">Batal</Link></Button>
                                    <Button type="submit" disabled={form.processing}>
                                        {form.processing ? <Loader2 className="animate-spin" /> : <Check />}
                                        Selesaikan tiket
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
