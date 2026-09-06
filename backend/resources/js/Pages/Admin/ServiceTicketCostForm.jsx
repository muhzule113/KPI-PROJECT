import { ArrowLeft, Check, Loader2 } from 'lucide-react';
import { Head, Link, useForm } from '@inertiajs/react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

function FieldError({ message }) {
    return message ? <p className="mt-1.5 text-xs font-medium text-destructive">{message}</p> : null;
}

export default function ServiceTicketCostForm({ mode, ticket }) {
    const isEstimate = mode === 'estimate';
    const form = useForm({
        estimated_cost: ticket.estimated_cost ?? 0,
        final_cost: ticket.final_cost ?? 0,
        paid_amount: ticket.paid_amount ?? 0,
        note: '',
        row_version: ticket.row_version,
    });

    const submit = (event) => {
        event.preventDefault();
        form.post('/app/service-tickets/' + ticket.id + '/cost', { preserveScroll: true });
    };

    return (
        <>
            <Head title={'Kelola Biaya ' + ticket.ticket_number} />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-2xl space-y-6">
                    <div>
                        <Link href="/app/service-tickets" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                            <ArrowLeft className="size-3.5" />
                            Kembali ke tiket servis
                        </Link>
                        <h1 className="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">Kelola Biaya / Nota</h1>
                        <p className="mt-1.5 text-sm text-muted-foreground">Nominal biaya hanya dapat diisi oleh Kasir atau manajemen.</p>
                    </div>

                    <Card className="shadow-sm shadow-slate-950/5">
                        <CardHeader className="border-b border-border/70">
                            <CardTitle>{ticket.ticket_number}</CardTitle>
                            <CardDescription>{ticket.customer_name} · {ticket.device}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <div className="mb-6 grid gap-3 rounded-xl border border-border/70 bg-muted/20 p-4 text-sm sm:grid-cols-2">
                                <div><p className="text-xs text-muted-foreground">Pelayan penerima</p><p className="mt-1 font-medium text-foreground">{ticket.pelayan_name}</p></div>
                                <div><p className="text-xs text-muted-foreground">Kasir terakhir</p><p className="mt-1 font-medium text-foreground">{ticket.cashier_name}</p></div>
                                {!isEstimate && <div><p className="text-xs text-muted-foreground">Status pembayaran</p><p className="mt-1 font-medium text-foreground">{ticket.payment_status}</p></div>}
                            </div>

                            <form onSubmit={submit} className="space-y-5">
                                {isEstimate ? (
                                    <div>
                                        <label htmlFor="estimated_cost" className="mb-2 block text-sm font-medium text-foreground">Estimasi biaya (Rp)</label>
                                        <Input id="estimated_cost" name="estimated_cost" type="number" min="0" step="1" value={form.data.estimated_cost} onChange={(event) => form.setData('estimated_cost', event.target.value)} required />
                                        <FieldError message={form.errors.estimated_cost || form.errors.cost} />
                                    </div>
                                ) : (
                                    <>
                                        <div>
                                            <label htmlFor="final_cost" className="mb-2 block text-sm font-medium text-foreground">Biaya final (Rp)</label>
                                            <Input id="final_cost" name="final_cost" type="number" min="0" step="1" value={form.data.final_cost} onChange={(event) => form.setData('final_cost', event.target.value)} required />
                                            <FieldError message={form.errors.final_cost || form.errors.cost} />
                                        </div>
                                        <div>
                                            <label htmlFor="paid_amount" className="mb-2 block text-sm font-medium text-foreground">Pembayaran aktual (Rp)</label>
                                            <Input id="paid_amount" name="paid_amount" type="number" min="0" step="1" value={form.data.paid_amount} onChange={(event) => form.setData('paid_amount', event.target.value)} required />
                                            <FieldError message={form.errors.paid_amount} />
                                        </div>
                                    </>
                                )}
                                <div>
                                    <label htmlFor="note" className="mb-2 block text-sm font-medium text-foreground">Catatan (opsional)</label>
                                    <textarea id="note" name="note" value={form.data.note} onChange={(event) => form.setData('note', event.target.value)} rows={3} className="flex min-h-20 w-full resize-y rounded-lg border border-input bg-transparent px-2.5 py-2 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50" placeholder="Catatan konfirmasi atau perubahan nominal." />
                                    <FieldError message={form.errors.note} />
                                </div>
                                <div className="flex flex-col-reverse gap-2 border-t border-border/70 pt-5 sm:flex-row sm:justify-end">
                                    <Button asChild type="button" variant="outline"><Link href="/app/service-tickets">Batal</Link></Button>
                                    <Button type="submit" disabled={form.processing}>
                                        {form.processing ? <Loader2 className="animate-spin" /> : <Check />}
                                        Simpan biaya
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
