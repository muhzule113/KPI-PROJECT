import { Head, useForm } from '@inertiajs/react';
import { CheckCircle2, Gauge, Loader2, MessageSquareText, Send, Star } from 'lucide-react';
import DecorativeBackdrop from '@/components/layout/DecorativeBackdrop';

export default function CustomerFeedbackForm({ ticket, has_feedback: hasFeedback, submit_url: submitUrl }) {
    const form = useForm({ rating: 5, comments: '' });

    const submit = (event) => {
        event.preventDefault();
        form.post(submitUrl, { preserveScroll: true });
    };

    return (
        <>
            <Head title="Feedback Layanan" />
            <main className="app-page-enter relative isolate flex min-h-[100dvh] items-center justify-center overflow-x-hidden auth-surface px-4 py-8 text-slate-950 sm:py-12">
                <DecorativeBackdrop />
                <div className="relative z-10 w-full max-w-lg">
                    <div className="mb-6 flex items-center justify-center gap-3">
                        <span className="flex size-10 items-center justify-center rounded-xl bg-indigo-500 text-white shadow-lg shadow-indigo-600/20"><Gauge className="size-5" /></span>
                        <span className="font-semibold tracking-wide">KPI System</span>
                    </div>

                    <div className="overflow-hidden rounded-3xl border border-white/75 bg-white/95 text-foreground shadow-2xl shadow-indigo-900/10 backdrop-blur-sm">
                        {hasFeedback ? (
                            <div className="px-6 py-12 text-center sm:px-10">
                                <span className="mx-auto flex size-16 items-center justify-center rounded-full bg-emerald-100 text-emerald-600"><CheckCircle2 className="size-8" /></span>
                                <h1 className="mt-6 text-2xl font-semibold tracking-tight">Feedback sudah diterima</h1>
                                <p className="mx-auto mt-3 max-w-sm text-sm leading-6 text-muted-foreground">Terima kasih, masukan Anda sudah tercatat. Kami menghargai waktu dan kepercayaan Anda.</p>
                            </div>
                        ) : (
                            <>
                                <div className="bg-gradient-to-br from-indigo-500 via-indigo-600 to-blue-700 px-6 py-8 text-white sm:px-10">
                                    <p className="text-xs font-semibold uppercase tracking-[0.2em] text-indigo-200">Terima kasih telah menggunakan layanan kami</p>
                                    <h1 className="mt-3 text-2xl font-semibold tracking-tight">Bagaimana pengalaman Anda?</h1>
                                    <p className="mt-2 text-sm leading-6 text-indigo-100/80">Bantu kami memberikan layanan servis HP yang lebih baik.</p>
                                </div>

                                <div className="space-y-6 px-6 py-7 sm:px-10">
                                    <div className="rounded-2xl border border-border bg-muted/30 p-4">
                                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">Detail layanan</p>
                                        <p className="mt-2 font-semibold text-foreground">{ticket.customer_name}</p>
                                        <p className="mt-1 text-sm text-muted-foreground">{ticket.device}{ticket.branch ? ` · ${ticket.branch}` : ''}</p>
                                        <p className="mt-1 text-xs text-muted-foreground">Nomor tiket: {ticket.number}</p>
                                    </div>

                                    <form onSubmit={submit} className="space-y-6" aria-busy={form.processing}>
                                        <fieldset>
                                            <legend className="mb-3 flex items-center gap-2 text-sm font-medium text-foreground"><Star className="size-4 text-amber-500" fill="currentColor" /> Beri rating layanan Anda</legend>
                                            <div className="grid grid-cols-5 gap-2">
                                                {[1, 2, 3, 4, 5].map((rating) => (
                                                    <label key={rating} className="cursor-pointer">
                                                        <input
                                                            type="radio"
                                                            name="rating"
                                                            value={rating}
                                                            checked={form.data.rating === rating}
                                                            onChange={() => form.setData('rating', rating)}
                                                            className="peer sr-only"
                                                        />
                                                        <span className="flex min-h-16 flex-col items-center justify-center gap-1 rounded-xl border border-border bg-background text-muted-foreground transition-colors hover:border-primary/50 hover:bg-primary/5 peer-checked:border-primary peer-checked:bg-primary/10 peer-checked:text-primary peer-focus-visible:ring-3 peer-focus-visible:ring-ring/50">
                                                            <Star className="size-5" fill={form.data.rating >= rating ? 'currentColor' : 'none'} />
                                                            <span className="text-xs font-semibold">{rating}</span>
                                                        </span>
                                                    </label>
                                                ))}
                                            </div>
                                            {form.errors.rating && <p className="mt-2 text-xs font-medium text-destructive">{form.errors.rating}</p>}
                                        </fieldset>

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

                                        <button type="submit" disabled={form.processing} className="inline-flex h-10 w-full items-center justify-center gap-2 rounded-xl bg-primary px-4 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary/80 focus-visible:outline-none focus-visible:ring-3 focus-visible:ring-ring/50 disabled:pointer-events-none disabled:opacity-60">
                                            {form.processing ? <Loader2 className="size-4 animate-spin" /> : <Send className="size-4" />}
                                            {form.processing ? 'Mengirim feedback...' : 'Kirim feedback'}
                                        </button>
                                    </form>
                                </div>
                            </>
                        )}
                    </div>
                    <p className="mt-5 text-center text-xs text-slate-600">Feedback Anda membantu kami terus berkembang.</p>
                </div>
            </main>
        </>
    );
}
