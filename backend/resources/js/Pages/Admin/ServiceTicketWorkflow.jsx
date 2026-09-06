import { Head, Link, useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';

const labels = {
    assign: 'Tugaskan Teknisi',
    consent: 'Catat persetujuan pelanggan',
    'payment-exception': 'Sahkan pengecualian pembayaran',
    'warranty-review': 'Validasi retur garansi',
    deliver: 'Serahkan perangkat',
    cancel: 'Batalkan tiket',
};

export default function ServiceTicketWorkflow({ ticket, technicians }) {
    const actions = ticket.available_actions.filter((action) => labels[action]);
    const form = useForm({
        action: actions[0] ?? '',
        row_version: ticket.row_version,
        technician_employee_id: ticket.technician_employee_id ?? technicians[0]?.id ?? '',
        consent_status: 'approved',
        exception_type: 'receivable',
        decision: 'approved',
        recipient_type: 'customer',
        recipient_name: ticket.customer_name,
        reason: '',
    });
    const select = (name, options) => (
        <select id={name} value={form.data[name]} onChange={(event) => form.setData(name, event.target.value)} className="w-full rounded-lg border border-input bg-background p-2" required>
            {options.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
        </select>
    );
    const submit = (event) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            row_version: ticket.row_version,
            assignment_reason: data.reason,
            consent_notes: data.reason,
            delivery_notes: data.reason,
        })).post(`/app/service-tickets/${ticket.id}/workflow`, { preserveScroll: true });
    };

    return (
        <div className="mx-auto max-w-3xl space-y-5 px-4 py-6">
            <Head title={ticket.ticket_number} />
            <Link href="/app/service-tickets" className="text-sm underline">Kembali ke tiket servis</Link>
            <Card>
                <CardHeader><CardTitle>{ticket.ticket_number} · {ticket.status}</CardTitle></CardHeader>
                <CardContent className="space-y-3">
                    <p>{ticket.customer_name} · {ticket.device_brand} {ticket.device_model}</p>
                    <p>{ticket.initial_complaint}</p>
                    <p>Pelayan: {ticket.pelayan_name} · Teknisi: {ticket.technician_name}</p>
                    <p>Diagnosis: {ticket.diagnosis_notes || 'Belum dicatat'}</p>
                    <p>Tindakan: {ticket.action_notes || 'Belum dicatat'}</p>
                    {(ticket.technical_evidence ?? []).map((evidence) => <p key={evidence.index}>
                        {evidence.has_file ? <a href={`/app/service-tickets/${ticket.id}/evidence/${evidence.index}`} className="underline">{evidence.file_name || evidence.reference}</a> : evidence.reference}
                    </p>)}
                    <p>Persetujuan: {ticket.customer_consent_status} · Pembayaran: {ticket.payment_status}</p>
                    <p>Biaya final: Rp {Number(ticket.final_cost).toLocaleString('id-ID')} · Dibayar: Rp {Number(ticket.paid_amount).toLocaleString('id-ID')}</p>
                    {ticket.available_actions.some((action) => ['estimated-cost', 'final-cost'].includes(action)) && <Button asChild><Link href={`/app/service-tickets/${ticket.id}/cost`}>Kelola biaya dan pembayaran</Link></Button>}
                </CardContent>
            </Card>
            {actions.length > 0 && <Card>
                <CardHeader><CardTitle>Tindakan tersedia</CardTitle></CardHeader>
                <CardContent>
                    <form onSubmit={submit} className="space-y-4">
                        <label htmlFor="action" className="block">Tindakan</label>
                        {select('action', actions.map((action) => [action, labels[action]]))}
                        {form.data.action === 'assign' && <><label htmlFor="technician_employee_id" className="block">Teknisi</label>{select('technician_employee_id', technicians.map((technician) => [technician.id, technician.name]))}</>}
                        {form.data.action === 'consent' && <><label htmlFor="consent_status" className="block">Persetujuan</label>{select('consent_status', [['approved', 'Setuju'], ['declined', 'Menolak']])}</>}
                        {form.data.action === 'payment-exception' && <><label htmlFor="exception_type" className="block">Jenis pengecualian</label>{select('exception_type', [['receivable', 'Piutang'], ['installment', 'Cicilan'], ['waiver', 'Pembebasan']])}</>}
                        {form.data.action === 'warranty-review' && <><label htmlFor="decision" className="block">Keputusan</label>{select('decision', [['approved', 'Setujui'], ['rejected', 'Tolak']])}</>}
                        {form.data.action === 'deliver' && <>
                            <label htmlFor="recipient_type" className="block">Penerima</label>{select('recipient_type', [['customer', 'Pelanggan'], ['representative', 'Perwakilan']])}
                            <label htmlFor="recipient_name" className="block">Nama penerima</label><Input id="recipient_name" value={form.data.recipient_name} onChange={(event) => form.setData('recipient_name', event.target.value)} required />
                        </>}
                        <label htmlFor="reason" className="block">Alasan / catatan</label>
                        <textarea id="reason" value={form.data.reason} onChange={(event) => form.setData('reason', event.target.value)} minLength={3} required rows={3} className="w-full rounded-lg border border-input bg-background p-2" />
                        {Object.entries(form.errors).map(([key, error]) => <p key={key} role="alert" className="text-sm text-destructive">{error}</p>)}
                        <Button disabled={form.processing}>Simpan tindakan</Button>
                    </form>
                </CardContent>
            </Card>}
        </div>
    );
}
