import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, CheckCircle2, ClipboardCheck, Loader2 } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { DatePicker } from '@/components/ui/date-picker';
import { Input } from '@/components/ui/input';
import { useFeedback } from '@/components/feedback/ActionFeedback';

export default function StockOpnameForm(props) {
    return (
        <>
            <StockOpnameContent {...props} />
        </>
    );
}

function StockOpnameContent({ opname, periods }) {
    const { requestAction } = useFeedback();
    const form = useForm({
        code: opname.code ?? '',
        period_id: opname.period_id,
        deadline: opname.deadline ?? '',
        items: opname.items.map((item) => ({ id: item.id, physical_stock: item.physical_stock ?? '' })),
    });
    const completed = opname.status === 'completed';

    const submit = (event) => {
        event.preventDefault();
        form.put(`/app/stock-opnames/${opname.id}`, { preserveScroll: true });
    };

    const complete = async () => {
        const result = await requestAction({
            title: 'Selesaikan stock opname?',
            description: 'Stok fisik akan disesuaikan melalui ledger dan KPI terkait akan disinkronkan.',
            confirmLabel: 'Selesaikan & sinkronkan',
        });

        if (result.confirmed) {
            router.post(`/app/stock-opnames/${opname.id}/complete`, {}, { preserveScroll: true });
        }
    };

    return (
        <>
            <Head title={`Stock Opname ${opname.code}`} />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-5xl space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div><Link href="/app/stock-opnames" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"><ArrowLeft className="size-3.5" />Kembali ke stock opname</Link><h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">{opname.code}</h1><p className="mt-1.5 text-sm text-muted-foreground">{opname.period} · Deadline {opname.deadline || 'belum diatur'}</p></div>
                        <Badge variant={completed ? 'default' : 'outline'}>{completed ? 'Selesai' : opname.status === 'in_progress' ? 'Sedang berjalan' : 'Draft'}</Badge>
                    </div>
                    <Card>
                        <CardHeader className="border-b border-border/70"><CardTitle>Informasi sesi</CardTitle><CardDescription>Stok sistem adalah snapshot saat sesi dibuat. Isi stok fisik sesuai hasil hitung gudang.</CardDescription></CardHeader>
                        <CardContent><form onSubmit={submit} className="space-y-6">
                            <div className="grid gap-5 sm:grid-cols-3"><div><label htmlFor="code" className="mb-2 block text-sm font-medium">Kode Opname</label><Input id="code" value={form.data.code} onChange={(event) => form.setData('code', event.target.value)} disabled={completed} /></div><div><label htmlFor="period_id" className="mb-2 block text-sm font-medium">Periode KPI</label><select id="period_id" value={form.data.period_id} onChange={(event) => form.setData('period_id', event.target.value)} disabled={completed} className="flex h-8 w-full rounded-lg border border-input bg-background px-2.5 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50">{periods.map((period) => <option key={period.value} value={period.value}>{period.label}</option>)}</select></div><div><label htmlFor="deadline" className="mb-2 block text-sm font-medium">Deadline</label><DatePicker id="deadline" value={form.data.deadline} onChange={(value) => form.setData('deadline', value)} disabled={completed} clearable className="w-full" aria-label="Deadline" /></div></div>
                            <div className="overflow-hidden rounded-xl border border-border/70"><div className="flex items-center gap-2 border-b border-border/70 bg-muted/35 px-4 py-3"><ClipboardCheck className="size-4 text-primary" /><p className="text-sm font-semibold">Hasil hitung per item</p></div><div className="overflow-x-auto"><table className="w-full min-w-[680px] text-left text-sm"><thead className="border-b border-border/70 text-xs text-muted-foreground"><tr><th className="px-4 py-3">Produk</th><th className="px-4 py-3">Stok sistem</th><th className="px-4 py-3">Stok fisik</th><th className="px-4 py-3">Selisih</th></tr></thead><tbody className="divide-y divide-border/70">{opname.items.map((item, index) => { const physical = Number(form.data.items[index]?.physical_stock ?? ''); const difference = Number.isFinite(physical) && form.data.items[index]?.physical_stock !== '' ? physical - item.system_stock : null; return <tr key={item.id}><td className="px-4 py-3"><p className="font-medium text-foreground">{item.sparepart}</p><p className="text-xs text-muted-foreground">{item.code}</p></td><td className="px-4 py-3 text-muted-foreground">{item.system_stock}</td><td className="px-4 py-3"><Input type="number" min="0" value={form.data.items[index]?.physical_stock ?? ''} onChange={(event) => form.setData('items', form.data.items.map((current, currentIndex) => currentIndex === index ? { ...current, physical_stock: event.target.value } : current))} disabled={completed} className="max-w-32" /></td><td className={`px-4 py-3 font-semibold ${difference === null ? 'text-muted-foreground' : difference === 0 ? 'text-emerald-600' : 'text-amber-600'}`}>{difference === null ? '—' : difference > 0 ? `+${difference}` : difference}</td></tr>; })}</tbody></table></div></div>
                            {!completed && <div className="flex justify-end gap-2 border-t border-border/70 pt-5"><Button asChild type="button" variant="outline"><Link href="/app/stock-opnames">Batal</Link></Button><Button type="submit" disabled={form.processing}>{form.processing ? <Loader2 className="animate-spin" /> : <CheckCircle2 />}Simpan hasil opname</Button><Button type="button" onClick={complete}><CheckCircle2 />Selesaikan & sinkronkan KPI</Button></div>}
                        </form></CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}
