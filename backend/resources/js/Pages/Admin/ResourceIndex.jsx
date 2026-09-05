import { Head, Link, router, usePoll } from '@inertiajs/react';
import { ArrowLeft, ChevronLeft, ChevronRight, Plus, Search, SlidersHorizontal } from 'lucide-react';
import { useState } from 'react';

import AdminTable from '@/components/admin/AdminTable';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useFeedback } from '@/components/feedback/ActionFeedback';

export default function ResourceIndex(props) {
    return (
        <>
            {props.resource?.key === 'import-batches' ? (
                <ImportBatchResourceIndexContent {...props} />
            ) : (
                <ResourceIndexContent {...props} />
            )}
        </>
    );
}

function ImportBatchResourceIndexContent(props) {
    usePoll(30000, { only: ['resource', 'records', 'search', 'pagination'] });

    return <ResourceIndexContent {...props} />;
}

function ResourceIndexContent({ resource, records, search, pagination }) {
    const { requestAction } = useFeedback();
    const [searchValue, setSearchValue] = useState(search ?? '');

    const submitSearch = (event) => {
        event.preventDefault();
        router.get(`/app/${resource.key}`, searchValue ? { search: searchValue } : {}, {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const runHeaderAction = async (action) => {
        if (action.confirm) {
            const result = await requestAction({
                title: action.label,
                description: action.confirm,
                confirmLabel: action.label,
                variant: action.variant === 'destructive' ? 'destructive' : 'default',
            });

            if (!result.confirmed) return;
        }

        router.post(action.url, {}, { preserveScroll: true });
    };

    return (
        <>
            <Head title={resource.plural_label} />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-[1440px] space-y-6">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            <Link href="/app" className="mb-3 inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground transition-colors hover:text-foreground">
                                <ArrowLeft className="size-3.5" />
                                Dashboard
                            </Link>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">{resource.plural_label}</h1>
                                <Badge variant="secondary">{pagination.total} data</Badge>
                            </div>
                            <p className="mt-1.5 max-w-2xl text-sm text-muted-foreground">{resource.description}</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {resource.header_actions?.map((action) => (
                                action.type === 'link' ? (
                                    <Button key={action.key} asChild variant={action.variant ?? 'outline'}><Link href={action.url}>{action.label}</Link></Button>
                                ) : (
                                    <Button key={action.key} type="button" variant={action.variant ?? 'outline'} onClick={() => runHeaderAction(action)}>{action.label}</Button>
                                )
                            ))}
                            {resource.can_create && (
                                <Button asChild className="shadow-sm">
                                    <Link href={`/app/${resource.key}/create`}>
                                        <Plus />
                                        Tambah {resource.label}
                                    </Link>
                                </Button>
                            )}
                        </div>
                    </div>

                    <Card className="overflow-hidden shadow-sm shadow-slate-950/5">
                        <CardHeader className="gap-4 border-b border-border/70 bg-card sm:flex-row sm:items-center sm:justify-between">
                            <div className="flex items-center gap-2">
                                <span className="flex size-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                    <SlidersHorizontal className="size-4" />
                                </span>
                                <div>
                                    <p className="text-sm font-semibold text-card-foreground">Daftar {resource.plural_label.toLowerCase()}</p>
                                    <p className="text-xs text-muted-foreground">Kelola data dengan cepat dan terstruktur.</p>
                                </div>
                            </div>
                            <form onSubmit={submitSearch} className="flex w-full gap-2 sm:max-w-sm">
                                <div className="relative flex-1">
                                    <Search className="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                    <Input value={searchValue} onChange={(event) => setSearchValue(event.target.value)} placeholder={`Cari ${resource.label.toLowerCase()}...`} className="pl-8" aria-label={`Cari ${resource.label.toLowerCase()}`} />
                                </div>
                                <Button type="submit" variant="outline">Cari</Button>
                            </form>
                        </CardHeader>
                        <CardContent className="p-0">
                            <AdminTable resource={resource} records={records} />
                        </CardContent>
                        {pagination.last_page > 1 && (
                            <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/70 px-5 py-3 text-xs text-muted-foreground">
                                <span>Menampilkan {pagination.from ?? 0}–{pagination.to ?? 0} dari {pagination.total} data</span>
                                <div className="flex items-center gap-1">
                                    {pagination.previous_url ? (
                                        <Button asChild variant="outline" size="icon-sm"><Link href={pagination.previous_url} aria-label="Halaman sebelumnya"><ChevronLeft /></Link></Button>
                                    ) : <Button variant="outline" size="icon-sm" disabled><ChevronLeft /></Button>}
                                    <span className="px-2 font-medium text-foreground">Halaman {pagination.current_page} / {pagination.last_page}</span>
                                    {pagination.next_url ? (
                                        <Button asChild variant="outline" size="icon-sm"><Link href={pagination.next_url} aria-label="Halaman berikutnya"><ChevronRight /></Link></Button>
                                    ) : <Button variant="outline" size="icon-sm" disabled><ChevronRight /></Button>}
                                </div>
                            </div>
                        )}
                    </Card>
                </div>
            </div>
        </>
    );
}
