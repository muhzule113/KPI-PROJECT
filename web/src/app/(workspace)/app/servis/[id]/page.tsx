import { ArrowLeftIcon, CalendarDotsIcon, CurrencyCircleDollarIcon, DeviceMobileIcon, PaperclipIcon, PhoneIcon, UserIcon, WrenchIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ConsentForm, DeliveryForm, PaymentExceptionForm, PaymentForm, SparepartConfirmForm, SparepartDecisionForm, SparepartRequestForm, TechnicalEvidenceForm, TechnicianAssignmentForm, TicketCompletionForm, TicketCostForm, TicketProgressForm, TicketStatusForm, WarrantyReturnForm, WarrantyReviewForm } from "@/components/tickets/ticket-forms";
import { FeedbackShareLink } from "@/components/tickets/customer-feedback-form";
import { StatusBadge } from "@/components/status-badge";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatDate, formatMoney } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { canAccessTicket } from "@/modules/access/scope";
import { capabilityForTransition, TICKET_TRANSITIONS } from "@/modules/tickets/workflow";
import { createFeedbackToken } from "@/modules/tickets/feedback-token";
import { createEvidenceDownloadToken } from "@/modules/files/evidence-upload";

export const metadata: Metadata = { title: "Rincian servis" };

const transitionLabels: Record<string, string> = {
  DIAGNOSING: "Mulai diagnosis",
  WAITING_CONSENT: "Minta persetujuan pelanggan",
  WAITING_SPAREPART: "Tunggu sparepart",
  IN_PROGRESS: "Mulai pengerjaan",
  QC_READY: "Ajukan QC",
  COMPLETED: "Nyatakan selesai",
  DELIVERED: "Serahkan perangkat",
  CANCELLED_UNREPAIRABLE: "Tidak dapat diperbaiki",
  CANCELLED: "Batalkan tiket",
};

