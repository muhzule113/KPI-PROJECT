import { ArrowLeft, Check, Loader2 } from 'lucide-react';
import { Head, Link, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

function FieldError({ message }) {
    return message ? <p className="mt-1.5 text-xs font-medium text-destructive">{message}</p> : null;
}

export default function ServiceTicketStatusForm({ ticket, status_options: statusOptions }) {
    const canUpdateStatus = statusOptions.length > 0;
    const visibleStatusOptions = canUpdateStatus
        ? statusOptions
        : [{ value: ticket.status, label: ticket.status_label }];

    const form = useForm({
        status: visibleStatusOptions[0]?.value ?? '',
        diagnosis_notes: ticket.diagnosis_notes ?? '',
        action_notes: ticket.action_notes ?? '',
        row_version: ticket.row_version,
    });

    const submit = (event) => {
        event.preventDefault();
        form.put(`/app/service-tickets/${ticket.id}/status`, { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Update Status ${ticket.ticket_number}`} />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-4xl space-y-6">
                    <div>
                        <Link href="/app/service-tickets" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                            <ArrowLeft className="size-3.5" />
                            Kembali ke tiket servis
                        </Link>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">Update Status Tiket</h1>
                            <Badge variant="outline">{ticket.status_label}</Badge>
                        </div>
                        <p className="mt-1.5 text-sm text-muted-foreground">Perbarui progres pengerjaan dan catatan servis tanpa mengubah data penerimaan customer.</p>
                        {ticket.status === 'qc_ready' && <Button asChild className="mt-4"><Link href={`/app/service-tickets/${ticket.id}/complete`}>Selesaikan Tiket</Link></Button>}
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
                            </div>

                            <form onSubmit={submit} className="mt-6 space-y-5">
                                <div>
                                    <label htmlFor="status" className="mb-2 block text-sm font-medium text-foreground">Status pengerjaan</label>
                                    <select
                                        id="status"
                                        value={form.data.status}
                                        onChange={(event) => form.setData('status', event.target.value)}
                                        disabled={!canUpdateStatus}
                                        required
                                        className="flex h-9 w-full rounded-lg border border-input bg-background px-2.5 text-sm text-foreground outline-none transition-colors focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                    >
                                        {visibleStatusOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                                    </select>
                                    {!canUpdateStatus && <p className="mt-1.5 text-xs text-muted-foreground">Tidak ada status lanjutan yang dapat diubah oleh pelayan pada tiket ini.</p>}
                                    <FieldError message={form.errors.status} />
                                </div>
                                <div>
                                    <label htmlFor="diagnosis_notes" className="mb-2 block text-sm font-medium text-foreground">Catatan diagnosa</label>
                                    <textarea
                                        id="diagnosis_notes"
                                        value={form.data.diagnosis_notes}
                                        onChange={(event) => form.setData('diagnosis_notes', event.target.value)}
                                        disabled={!canUpdateStatus}
                                        rows={5}
                                        className="flex min-h-28 w-full resize-y rounded-lg border border-input bg-transparent px-2.5 py-2 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
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
                                        disabled={!canUpdateStatus}
                                        rows={5}
                                        className="flex min-h-28 w-full resize-y rounded-lg border border-input bg-transparent px-2.5 py-2 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                        placeholder="Tuliskan tindakan yang sudah dilakukan atau akan dilakukan."
                                    />
                                    <FieldError message={form.errors.action_notes} />
                                </div>
                                <div className="flex flex-col-reverse gap-2 border-t border-border/70 pt-5 sm:flex-row sm:justify-end">
                                    <Button asChild type="button" variant="outline"><Link href="/app/service-tickets">Batal</Link></Button>
                                    <Button type="submit" disabled={!canUpdateStatus || form.processing || !form.data.status}>
                                        {form.processing ? <Loader2 className="animate-spin" /> : <Check />}
                                        Simpan update status
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
