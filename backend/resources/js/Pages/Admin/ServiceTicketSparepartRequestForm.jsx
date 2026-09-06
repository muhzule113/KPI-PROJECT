import { ArrowLeft, Check, Loader2 } from 'lucide-react';
import { Head, Link, useForm } from '@inertiajs/react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

function FieldError({ message }) {
    return message ? <p className="mt-1.5 text-xs font-medium text-destructive">{message}</p> : null;
}

const statusLabels = {
    pending: 'Menunggu Gudang',
    fulfilled: 'Dipenuhi',
    unavailable: 'Tidak tersedia',
};

export default function ServiceTicketSparepartRequestForm({ ticket, part_options: partOptions = [], requests = [] }) {
    const form = useForm({
        sparepart_id: partOptions[0]?.value ?? '',
        quantity: 1,
        notes: '',
    });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/service-tickets/${ticket.id}/sparepart-request`, { preserveScroll: true });
    };

    return (
        <>
            <Head title={`Request Sparepart ${ticket.ticket_number}`} />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-2xl space-y-6">
                    <div>
                        <Link href="/app/service-tickets" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                            <ArrowLeft className="size-3.5" />
                            Kembali ke tiket servis
                        </Link>
                        <div className="flex flex-wrap items-center gap-2">
                            <h1 className="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">Request Sparepart</h1>
                            <Badge variant="outline">{ticket.status_label}</Badge>
                        </div>
                        <p className="mt-1.5 text-sm text-muted-foreground">Tentukan part dan jumlah berdasarkan informasi Teknisi. Stok baru berkurang setelah Gudang memenuhi request.</p>
                    </div>

                    <Card className="shadow-sm shadow-slate-950/5">
                        <CardHeader className="border-b border-border/70">
                            <CardTitle>{ticket.ticket_number}</CardTitle>
                            <CardDescription>{ticket.customer_name} · {ticket.device} · Teknisi: {ticket.technician_name}</CardDescription>
                        </CardHeader>
                        <CardContent>
                            {partOptions.length === 0 ? (
                                <p className="rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground">Belum ada katalog sparepart untuk cabang tiket ini.</p>
                            ) : (
                                <form onSubmit={submit} className="space-y-5">
                                    <div>
                                        <label htmlFor="sparepart_id" className="mb-2 block text-sm font-medium text-foreground">Sparepart</label>
                                        <select
                                            id="sparepart_id"
                                            name="sparepart_id"
                                            value={form.data.sparepart_id}
                                            onChange={(event) => form.setData('sparepart_id', event.target.value)}
                                            required
                                            className="flex h-9 w-full rounded-lg border border-input bg-background px-2.5 text-sm text-foreground outline-none transition-colors focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50"
                                        >
                                            {partOptions.map((part) => <option key={part.value} value={part.value}>{part.label} ({part.code}) — Stok {part.stock} pcs</option>)}
                                        </select>
                                        <FieldError message={form.errors.sparepart_id} />
                                    </div>
                                    <div>
                                        <label htmlFor="quantity" className="mb-2 block text-sm font-medium text-foreground">Jumlah (pcs)</label>
                                        <Input id="quantity" name="quantity" type="number" min="1" step="1" value={form.data.quantity} onChange={(event) => form.setData('quantity', event.target.value)} required />
                                        <FieldError message={form.errors.quantity} />
                                    </div>
                                    <div>
                                        <label htmlFor="notes" className="mb-2 block text-sm font-medium text-foreground">Catatan (opsional)</label>
                                        <textarea id="notes" name="notes" value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} rows={3} className="flex min-h-20 w-full resize-y rounded-lg border border-input bg-transparent px-2.5 py-2 text-sm text-foreground outline-none transition-colors placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50" placeholder="Contoh: butuh LCD untuk Samsung Galaxy A54." />
                                        <FieldError message={form.errors.notes} />
                                    </div>
                                    <div className="flex flex-col-reverse gap-2 border-t border-border/70 pt-5 sm:flex-row sm:justify-end">
                                        <Button asChild type="button" variant="outline"><Link href="/app/service-tickets">Batal</Link></Button>
                                        <Button type="submit" disabled={form.processing || !form.data.sparepart_id}>
                                            {form.processing ? <Loader2 className="animate-spin" /> : <Check />}
                                            Kirim request ke Gudang
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </CardContent>
                    </Card>

                    {requests.length > 0 && (
                        <Card className="shadow-sm shadow-slate-950/5">
                            <CardHeader className="border-b border-border/70">
                                <CardTitle className="text-base">Request pada tiket ini</CardTitle>
                            </CardHeader>
                            <CardContent className="divide-y divide-border/70 p-0">
                                {requests.map((request, index) => (
                                    <div key={`${request.part_code}-${index}`} className="flex items-center justify-between gap-4 px-5 py-4 text-sm">
                                        <div>
                                            <p className="font-medium text-foreground">{request.part_name} × {request.quantity}</p>
                                            <p className="text-xs text-muted-foreground">{request.part_code || '—'}</p>
                                        </div>
                                        <Badge variant="outline">{statusLabels[request.status] || request.status}</Badge>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </>
    );
}
