"use client";

import { ArrowRightIcon, CurrencyCircleDollarIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import { useRouter } from "next/navigation";
import { useActionState, useEffect } from "react";
import { addTechnicalEvidence, approvePaymentException, assignTechnician, completeTicket, confirmSparepart, createTicket, createWarrantyReturn, decideSparepartRequest, deliverTicket, recordCustomerConsent, recordPayment, recordTicketCost, requestSparepart, reviewWarrantyReturn, saveTicketProgress, updateTicketStatus, type TicketActionState } from "@/app/(workspace)/app/servis/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

const initialState: TicketActionState = {};

function Feedback({ state }: { state: TicketActionState }) {
  if (state.error) return <p role="alert" className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">{state.error}</p>;
  if (state.success) return <p role="status" className="rounded-lg bg-accent px-3 py-2 text-sm text-primary">{state.success}</p>;
  return null;
}

export function CreateTicketForm({ branches }: { branches: Array<{ id: string; name: string }> }) {
  const [state, action, pending] = useActionState(createTicket, initialState);
  const router = useRouter();
  useEffect(() => { if (state.ticketId) router.replace(`/app/servis/${state.ticketId}`); }, [router, state.ticketId]);
  return (
    <form action={action} className="space-y-6">
      <section className="rounded-2xl border bg-card p-5 sm:p-6">
        <h2 className="text-base font-semibold">Pelanggan dan perangkat</h2>
        <div className="mt-5 grid gap-5 sm:grid-cols-2">
          <div className="space-y-2"><Label htmlFor="customerName">Nama pelanggan</Label><Input id="customerName" name="customerName" required maxLength={120} /></div>
          <div className="space-y-2"><Label htmlFor="customerPhone">Nomor telepon</Label><Input id="customerPhone" name="customerPhone" type="tel" inputMode="tel" required minLength={7} maxLength={30} /></div>
          <div className="space-y-2 sm:col-span-2"><Label htmlFor="customerAddress">Alamat pelanggan <span className="font-normal text-muted-foreground">(opsional)</span></Label><Textarea id="customerAddress" name="customerAddress" maxLength={1000} rows={2} /></div>
          <div className="space-y-2"><Label htmlFor="deviceBrand">Merek perangkat</Label><Input id="deviceBrand" name="deviceBrand" required maxLength={80} /></div>
          <div className="space-y-2"><Label htmlFor="deviceModel">Model perangkat</Label><Input id="deviceModel" name="deviceModel" required maxLength={120} /></div>
          <div className="space-y-2 sm:col-span-2"><Label htmlFor="imeiOrSerial">IMEI atau nomor seri <span className="font-normal text-muted-foreground">(opsional)</span></Label><Input id="imeiOrSerial" name="imeiOrSerial" maxLength={120} /></div>
          <div className="space-y-2 sm:col-span-2"><Label htmlFor="physicalCondition">Kondisi fisik saat diterima <span className="font-normal text-muted-foreground">(opsional)</span></Label><Textarea id="physicalCondition" name="physicalCondition" maxLength={2000} rows={2} /></div>
        </div>
      </section>
      <section className="rounded-2xl border bg-card p-5 sm:p-6">
        <h2 className="text-base font-semibold">Kebutuhan servis</h2>
        <div className="mt-5 grid gap-5 sm:grid-cols-2">
          <div className="space-y-2 sm:col-span-2"><Label htmlFor="initialComplaint">Keluhan awal</Label><Textarea id="initialComplaint" name="initialComplaint" required minLength={5} maxLength={2000} rows={4} /></div>
          <div className="space-y-2 sm:col-span-2"><Label htmlFor="customerNeeds">Kebutuhan pelanggan <span className="font-normal text-muted-foreground">(opsional)</span></Label><Textarea id="customerNeeds" name="customerNeeds" maxLength={1000} rows={3} /></div>
          <div className="space-y-2"><Label htmlFor="branchId">Cabang</Label><select id="branchId" name="branchId" required defaultValue={branches.length === 1 ? branches[0].id : ""} className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="" disabled>Pilih cabang</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></div>
          <div className="space-y-2"><Label htmlFor="serviceCategory">Kategori servis</Label><Input id="serviceCategory" name="serviceCategory" defaultValue="general" required maxLength={50} /></div>
          <div className="space-y-2"><Label htmlFor="serviceComplexity">Kompleksitas</Label><select id="serviceComplexity" name="serviceComplexity" defaultValue="light" className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="light">Ringan · SLA 1 hari kerja</option><option value="medium">Sedang · SLA 3 hari kerja</option><option value="heavy">Berat · SLA 7 hari kerja</option></select></div>
        </div>
      </section>
      <Feedback state={state} />
      <div className="flex justify-end"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <ArrowRightIcon />}{pending ? "Menyimpan..." : "Buat tiket servis"}</Button></div>
    </form>
  );
}

