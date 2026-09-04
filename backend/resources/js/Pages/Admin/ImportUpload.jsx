import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, FileUp, Loader2 } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';

export default function ImportUpload({ periods }) {
    const form = useForm({ period_id: periods.find((period) => period.status === 'OPEN')?.value ?? periods[0]?.value ?? '', report_file: null });

    const submit = (event) => {
        event.preventDefault();
        form.post('/app/import-batches/upload', { forceFormData: true });
    };

    return (
        <AppLayout>
            <Head title="Upload Laporan Kasir" />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-2xl space-y-6">
                    <div>
                        <Link href="/app/import-batches" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground hover:text-foreground"><ArrowLeft className="size-3.5" />Kembali ke import</Link>
                        <h1 className="text-2xl font-semibold tracking-tight sm:text-3xl">Upload laporan kasir</h1>
                        <p className="mt-1.5 text-sm text-muted-foreground">File akan dianalisis dan masuk tahap preview sebelum diterapkan ke KPI.</p>
                    </div>
                    <Card>
                        <CardHeader className="border-b border-border/70"><CardTitle>File laporan</CardTitle><CardDescription>Format yang didukung: XLSX, XLS, CSV, atau TXT. Maksimal 10 MB.</CardDescription></CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-5">
                                <div>
                                    <label htmlFor="period_id" className="mb-2 block text-sm font-medium">Periode KPI</label>
                                    <select id="period_id" value={form.data.period_id} onChange={(event) => form.setData('period_id', event.target.value)} className="flex h-10 w-full rounded-lg border border-input bg-background px-3 text-sm outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50">
                                        <option value="">Pilih periode</option>
                                        {periods.map((period) => <option key={period.value} value={period.value}>{period.label}</option>)}
                                    </select>
                                    {form.errors.period_id && <p className="mt-1.5 text-xs font-medium text-destructive">{form.errors.period_id}</p>}
                                </div>
                                <div>
                                    <label htmlFor="report_file" className="mb-2 block text-sm font-medium">File laporan kasir</label>
                                    <label htmlFor="report_file" className="flex min-h-36 cursor-pointer flex-col items-center justify-center rounded-2xl border border-dashed border-border bg-muted/20 px-4 text-center transition-colors hover:border-primary/50 hover:bg-primary/5">
                                        <FileUp className="size-7 text-primary" />
                                        <span className="mt-2 text-sm font-medium text-foreground">Pilih file untuk diunggah</span>
                                        <span className="mt-1 text-xs text-muted-foreground">{form.data.report_file?.name ?? 'XLSX, XLS, CSV, TXT'}</span>
                                        <input id="report_file" type="file" accept=".xlsx,.xls,.csv,.txt" onChange={(event) => form.setData('report_file', event.target.files?.[0] ?? null)} className="sr-only" />
                                    </label>
                                    {form.errors.report_file && <p className="mt-1.5 text-xs font-medium text-destructive">{form.errors.report_file}</p>}
                                </div>
                                <div className="flex justify-end gap-2 border-t border-border/70 pt-5"><Button asChild type="button" variant="outline"><Link href="/app/import-batches">Batal</Link></Button><Button type="submit" disabled={form.processing || !form.data.report_file}>{form.processing ? <Loader2 className="animate-spin" /> : <FileUp />}Upload & analisis</Button></div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
