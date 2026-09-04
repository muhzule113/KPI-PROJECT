import { Badge } from '@/components/ui/badge';

const statusMap = {
    approved: { label: 'Disetujui', variant: 'default' },
    locked: { label: 'Dikunci', variant: 'secondary' },
    verified: { label: 'Terverifikasi', variant: 'secondary' },
    pending_approval: { label: 'Menunggu approval', variant: 'outline' },
    revision_required: { label: 'Perlu revisi', variant: 'destructive' },
    submitted: { label: 'Terkirim', variant: 'outline' },
    under_review: { label: 'Sedang ditinjau', variant: 'outline' },
    draft: { label: 'Draft', variant: 'secondary' },
};

export default function StatusBadge({ status }) {
    const item = statusMap[status] ?? {
        label: status ? status.replaceAll('_', ' ') : 'Belum ada status',
        variant: 'secondary',
    };

    return <Badge variant={item.variant}>{item.label}</Badge>;
}