export function TicketStatusForm({ ticketId, rowVersion, options }: { ticketId: string; rowVersion: number; options: Array<{ status: string; label: string }> }) {
  const [state, action, pending] = useActionState(updateTicketStatus, initialState);
  if (!options.length) return null;
  return (
    <form action={action} className="rounded-2xl border bg-card p-5">
      <input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} />
      <h2 className="text-base font-semibold">Perbarui alur servis</h2>
      <div className="mt-4 space-y-2"><Label htmlFor="note">Catatan tindakan</Label><Textarea id="note" name="note" rows={3} maxLength={1000} placeholder="Jelaskan hasil diagnosis atau tindakan penting" /></div>
      <div className="mt-4 flex flex-wrap gap-2">{options.map((option) => <Button key={option.status} type="submit" name="nextStatus" value={option.status} variant={option.status.startsWith("CANCELLED") ? "outline" : "default"} disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <ArrowRightIcon />}{option.label}</Button>)}</div>
      <div className="mt-3"><Feedback state={state} /></div>
    </form>
  );
}

export function PaymentForm({ ticketId, rowVersion, defaultValue }: { ticketId: string; rowVersion: number; defaultValue: string }) {
  const [state, action, pending] = useActionState(recordPayment, initialState);
  return (
    <form action={action} className="rounded-2xl border bg-card p-5">
      <input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} />
      <h2 className="flex items-center gap-2 text-base font-semibold"><CurrencyCircleDollarIcon className="text-primary" /> Pembayaran</h2>
      <div className="mt-4 flex gap-2"><div className="min-w-0 flex-1"><Label htmlFor="paidAmount" className="sr-only">Nominal dibayar</Label><Input id="paidAmount" name="paidAmount" type="number" inputMode="numeric" min={0} max={1_000_000_000} defaultValue={defaultValue} required /></div><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Simpan</Button></div>
      <div className="mt-3"><Feedback state={state} /></div>
    </form>
  );
}

export function TechnicianAssignmentForm({ ticketId, rowVersion, technicians, currentId }: { ticketId: string; rowVersion: number; technicians: Array<{ id: string; name: string }>; currentId?: string }) {
  const [state, action, pending] = useActionState(assignTechnician, initialState);
  return <form action={action} className="rounded-2xl border bg-card p-5"><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><h2 className="text-base font-semibold">Penugasan Teknisi</h2><div className="mt-4 space-y-2"><Label htmlFor="technicianEmployeeId">Teknisi</Label><select id="technicianEmployeeId" name="technicianEmployeeId" defaultValue={currentId ?? ""} required className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="" disabled>Pilih Teknisi</option>{technicians.map((technician) => <option key={technician.id} value={technician.id}>{technician.name}</option>)}</select></div><div className="mt-4 space-y-2"><Label htmlFor="assignmentReason">Alasan penugasan</Label><Textarea id="assignmentReason" name="reason" required minLength={3} maxLength={1000} rows={2} /></div><div className="mt-4"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Simpan penugasan</Button></div><div className="mt-3"><Feedback state={state} /></div></form>;
}

export function TicketProgressForm({ ticketId, rowVersion, diagnosisNotes, actionNotes }: { ticketId: string; rowVersion: number; diagnosisNotes?: string; actionNotes?: string }) {
  const [state, action, pending] = useActionState(saveTicketProgress, initialState);
  return <form action={action} className="rounded-2xl border bg-card p-5"><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><h2 className="text-base font-semibold">Catatan teknis</h2><div className="mt-4 space-y-2"><Label htmlFor="diagnosisNotes">Diagnosis</Label><Textarea id="diagnosisNotes" name="diagnosisNotes" defaultValue={diagnosisNotes} maxLength={5000} rows={3} /></div><div className="mt-4 space-y-2"><Label htmlFor="actionNotes">Tindakan</Label><Textarea id="actionNotes" name="actionNotes" defaultValue={actionNotes} maxLength={5000} rows={3} /></div><div className="mt-4"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Simpan catatan</Button></div><div className="mt-3"><Feedback state={state} /></div></form>;
}

