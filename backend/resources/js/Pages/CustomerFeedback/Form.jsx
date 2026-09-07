import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, Clock3, Gauge, Loader2, MessageSquareText, Send, Star, Wrench } from 'lucide-react';
import DecorativeBackdrop from '@/components/layout/DecorativeBackdrop';

const statusLabels = {
    intake: 'Diterima',
    diagnosing: 'Diagnosis',
    waiting_sparepart: 'Menunggu sparepart',
    in_progress: 'Dikerjakan',
    qc_ready: 'Siap QC',
    completed: 'Selesai',
    delivered: 'Diserahkan',
    cancelled: 'Dibatalkan',
};

const serviceStages = ['intake', 'diagnosing', 'waiting_sparepart', 'in_progress', 'qc_ready', 'completed', 'delivered'];

const formatDateTime = (value) => value ? new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : null;

export default function CustomerFeedbackForm({ ticket, has_feedback: hasFeedback, submit_url: submitUrl }) {
    const form = useForm({ rating: null, technician_rating: null, comments: '' });
    const stages = ticket.timeline?.length
        ? ticket.timeline
        : [...serviceStages, 'cancelled'].map((key) => ({ key, label: statusLabels[key] }));

    const submit = (event) => {
        event.preventDefault();
        form.post(submitUrl, { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Progres Servis ${ticket.number}`} />
            <main className="app-page-enter relative isolate min-h-[100dvh] overflow-x-hidden auth-surface px-4 py-8 text-slate-950 sm:py-12">
                <DecorativeBackdrop />
                <div className="relative z-10 mx-auto w-full max-w-3xl">
                    <div className="mb-6 flex items-center justify-center gap-3">
                        <span className="flex size-10 items-center justify-center rounded-xl bg-indigo-500 text-white shadow-lg shadow-indigo-600/20"><Gauge className="size-5" /></span>
                        <span className="font-semibold tracking-wide">KPI System</span>
                    </div>

                    <div className="overflow-hidden rounded-3xl border border-white/75 bg-white/95 text-foreground shadow-2xl shadow-indigo-900/10 backdrop-blur-sm">
                        <div className="bg-gradient-to-br from-indigo-500 via-indigo-600 to-blue-700 px-6 py-8 text-white sm:px-10">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-indigo-100">Progres servis</p>
                                    <h1 className="mt-2 text-2xl font-semibold tracking-tight">{ticket.device}</h1>
                                    <p className="mt-2 text-sm text-indigo-100">Nomor tiket: {ticket.number}</p>
                                </div>
                                <span className="rounded-lg bg-white/15 px-3 py-1.5 text-sm font-medium ring-1 ring-white/25">
                                    {ticket.status_label ?? statusLabels[ticket.status] ?? ticket.status}
                                </span>
                            </div>
                        </div>

                        <div className="space-y-8 px-6 py-7 sm:px-10">
                            <section aria-labelledby="service-detail-title">
                                <h2 id="service-detail-title" className="text-sm font-semibold text-foreground">Detail servis</h2>
                                <dl className="mt-4 grid gap-x-8 gap-y-4 text-sm sm:grid-cols-2">
                                    <Detail label="Customer" value={ticket.customer_name} />
                                    <Detail label="Cabang" value={ticket.branch} />
                                    <Detail label="Keluhan awal" value={ticket.initial_complaint} className="sm:col-span-2" />
                                    <Detail label="Pelayan" value={ticket.pelayan} />
                                    <Detail label="Teknisi" value={ticket.technician ?? 'Belum ditugaskan'} />
                                    <Detail label="Estimasi selesai" value={formatDateTime(ticket.estimated_completion_at) ?? 'Belum ditentukan'} />
                                    <Detail label="Terakhir diperbarui" value={formatDateTime(ticket.updated_at)} />
                                </dl>
                            </section>

                            <section aria-labelledby="service-progress-title">
                                <div className="flex items-center gap-2">
                                    <Clock3 className="size-4 text-primary" />
                                    <h2 id="service-progress-title" className="text-sm font-semibold text-foreground">Tahapan servis</h2>
                                </div>
                                <ol className="mt-4 space-y-0" aria-label="Progres tiket servis">
                                    {stages.map((stage, index) => {
                                        const isCurrent = stage.state === 'current' || stage.key === ticket.status;
                                        const isCompleted = stage.state === 'completed';

                                        return (
                                            <li key={stage.key} className="relative flex min-h-14 gap-3 pb-4 last:min-h-0 last:pb-0" aria-current={isCurrent ? 'step' : undefined}>
                                                {index < stages.length - 1 && <span className={`absolute left-[15px] top-8 h-[calc(100%-1rem)] w-px ${isCompleted ? 'bg-primary/50' : 'bg-border'}`} aria-hidden="true" />}
                                                <span className={`relative z-10 flex size-8 shrink-0 items-center justify-center rounded-full border ${isCurrent ? 'border-primary bg-primary text-primary-foreground' : isCompleted ? 'border-primary/40 bg-primary/10 text-primary' : 'border-border bg-background text-muted-foreground'}`}>
                                                    {isCompleted ? <CheckCircle2 className="size-4" /> : stage.key === 'cancelled' ? <span className="text-sm font-semibold">!</span> : <Wrench className="size-3.5" />}
                                                </span>
                                                <div className="pt-1">
                                                    <p className={`text-sm font-medium ${isCurrent || isCompleted ? 'text-foreground' : 'text-muted-foreground'}`}>{stage.label ?? statusLabels[stage.key]}</p>
                                                    {isCurrent && <p className="mt-0.5 text-xs text-muted-foreground">Status servis saat ini</p>}
                                                    {stage.state === 'optional' && <p className="mt-0.5 text-xs text-muted-foreground">Tahap opsional, bila diperlukan</p>}
                                                </div>
                                            </li>
                                        );
                                    })}
                                </ol>
                            </section>

                            {hasFeedback && (
                                <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-5 text-center">
                                    <CheckCircle2 className="mx-auto size-8 text-emerald-600" />
                                    <h2 className="mt-3 font-semibold text-emerald-950">Feedback sudah diterima</h2>
                                    <p className="mt-1 text-sm leading-6 text-emerald-800">Terima kasih, masukan Anda sudah tercatat. Progres servis tetap dapat dilihat melalui halaman ini.</p>
                                </div>
                            )}

                            {!hasFeedback && !ticket.can_feedback && (
                                <div className="rounded-2xl border border-border bg-muted/30 p-5 text-sm leading-6 text-muted-foreground">
                                    {ticket.status === 'cancelled'
                                        ? 'Tiket servis ini telah dibatalkan dan tidak menerima penilaian.'
                                        : 'Penilaian dapat diberikan setelah perangkat diserahkan.'}
                                </div>
                            )}

                            {ticket.can_feedback && (
                                <section className="border-t border-border pt-7" aria-labelledby="feedback-title">
                                    <h2 id="feedback-title" className="text-xl font-semibold tracking-tight text-foreground">Nilai layanan kami</h2>
                                    <p className="mt-1 text-sm leading-6 text-muted-foreground">Pilih rating untuk setiap petugas yang menangani servis Anda.</p>

                                    <form onSubmit={submit} className="mt-6 space-y-6" aria-busy={form.processing}>
                                        <RatingField
                                            name="rating"
                                            label={`Pelayan${ticket.pelayan ? `, ${ticket.pelayan}` : ''}`}
                                            value={form.data.rating}
                                            error={form.errors.rating}
                                            onChange={(rating) => form.setData('rating', rating)}
                                        />

                                        {ticket.technician && (
                                            <RatingField
                                                name="technician_rating"
                                                label={`Teknisi, ${ticket.technician}`}
                                                value={form.data.technician_rating}
                                                error={form.errors.technician_rating}
                                                onChange={(rating) => form.setData('technician_rating', rating)}
                                            />
                                        )}

                                        <div>
                                            <label htmlFor="comments" className="mb-2 flex items-center gap-2 text-sm font-medium text-foreground"><MessageSquareText className="size-4 text-primary" /> Ceritakan pengalaman Anda <span className="font-normal text-muted-foreground">(opsional)</span></label>
                                            <textarea
                                                id="comments"
                                                value={form.data.comments}
                                                onChange={(event) => form.setData('comments', event.target.value)}
                                                rows={4}
                                                maxLength={2000}
                                                placeholder="Apa yang paling membantu atau perlu kami perbaiki?"
                                                className="w-full resize-y rounded-xl border border-input bg-transparent px-3 py-2.5 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                            />
                                            {form.errors.comments && <p className="mt-1.5 text-xs font-medium text-destructive">{form.errors.comments}</p>}
                                        </div>

                                        <button type="submit" disabled={form.processing} className="inline-flex min-h-11 w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/80 focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-60">
                                            {form.processing ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                                            {form.processing ? 'Mengirim feedback...' : 'Kirim feedback'}
                                        </button>
                                    </form>
                                </section>
                            )}
                        </div>
                    </div>
                    <p className="mt-5 text-center text-xs text-slate-600">Simpan tautan ini untuk melihat pembaruan progres servis.</p>
                </div>
            </main>
        </>
    );
}

function Detail({ label, value, className = '' }) {
    return (
        <div className={className}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-1 font-medium text-foreground">{value || 'Belum tersedia'}</dd>
        </div>
    );
}

function RatingField({ name, label, value, error, onChange }) {
    return (
        <fieldset>
            <legend className="mb-3 flex items-center gap-2 text-sm font-medium text-foreground"><Star className="size-4 text-amber-500" fill="currentColor" /> Rating {label}</legend>
            <div className="grid grid-cols-5 gap-2">
                {[1, 2, 3, 4, 5].map((rating) => (
                    <label key={rating} className="cursor-pointer">
                        <input
                            type="radio"
                            name={name}
                            value={rating}
                            checked={value === rating}
                            onChange={() => onChange(rating)}
                            className="peer sr-only"
                            required
                        />
                        <span className="flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl border border-border bg-background text-muted-foreground transition-colors hover:border-primary/50 hover:bg-primary/5 peer-checked:border-primary peer-checked:bg-primary/10 peer-checked:text-primary peer-focus-visible:ring-3 peer-focus-visible:ring-ring/50">
                            <Star className="size-5" fill={value !== null && value >= rating ? 'currentColor' : 'none'} />
                            <span className="text-xs font-semibold">{rating}</span>
                        </span>
                    </label>
                ))}
            </div>
            {error && <p className="mt-2 text-xs font-medium text-destructive">{error}</p>}
        </fieldset>
    );
}
