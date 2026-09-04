import { Link, router } from '@inertiajs/react';
import { ArrowRightCircle, CheckCircle2, Calculator, ClipboardCheck, LockKeyhole, Pencil, RefreshCw, RotateCcw, Send, Trash2, Upload } from 'lucide-react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useFeedback } from '@/components/feedback/ActionFeedback';

const statusVariants = {
    active: 'default',
    inactive: 'secondary',
    resigned: 'destructive',
    leave: 'outline',
};

const actionIcons = {
    assess: ClipboardCheck,
    approve: CheckCircle2,
    review: ArrowRightCircle,
    calculate: Calculator,
    complete: CheckCircle2,
    confirm: CheckCircle2,
    close_submission: LockKeyhole,
    lock: LockKeyhole,
    publish: Send,
    recalculate: Calculator,
    resolve: CheckCircle2,
    revision: RotateCcw,
    sync: RefreshCw,
    upload: Upload,
    validate: ClipboardCheck,
};

function CellValue({ column, value }) {
    if (column.type === 'boolean') {
        return <Badge variant={value?.value ? 'default' : 'secondary'}>{value?.label}</Badge>;
    }

    if (column.type === 'badge' || column.type === 'status') {
        return <Badge variant={column.type === 'status' ? (statusVariants[value?.value] ?? 'outline') : 'outline'}>{value?.label}</Badge>;
    }

    return <span className={cn(column.emphasis && 'font-semibold text-foreground')}>{value?.label ?? '—'}</span>;
}

export default function AdminTable({ resource, records = [] }) {
    const { requestAction } = useFeedback();

    const deleteRecord = async (record) => {
        const result = await requestAction({
            title: `Hapus ${resource.label.toLowerCase()}?`,
            description: `Data ${resource.label.toLowerCase()} ini akan dihapus dan tidak dapat dipulihkan dari halaman ini.`,
            confirmLabel: 'Hapus data',
            variant: 'destructive',
        });

        if (!result.confirmed) {
            return;
        }

        router.delete(`/app/${resource.key}/${record.id}`, {
            preserveScroll: true,
        });
    };

    const runAction = async (action, record) => {
        const message = action.confirm ?? `Jalankan ${action.label.toLowerCase()}?`;
        const promptConfig = action.prompt
            ? (typeof action.prompt === 'string' ? { label: action.prompt, name: 'reason' } : action.prompt)
            : null;
        const result = await requestAction({
            title: action.label,
            description: message,
            confirmLabel: promptConfig ? 'Kirim' : action.label,
            variant: action.variant === 'destructive' ? 'destructive' : 'default',
            prompt: promptConfig ?? undefined,
        });

        if (!result.confirmed) {
            return;
        }

        const payload = promptConfig ? { [promptConfig.name]: result.value } : {};

        router.post(action.record_url.replace(':record', record.id), payload, {
            preserveScroll: true,
        });
    };

    if (records.length === 0) {
        return (
            <div className="px-6 py-16 text-center">
                <p className="text-sm font-medium text-foreground">Belum ada data</p>
                <p className="mt-1 text-sm text-muted-foreground">Data akan muncul di sini setelah ditambahkan.</p>
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-sm">
                <thead className="border-b border-border/80 bg-muted/35 text-[11px] uppercase tracking-[0.12em] text-muted-foreground">
                    <tr>
                        {resource.columns.map((column) => (
                            <th key={column.key} className="whitespace-nowrap px-5 py-3 font-semibold">
                                {column.label}
                            </th>
                        ))}
                        <th className="w-24 px-5 py-3 text-right font-semibold">Aksi</th>
                    </tr>
                </thead>
                <tbody className="divide-y divide-border/70">
                    {records.map((record) => (
                        <tr key={record.id} className="group transition-colors hover:bg-muted/25">
                            {resource.columns.map((column) => (
                                <td key={column.key} className="px-5 py-4 align-middle text-muted-foreground">
                                    <CellValue column={column} value={record.values[column.key]} />
                                </td>
                            ))}
                            <td className="px-5 py-4 text-right align-middle">
                                <div className="flex justify-end gap-1 opacity-70 transition-opacity group-hover:opacity-100">
                                    {(record.can_edit ?? resource.can_edit) && (
                                        <Button asChild variant="ghost" size="icon-sm" title={`Ubah ${resource.label.toLowerCase()}`}>
                                            <Link href={`/app/${resource.key}/${record.id}/edit`} aria-label={`Ubah ${resource.label.toLowerCase()}`}>
                                                <Pencil />
                                            </Link>
                                        </Button>
                                    )}
                                    {(record.can_delete ?? resource.can_delete) && (
                                        <Button
                                            type="button"
                                            variant="destructive"
                                            size="icon-sm"
                                            title={`Hapus ${resource.label.toLowerCase()}`}
                                            aria-label={`Hapus ${resource.label.toLowerCase()}`}
                                            onClick={() => deleteRecord(record)}
                                        >
                                            <Trash2 />
                                        </Button>
                                    )}
                                    {(record.actions ?? []).map((action) => {
                                        const Icon = actionIcons[action.key] ?? RefreshCw;
                                        const actionUrl = action.record_url.replace(':record', record.id);

                                        if (action.type === 'link') {
                                            return (
                                                <Button key={action.key} asChild variant={action.variant ?? 'outline'} size="icon-sm" title={action.label}>
                                                    <Link href={actionUrl} aria-label={action.label}>
                                                        <Icon />
                                                    </Link>
                                                </Button>
                                            );
                                        }

                                        return (
                                            <Button
                                                key={action.key}
                                                type="button"
                                                variant={action.variant ?? 'outline'}
                                                size="icon-sm"
                                                title={action.label}
                                                aria-label={action.label}
                                                onClick={() => runAction(action, record)}
                                            >
                                                <Icon />
                                            </Button>
                                        );
                                    })}
                                </div>
                            </td>
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