export function TechnicalEvidenceForm({ ticketId, rowVersion }: { ticketId: string; rowVersion: number }) {
  const [state, action, pending] = useActionState(addTechnicalEvidence, initialState);
  return <form action={action} className="rounded-2xl border bg-card p-5"><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><h2 className="text-base font-semibold">Tambah evidence teknis</h2><div className="mt-4 grid gap-4 sm:grid-cols-2"><div className="space-y-2"><Label htmlFor="evidenceType">Jenis</Label><Input id="evidenceType" name="type" required maxLength={50} placeholder="Contoh: foto diagnosis" /></div><div className="space-y-2"><Label htmlFor="evidenceReference">Referensi</Label><Input id="evidenceReference" name="reference" required maxLength={1000} placeholder="Nomor foto atau dokumen" /></div></div><div className="mt-4 space-y-2"><Label htmlFor="evidenceNote">Catatan <span className="font-normal text-muted-foreground">(opsional)</span></Label><Textarea id="evidenceNote" name="note" maxLength={2000} rows={2} /></div><div className="mt-4"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Tambah evidence</Button></div><div className="mt-3"><Feedback state={state} /></div></form>;
}

export function ConsentForm({ ticketId, rowVersion, defaultStatus }: { ticketId: string; rowVersion: number; defaultStatus: string }) {
  const [state, action, pending] = useActionState(recordCustomerConsent, initialState);
  return <form action={action} className="rounded-2xl border bg-card p-5"><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><h2 className="text-base font-semibold">Persetujuan pelanggan</h2><div className="mt-4 space-y-2"><Label htmlFor="consentStatus">Keputusan</Label><select id="consentStatus" name="consentStatus" defaultValue={["approved", "declined"].includes(defaultStatus) ? defaultStatus : ""} required className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="" disabled>Pilih keputusan</option><option value="approved">Disetujui</option><option value="declined">Ditolak</option></select></div><div className="mt-4 space-y-2"><Label htmlFor="consentNotes">Catatan</Label><Textarea id="consentNotes" name="notes" maxLength={2000} rows={2} placeholder="Wajib jika pelanggan menolak" /></div><div className="mt-4"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Simpan persetujuan</Button></div><div className="mt-3"><Feedback state={state} /></div></form>;
}

export function TicketCostForm({ ticketId, rowVersion, kind, defaultValue }: { ticketId: string; rowVersion: number; kind: "estimated" | "final"; defaultValue: string }) {
  const [state, action, pending] = useActionState(recordTicketCost, initialState);
  const label = kind === "final" ? "Biaya final" : "Estimasi biaya";
  return <form action={action} className="rounded-2xl border bg-card p-5"><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><input type="hidden" name="kind" value={kind} /><h2 className="text-base font-semibold">{label}</h2><div className="mt-4 space-y-2"><Label htmlFor={`cost-${kind}`}>Nominal</Label><Input id={`cost-${kind}`} name="amount" type="number" inputMode="numeric" min={0} max={1_000_000_000} defaultValue={defaultValue} required /></div><div className="mt-4 space-y-2"><Label htmlFor={`cost-note-${kind}`}>Catatan perubahan</Label><Textarea id={`cost-note-${kind}`} name="note" required minLength={3} maxLength={2000} rows={2} /></div><div className="mt-4"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Simpan {label.toLowerCase()}</Button></div><div className="mt-3"><Feedback state={state} /></div></form>;
}

const qcLabels = { display: "Layar", touch: "Sentuh", camera: "Kamera", mic: "Mikrofon", speaker: "Speaker", cellular: "Seluler", charging: "Pengisian", biometric: "Biometrik" } as const;