export default async function TicketDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.view")) notFound();
  const { id } = await params;
  const ticket = await prisma.serviceTicket.findUnique({
    where: { id },
    include: {
      branch: true,
      period: true,
      intakeBy: { select: { name: true, supervisorId: true } },
      technician: { select: { name: true, supervisorId: true } },
      paymentRecordedBy: { select: { name: true } },
      deliveredBy: { select: { name: true } },
      sparepartRequests: { orderBy: { requestedAt: "desc" }, include: { sparepart: true } },
      feedback: true,
    },
  });
  if (!ticket || !canAccessTicket(user, ticket)) notFound();
  const canAssign = (hasCapability(user, "tickets.supervise") || hasCapability(user, "tickets.manage")) && !["COMPLETED", "DELIVERED", "CANCELLED", "CANCELLED_UNREPAIRABLE"].includes(ticket.status);
  const technicians = canAssign ? await prisma.employee.findMany({ where: { branchId: ticket.branchId, status: "ACTIVE", supervisorId: user.role === "supervisor" ? user.employee?.id : undefined, position: { code: "POS-TEK", isActive: true } }, orderBy: { name: "asc" }, select: { id: true, name: true } }) : [];
  const canRequestPart = hasCapability(user, "spareparts.request") && ticket.technicianEmployeeId === user.employee?.id && ["DIAGNOSING", "WAITING_SPAREPART", "IN_PROGRESS"].includes(ticket.status) && (!ticket.isWarrantyReturn || ticket.warrantyReviewStatus === "approved");
  const spareparts = canRequestPart ? await prisma.sparepart.findMany({ where: { productType: "sparepart", OR: [{ branchId: ticket.branchId }, { branchId: null }] }, orderBy: [{ category: "asc" }, { name: "asc" }], select: { id: true, code: true, name: true, stockQuantity: true } }) : [];
  const canFulfillPart = hasCapability(user, "spareparts.fulfill") || hasCapability(user, "tickets.manage");
  const canConfirmPart = hasCapability(user, "spareparts.confirm") && ticket.technicianEmployeeId === user.employee?.id;
  const options = (TICKET_TRANSITIONS[ticket.status] ?? [])
    .filter((status) => !["COMPLETED", "DELIVERED"].includes(status))
    .filter((status) => status !== "DIAGNOSING" || user.employee?.position.code === "POS-TEK")
    .filter((status) => hasCapability(user, capabilityForTransition(ticket.status, status)) || hasCapability(user, "tickets.manage"))
    .map((status) => ({ status, label: transitionLabels[status] ?? status }));
  const technicalOwner = hasCapability(user, "tickets.progress") && ticket.technicianEmployeeId === user.employee?.id && (!ticket.isWarrantyReturn || ticket.warrantyReviewStatus === "approved");
  const canProgress = technicalOwner && ["DIAGNOSING", "WAITING_CONSENT", "WAITING_SPAREPART", "IN_PROGRESS"].includes(ticket.status);
  const canAddEvidence = technicalOwner && hasCapability(user, "tickets.evidence") && !["COMPLETED", "DELIVERED", "CANCELLED", "CANCELLED_UNREPAIRABLE"].includes(ticket.status);
  const canConsent = (hasCapability(user, "tickets.manage") || (hasCapability(user, "tickets.consent") && ticket.intakeByEmployeeId === user.employee?.id)) && !["COMPLETED", "DELIVERED", "CANCELLED", "CANCELLED_UNREPAIRABLE"].includes(ticket.status);
  const canCost = hasCapability(user, "tickets.cost") || hasCapability(user, "tickets.manage");
  const canComplete = technicalOwner && (TICKET_TRANSITIONS[ticket.status] ?? []).includes("COMPLETED");
  const canDeliver = ticket.status === "COMPLETED" && (hasCapability(user, "tickets.deliver") || hasCapability(user, "tickets.supervise") || hasCapability(user, "tickets.manage"));
  const canPay = ticket.status === "COMPLETED" && (hasCapability(user, "tickets.payment") || hasCapability(user, "tickets.manage"));
  const canApprovePaymentException = ticket.status === "COMPLETED" && hasCapability(user, "tickets.manage") && ["owner_manager", "super_admin"].includes(user.role);
  const canCreateWarrantyReturn = ticket.status === "DELIVERED" && Boolean(ticket.warrantyExpiresAt && ticket.warrantyExpiresAt >= new Date()) && (hasCapability(user, "tickets.create") || hasCapability(user, "tickets.manage"));
  const canReviewWarranty = ticket.isWarrantyReturn && ticket.status === "INTAKE" && ticket.warrantyReviewStatus === "pending" && (hasCapability(user, "tickets.supervise") || hasCapability(user, "tickets.manage"));
  const bill = ticket.finalCost.gt(0) ? ticket.finalCost : ticket.estimatedCost;
  const canShareFeedback = hasCapability(user, "tickets.feedback-link") && ticket.status === "DELIVERED" && ticket.deliveredAt && new Date() <= new Date(ticket.deliveredAt.valueOf() + 7 * 86_400_000) && !ticket.feedback;
  const feedbackHref = canShareFeedback ? `/feedback/${createFeedbackToken(ticket.id, new Date(ticket.deliveredAt!.valueOf() + 7 * 86_400_000))}` : null;
  const evidenceExpiresAt = new Date(new Date().valueOf() + 5 * 60_000);
  const technicalEvidence = (Array.isArray(ticket.technicalEvidenceJson) ? ticket.technicalEvidenceJson : []).flatMap((raw, index) => {
    if (raw === null || Array.isArray(raw) || typeof raw !== "object") return [];
    return [{ index, type: String(raw.type ?? "evidence"), reference: String(raw.reference ?? ""), fileName: typeof raw.file_name === "string" ? raw.file_name : null, scanStatus: String(raw.scan_status ?? ""), hash: typeof raw.sha256_hash === "string" ? raw.sha256_hash : null }];
  });

  return (
    <div className="space-y-6">
      <Button asChild variant="link"><Link href="/app/servis"><ArrowLeftIcon /> Kembali ke tiket servis</Link></Button>
      <section className="rounded-2xl border bg-card p-5 sm:p-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div><p className="font-mono text-xs font-semibold text-primary">{ticket.ticketNumber}</p><h1 className="mt-1 text-2xl font-semibold tracking-tight">{ticket.deviceBrand} {ticket.deviceModel}</h1><p className="mt-1 text-sm text-muted-foreground">Diterima {formatDate(ticket.createdAt)} di {ticket.branch.name}</p>{ticket.isWarrantyReturn ? <div className="mt-2 flex flex-wrap items-center gap-2"><Badge variant="secondary">Retur garansi</Badge><StatusBadge status={ticket.warrantyReviewStatus} /></div> : null}</div><StatusBadge status={ticket.status} />
        </div>
        <div className="mt-6 h-2 overflow-hidden rounded-full bg-muted"><div className="h-full bg-primary" style={{ width: `${Math.max(8, (["INTAKE", "DIAGNOSING", "WAITING_CONSENT", "WAITING_SPAREPART", "IN_PROGRESS", "QC_READY", "COMPLETED", "DELIVERED"].indexOf(ticket.status) + 1) * 12.5)}%` }} /></div>
      </section>

      <div className="grid gap-5 lg:grid-cols-[1.2fr_0.8fr]">
        <div className="space-y-5">
          <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><WrenchIcon className="text-primary" /> Keluhan dan penanganan</CardTitle></CardHeader><CardContent className="space-y-4 text-sm"><div><p className="text-xs text-muted-foreground">Keluhan awal</p><p className="mt-1 whitespace-pre-wrap leading-6">{ticket.initialComplaint}</p></div>{ticket.customerNeeds ? <div><p className="text-xs text-muted-foreground">Kebutuhan pelanggan</p><p className="mt-1 whitespace-pre-wrap leading-6">{ticket.customerNeeds}</p></div> : null}{ticket.physicalCondition ? <div><p className="text-xs text-muted-foreground">Kondisi fisik saat diterima</p><p className="mt-1 whitespace-pre-wrap leading-6">{ticket.physicalCondition}</p></div> : null}{ticket.diagnosisNotes ? <div><p className="text-xs text-muted-foreground">Diagnosis</p><p className="mt-1 whitespace-pre-wrap leading-6">{ticket.diagnosisNotes}</p></div> : null}{ticket.actionNotes ? <div><p className="text-xs text-muted-foreground">Catatan tindakan</p><p className="mt-1 whitespace-pre-wrap leading-6">{ticket.actionNotes}</p></div> : null}<div className="flex flex-wrap items-center gap-2"><span className="text-xs text-muted-foreground">Persetujuan pelanggan</span><StatusBadge status={ticket.customerConsentStatus} />{ticket.resultStatus !== "pending" ? <StatusBadge status={ticket.resultStatus} /> : null}</div></CardContent></Card>
          <Card>
            <CardHeader><CardTitle className="text-base">Sparepart</CardTitle></CardHeader>
            <CardContent className="space-y-4">
              {ticket.sparepartRequests.length ? <div className="divide-y">{ticket.sparepartRequests.map((request) => <div key={request.id} className="py-3 text-sm"><div className="flex items-start justify-between gap-4"><div><p className="font-medium">{request.sparepart.name}</p><p className="text-xs text-muted-foreground">{request.quantity} unit · diminta {formatDate(request.requestedAt)}</p>{request.availabilityNote ? <p className="mt-1 text-xs text-muted-foreground">{request.availabilityNote}</p> : null}</div><Badge variant="secondary">{request.status}</Badge></div>{canFulfillPart && request.status === "pending" ? <SparepartDecisionForm requestId={request.id} ticketId={ticket.id} rowVersion={ticket.rowVersion} /> : null}{canConfirmPart && request.status === "fulfilled" && !request.confirmedAt ? <SparepartConfirmForm requestId={request.id} ticketId={ticket.id} rowVersion={ticket.rowVersion} /> : null}</div>)}</div> : <p className="text-sm text-muted-foreground">Belum ada permintaan sparepart.</p>}
              {canRequestPart && spareparts.length ? <SparepartRequestForm ticketId={ticket.id} rowVersion={ticket.rowVersion} spareparts={spareparts} /> : null}
            </CardContent>
          </Card>
        </div>

        <div className="space-y-5">
          <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><UserIcon className="text-primary" /> Pelanggan</CardTitle></CardHeader><CardContent className="space-y-4 text-sm"><div><p className="text-xs text-muted-foreground">Nama</p><p className="mt-1 font-medium">{ticket.customerName}</p></div><div><p className="text-xs text-muted-foreground">Telepon</p><a href={`tel:${ticket.customerPhone}`} className="mt-1 inline-flex min-h-11 items-center gap-2 font-medium text-primary hover:underline"><PhoneIcon /> {ticket.customerPhone}</a></div>{ticket.imeiOrSerial ? <div><p className="text-xs text-muted-foreground">IMEI atau nomor seri</p><p className="mt-1 font-mono text-xs">{ticket.imeiOrSerial}</p></div> : null}</CardContent></Card>
          <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><DeviceMobileIcon className="text-primary" /> Penanggung jawab</CardTitle></CardHeader><CardContent className="space-y-3 text-sm"><div className="flex justify-between gap-4"><span className="text-muted-foreground">Penerima</span><span className="text-right font-medium">{ticket.intakeBy?.name ?? "Tidak tercatat"}</span></div><div className="flex justify-between gap-4"><span className="text-muted-foreground">Teknisi</span><span className="text-right font-medium">{ticket.technician?.name ?? "Belum ditugaskan"}</span></div>{ticket.estimatedCompletionAt ? <div className="flex justify-between gap-4"><span className="flex items-center gap-1 text-muted-foreground"><CalendarDotsIcon /> Perkiraan selesai</span><span className="text-right font-medium">{formatDate(ticket.estimatedCompletionAt)}</span></div> : null}</CardContent></Card>
          <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><CurrencyCircleDollarIcon className="text-primary" /> Ringkasan biaya</CardTitle></CardHeader><CardContent className="space-y-3 text-sm"><div className="flex justify-between gap-4"><span className="text-muted-foreground">Estimasi</span><span className="font-semibold">{formatMoney(ticket.estimatedCost.toString())}</span></div><div className="flex justify-between gap-4"><span className="text-muted-foreground">Biaya final</span><span className="font-semibold">{formatMoney(ticket.finalCost.toString())}</span></div><div className="flex justify-between gap-4"><span className="text-muted-foreground">Tagihan aktif</span><span className="font-semibold">{formatMoney(bill.toString())}</span></div><div className="flex justify-between gap-4"><span className="text-muted-foreground">Dibayar</span><span className="font-semibold">{formatMoney(ticket.paidAmount.toString())}</span></div><div className="flex items-center justify-between gap-4"><span className="text-muted-foreground">Status</span><StatusBadge status={ticket.paymentStatus} /></div></CardContent></Card>
        </div>
      </div>

      {technicalEvidence.length ? <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><PaperclipIcon className="text-primary" /> Evidence teknis</CardTitle></CardHeader><CardContent className="grid gap-3 sm:grid-cols-2">{technicalEvidence.map((evidence) => <div key={evidence.index} className="rounded-xl border p-4 text-sm"><div className="flex items-start justify-between gap-3"><div className="min-w-0"><p className="font-medium">{evidence.reference || evidence.fileName || `Evidence ${evidence.index + 1}`}</p><p className="mt-1 text-xs text-muted-foreground">{evidence.type}{evidence.hash ? ` · SHA-256 ${evidence.hash.slice(0, 12)}…` : ""}</p></div><Badge variant={evidence.scanStatus === "clean" ? "default" : "warning"}>{evidence.scanStatus || "referensi"}</Badge></div>{evidence.fileName && evidence.scanStatus === "clean" ? <Button asChild size="sm" variant="outline" className="mt-3"><a href={`/api/service-tickets/${ticket.id}/evidence/${evidence.index}?token=${encodeURIComponent(createEvidenceDownloadToken(ticket.id, evidence.index, evidenceExpiresAt))}`}><PaperclipIcon /> Unduh {evidence.fileName}</a></Button> : null}</div>)}</CardContent></Card> : null}

      <div className="grid gap-5 lg:grid-cols-2">
        {canAssign && technicians.length ? <TechnicianAssignmentForm ticketId={ticket.id} rowVersion={ticket.rowVersion} technicians={technicians} currentId={ticket.technicianEmployeeId ?? undefined} /> : null}
        {options.length ? <TicketStatusForm ticketId={ticket.id} rowVersion={ticket.rowVersion} options={options} /> : null}
        {canProgress ? <TicketProgressForm ticketId={ticket.id} rowVersion={ticket.rowVersion} diagnosisNotes={ticket.diagnosisNotes ?? undefined} actionNotes={ticket.actionNotes ?? undefined} /> : null}
        {canAddEvidence ? <TechnicalEvidenceForm ticketId={ticket.id} rowVersion={ticket.rowVersion} /> : null}
        {canConsent ? <ConsentForm ticketId={ticket.id} rowVersion={ticket.rowVersion} defaultStatus={ticket.customerConsentStatus} /> : null}
        {canCost && ticket.status !== "COMPLETED" && !["DELIVERED", "CANCELLED", "CANCELLED_UNREPAIRABLE"].includes(ticket.status) ? <TicketCostForm ticketId={ticket.id} rowVersion={ticket.rowVersion} kind="estimated" defaultValue={ticket.estimatedCost.toString()} /> : null}
        {canComplete ? <TicketCompletionForm ticketId={ticket.id} rowVersion={ticket.rowVersion} diagnosisNotes={ticket.diagnosisNotes ?? undefined} actionNotes={ticket.actionNotes ?? undefined} /> : null}
        {canCost && ticket.status === "COMPLETED" ? <TicketCostForm ticketId={ticket.id} rowVersion={ticket.rowVersion} kind="final" defaultValue={ticket.finalCost.toString()} /> : null}
        {canPay ? <PaymentForm ticketId={ticket.id} rowVersion={ticket.rowVersion} defaultValue={ticket.paidAmount.toString()} /> : null}
        {canApprovePaymentException ? <PaymentExceptionForm ticketId={ticket.id} rowVersion={ticket.rowVersion} /> : null}
        {canDeliver ? <DeliveryForm ticketId={ticket.id} rowVersion={ticket.rowVersion} customerName={ticket.customerName} /> : null}
        {canCreateWarrantyReturn ? <WarrantyReturnForm ticketId={ticket.id} rowVersion={ticket.rowVersion} /> : null}
        {canReviewWarranty ? <WarrantyReviewForm ticketId={ticket.id} rowVersion={ticket.rowVersion} /> : null}
      </div>
      {feedbackHref ? <FeedbackShareLink href={feedbackHref} /> : null}
    </div>
  );
}
