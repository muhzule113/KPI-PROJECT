import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Loader2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout';

export default function KpiCorrectionForm({ kpi }) {
    const form = useForm({ reason: '', items: kpi.items.map((item) => ({ id: item.id, actual: item.actual ?? '' })) });

    const submit = (event) => {
        event.preventDefault();
        form.post(`/app/employee-kpis/${kpi.id}/correction`, { preserveScroll: true });
    };

    return (
        <AppLayout>
            <Head title="Ajukan Koreksi KPI" />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8"><div className="mx-auto max-w-3xl space-y-6">
                <div><Link href="/app/employee-kpis" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"><ArrowLeft className="size-3.5" />Kembali ke penilaian KPI</Link><h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Ajukan koreksi KPI</h1><p className="mt-1.5 text-sm text-muted-foreground">{kpi.employee} · {kpi.period}</p></div>
                <Card><CardHeader className="border-b border-border/70"><CardTitle>Koreksi resmi</CardTitle><CardDescription>Koreksi akan masuk proses dual authorization dan tidak langsung mengubah KPI.</CardDescription></CardHeader><CardContent><form onSubmit={submit} className="space-y-6">
                    <div><label htmlFor="reason" className="mb-2 block text-sm font-medium">Alasan koreksi</label><textarea id="reason" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} rows={4} className="flex min-h-24 w-full rounded-lg border border-input bg-transparent px-3 py-2 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50" placeholder="Jelaskan alasan perubahan data aktual..." />{form.errors.reason && <p className="mt-1.5 text-xs font-medium text-destructive">{form.errors.reason}</p>}</div>
                    <div className="overflow-hidden rounded-xl border border-border/70"><div className="border-b border-border/70 bg-muted/35 px-4 py-3 text-sm font-semibold">Nilai aktual baru</div><div className="divide-y divide-border/70">{kpi.items.map((item, index) => <div key={item.id} className="grid gap-3 px-4 py-4 sm:grid-cols-[1fr_12rem] sm:items-center"><div><p className="font-semibold text-foreground">{item.code}</p><p className="text-sm text-muted-foreground">{item.name}</p><p className="mt-1 text-xs text-muted-foreground">Nilai saat ini: {item.actual ?? '—'}</p></div><Input type="number" value={form.data.items[index]?.actual ?? ''} onChange={(event) => form.setData('items', form.data.items.map((current, currentIndex) => currentIndex === index ? { ...current, actual: event.target.value } : current))} aria-label={`Nilai aktual baru ${item.code}`} />{form.errors[`items.${index}.actual`] && <p className="text-xs font-medium text-destructive sm:col-start-2">{form.errors[`items.${index}.actual`]}</p>}</div>)}</div></div>
                    <div className="flex justify-end gap-2 border-t border-border/70 pt-5"><Button asChild type="button" variant="outline"><Link href="/app/employee-kpis">Batal</Link></Button><Button type="submit" disabled={form.processing}>{form.processing ? <Loader2 className="animate-spin" /> : <Check />}Ajukan koreksi</Button></div>
                </form></CardContent></Card>
            </div></div>
        </AppLayout>
    );
}