export function TicketCompletionForm({ ticketId, rowVersion, diagnosisNotes, actionNotes }: { ticketId: string; rowVersion: number; diagnosisNotes?: string; actionNotes?: string }) {
  const [state, action, pending] = useActionState(completeTicket, initialState);
  return <form action={action} className="rounded-2xl border bg-card p-5"><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><h2 className="text-base font-semibold">Penyelesaian teknis</h2><div className="mt-4 space-y-2"><Label htmlFor="resultStatus">Hasil servis</Label><select id="resultStatus" name="resultStatus" defaultValue="success" required className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="success">Berhasil</option><option value="unrepairable">Tidak dapat diperbaiki</option><option value="customer_declined">Pelanggan menolak</option></select></div><div className="mt-4 grid gap-4 sm:grid-cols-2"><div className="space-y-2"><Label htmlFor="completeDiagnosis">Diagnosis final</Label><Textarea id="completeDiagnosis" name="diagnosisNotes" required minLength={3} maxLength={5000} rows={3} defaultValue={diagnosisNotes} /></div><div className="space-y-2"><Label htmlFor="completeAction">Tindakan final</Label><Textarea id="completeAction" name="actionNotes" required minLength={3} maxLength={5000} rows={3} defaultValue={actionNotes} /></div></div><fieldset className="mt-4"><legend className="text-sm font-medium">Checklist QC</legend><div className="mt-2 grid gap-1 sm:grid-cols-2">{Object.entries(qcLabels).map(([key, label]) => <label key={key} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-2 text-sm hover:bg-accent"><input type="checkbox" name={key} className="size-4 accent-primary" />{label} lulus</label>)}</div></fieldset><div className="mt-4 grid gap-4 sm:grid-cols-2"><div className="space-y-2"><Label htmlFor="technicalEvidence">Referensi evidence</Label><Input id="technicalEvidence" name="technicalEvidence" maxLength={1000} placeholder="Nomor foto atau referensi dokumen" /></div><div className="space-y-2"><Label htmlFor="technicalEvidenceFile">File evidence</Label><Input id="technicalEvidenceFile" name="technicalEvidenceFile" type="file" accept=".jpg,.jpeg,.png,.webp,.pdf,image/jpeg,image/png,image/webp,application/pdf" /><p className="text-xs text-muted-foreground">JPG, PNG, WEBP, atau PDF; maksimal 10 MB.</p></div></div><div className="mt-4 grid gap-4 sm:grid-cols-2"><div className="space-y-2"><Label htmlFor="unrepairableReason">Alasan tidak dapat diperbaiki</Label><Textarea id="unrepairableReason" name="unrepairableReason" maxLength={2000} rows={2} /></div><div className="space-y-2"><Label htmlFor="customerDeclinedReason">Alasan pelanggan menolak</Label><Textarea id="customerDeclinedReason" name="customerDeclinedReason" maxLength={2000} rows={2} /></div></div><div className="mt-4"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Selesaikan teknis</Button></div><div className="mt-3"><Feedback state={state} /></div></form>;
}

export function DeliveryForm({ ticketId, rowVersion, customerName }: { ticketId: string; rowVersion: number; customerName: string }) {
  const [state, action, pending] = useActionState(deliverTicket, initialState);
  return <form action={action} className="rounded-2xl border bg-card p-5"><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><h2 className="text-base font-semibold">Penyerahan perangkat</h2><div className="mt-4 space-y-2"><Label htmlFor="recipientType">Penerima</Label><select id="recipientType" name="recipientType" defaultValue="customer" className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="customer">Pelanggan: {customerName}</option><option value="representative">Perwakilan pelanggan</option></select></div><div className="mt-4 space-y-2"><Label htmlFor="recipientName">Nama penerima lain</Label><Input id="recipientName" name="recipientName" maxLength={120} /></div><div className="mt-4 space-y-2"><Label htmlFor="deliveryNotes">Catatan penyerahan</Label><Textarea id="deliveryNotes" name="deliveryNotes" maxLength={2000} rows={2} /></div><div className="mt-4"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Serahkan perangkat</Button></div><div className="mt-3"><Feedback state={state} /></div></form>;
}

export function PaymentExceptionForm({ ticketId, rowVersion }: { ticketId: string; rowVersion: number }) {
  const [state, action, pending] = useActionState(approvePaymentException, initialState);
  return <form action={action} className="rounded-2xl border bg-card p-5"><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><h2 className="text-base font-semibold">Pengecualian pembayaran</h2><div className="mt-4 space-y-2"><Label htmlFor="exceptionType">Jenis</Label><select id="exceptionType" name="exceptionType" defaultValue="" required className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="" disabled>Pilih jenis</option><option value="installment">Cicilan</option><option value="receivable">Piutang</option><option value="waiver">Pembebasan</option></select></div><div className="mt-4 space-y-2"><Label htmlFor="exceptionReason">Alasan Manager</Label><Textarea id="exceptionReason" name="reason" required minLength={3} maxLength={2000} rows={2} /></div><div className="mt-4"><Button type="submit" variant="outline" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Sahkan pengecualian</Button></div><div className="mt-3"><Feedback state={state} /></div></form>;
}

export function SparepartRequestForm({ ticketId, rowVersion, spareparts }: { ticketId: string; rowVersion: number; spareparts: Array<{ id: string; code: string; name: string; stockQuantity: number }> }) {
  const [state, action, pending] = useActionState(requestSparepart, initialState);
  return <form action={action} className="rounded-xl border bg-muted/30 p-4"><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><h3 className="text-sm font-semibold">Minta sparepart</h3><div className="mt-3 grid gap-3 sm:grid-cols-[1fr_7rem]"><div><Label htmlFor="sparepartId" className="sr-only">Sparepart</Label><select id="sparepartId" name="sparepartId" defaultValue="" required className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="" disabled>Pilih sparepart</option>{spareparts.map((part) => <option key={part.id} value={part.id}>{part.code} · {part.name} · stok {part.stockQuantity}</option>)}</select></div><div><Label htmlFor="partQuantity" className="sr-only">Jumlah</Label><Input id="partQuantity" name="quantity" type="number" inputMode="numeric" min={1} max={1000} defaultValue={1} required /></div></div><div className="mt-3"><Label htmlFor="partNotes" className="sr-only">Catatan permintaan</Label><Textarea id="partNotes" name="notes" maxLength={2000} rows={2} placeholder="Catatan permintaan (opsional)" /></div><div className="mt-3"><Button type="submit" size="sm" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Kirim permintaan</Button></div><div className="mt-3"><Feedback state={state} /></div></form>;
}

export function SparepartDecisionForm({ requestId, ticketId, rowVersion }: { requestId: string; ticketId: string; rowVersion: number }) {
  const [state, action, pending] = useActionState(decideSparepartRequest, initialState);
  return <form action={action} className="mt-3 space-y-2"><input type="hidden" name="requestId" value={requestId} /><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><Label htmlFor={`availability-${requestId}`} className="sr-only">Catatan ketersediaan</Label><Input id={`availability-${requestId}`} name="note" maxLength={2000} placeholder="Alasan jika stok tidak tersedia" /><div className="flex flex-wrap gap-2"><Button type="submit" size="sm" name="decision" value="fulfilled" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Penuhi</Button><Button type="submit" size="sm" variant="outline" name="decision" value="unavailable" disabled={pending}>Tidak tersedia</Button></div><Feedback state={state} /></form>;
}

export function SparepartConfirmForm({ requestId, ticketId, rowVersion }: { requestId: string; ticketId: string; rowVersion: number }) {
  const [state, action, pending] = useActionState(confirmSparepart, initialState);
  return <form action={action} className="mt-3"><input type="hidden" name="requestId" value={requestId} /><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><Button type="submit" size="sm" variant="outline" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Konfirmasi diterima</Button><div className="mt-2"><Feedback state={state} /></div></form>;
}

export function WarrantyReturnForm({ ticketId, rowVersion }: { ticketId: string; rowVersion: number }) {
  const [state, action, pending] = useActionState(createWarrantyReturn, initialState);
  const router = useRouter();
  useEffect(() => { if (state.ticketId) router.replace(`/app/servis/${state.ticketId}`); }, [router, state.ticketId]);
  return <form action={action} className="rounded-2xl border bg-card p-5"><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><h2 className="text-base font-semibold">Buat retur garansi</h2><div className="mt-4 space-y-2"><Label htmlFor="warrantyReturnReason">Keluhan retur</Label><Textarea id="warrantyReturnReason" name="reason" required minLength={3} maxLength={2000} rows={3} /></div><div className="mt-4"><Button type="submit" variant="outline" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Buat tiket retur</Button></div><div className="mt-3"><Feedback state={state} /></div></form>;
}

export function WarrantyReviewForm({ ticketId, rowVersion }: { ticketId: string; rowVersion: number }) {
  const [state, action, pending] = useActionState(reviewWarrantyReturn, initialState);
  return <form action={action} className="rounded-2xl border bg-card p-5"><input type="hidden" name="ticketId" value={ticketId} /><input type="hidden" name="rowVersion" value={rowVersion} /><h2 className="text-base font-semibold">Validasi retur garansi</h2><div className="mt-4 space-y-2"><Label htmlFor="warrantyReviewReason">Alasan keputusan</Label><Textarea id="warrantyReviewReason" name="reason" required minLength={3} maxLength={2000} rows={3} /></div><div className="mt-4 flex flex-wrap gap-2"><Button type="submit" name="decision" value="approved" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : null}Setujui</Button><Button type="submit" name="decision" value="rejected" variant="outline" disabled={pending}>Tolak</Button></div><div className="mt-3"><Feedback state={state} /></div></form>;
}
