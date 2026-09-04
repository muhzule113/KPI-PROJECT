import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Check, Loader2 } from 'lucide-react';

import AdminForm from '@/components/admin/AdminForm';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout';

export default function ResourceForm({ resource, form: formPayload }) {
    const form = useForm(formPayload.values);
    const isEdit = formPayload.mode === 'edit';

    const submit = (event) => {
        event.preventDefault();

        if (isEdit) {
            form.put(`/app/${resource.key}/${formPayload.record_id}`, { preserveScroll: true });
        } else {
            form.post(`/app/${resource.key}`, { preserveScroll: true });
        }
    };

    return (
        <AppLayout>
            <Head title={`${isEdit ? 'Ubah' : 'Tambah'} ${resource.label}`} />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-4xl space-y-6">
                    <div>
                        <Link href={`/app/${resource.key}`} className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                            <ArrowLeft className="size-3.5" />
                            Kembali ke {resource.plural_label}
                        </Link>
                        <h1 className="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">{isEdit ? 'Ubah' : 'Tambah'} {resource.label}</h1>
                        <p className="mt-1.5 text-sm text-muted-foreground">{resource.description}</p>
                    </div>

                    <Card className="shadow-sm shadow-slate-950/5">
                        <CardHeader className="border-b border-border/70">
                            <CardTitle>Informasi {resource.label}</CardTitle>
                            <CardDescription>Lengkapi data berikut agar informasi tetap akurat.</CardDescription>
                        </CardHeader>
                        <CardContent>
                            <form onSubmit={submit} className="space-y-6">
                                <AdminForm fields={resource.fields} options={formPayload.options} form={form} />
                                <div className="flex flex-col-reverse gap-2 border-t border-border/70 pt-5 sm:flex-row sm:justify-end">
                                    <Button asChild type="button" variant="outline">
                                        <Link href={`/app/${resource.key}`}>Batal</Link>
                                    </Button>
                                    <Button type="submit" disabled={form.processing}>
                                        {form.processing ? <Loader2 className="animate-spin" /> : <Check />}
                                        {isEdit ? 'Simpan perubahan' : 'Simpan data'}
                                    </Button>
                                </div>
                            </form>
                        </CardContent>
                    </Card>
                </div>
            </div>
        </AppLayout>
    );
}
