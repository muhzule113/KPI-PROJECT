"use server";

import { createHash, randomUUID } from "node:crypto";
import { mkdir, unlink, writeFile } from "node:fs/promises";
import { join } from "node:path";
import { revalidatePath } from "next/cache";
import { z } from "zod";
import type { Prisma } from "@/generated/prisma/client";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { canAccessTicket } from "@/modules/access/scope";
import { MAX_EVIDENCE_BYTES, scanEvidenceFile, validateEvidenceFile } from "@/modules/files/evidence-upload";
import { assertExpectedVersion } from "@/modules/kpi/workflow";
import { syncEmployeeOperationalKpis } from "@/modules/kpi/operational-sync";
import { addWeekdays, assertTechnicalEvidenceCanBeAdded, assertTicketTransition, assertTicketTransitionPrerequisites, capabilityForTransition, SERVICE_SLA_WORKDAYS, totalSparepartWaitMinutes } from "@/modules/tickets/workflow";

export type TicketActionState = { error?: string; success?: string; ticketId?: string };

const createSchema = z.object({
  branchId: z.string().min(1),
  customerName: z.string().trim().min(2).max(120),
  customerPhone: z.string().trim().min(7).max(30),
  customerAddress: z.string().trim().max(1000).optional(),
  deviceBrand: z.string().trim().min(1).max(80),
  deviceModel: z.string().trim().min(1).max(120),
  imeiOrSerial: z.string().trim().max(120).optional(),
  physicalCondition: z.string().trim().max(2000).optional(),
  initialComplaint: z.string().trim().min(5).max(2000),
  customerNeeds: z.string().trim().max(1000).optional(),
  serviceCategory: z.string().trim().min(1).max(50).default("general"),
  serviceComplexity: z.enum(["light", "medium", "heavy"]).default("light"),
});

const safeMessage = (error: unknown) => error instanceof Error && !error.message.toLowerCase().includes("prisma") ? error.message : "Data servis tidak dapat disimpan.";

async function lockedTicket(tx: Prisma.TransactionClient, id: string) {
  await tx.$queryRaw`SELECT id FROM service_tickets WHERE id = ${id} FOR UPDATE`;
  const ticket = await tx.serviceTicket.findUnique({ where: { id }, include: { sparepartRequests: true, intakeBy: { select: { supervisorId: true } }, technician: { select: { supervisorId: true } } } });
  if (!ticket) throw new Error("Tiket servis tidak ditemukan.");
  return ticket;
}

function assertTicketAccess(user: Awaited<ReturnType<typeof requireUser>>, ticket: { branchId: string; status: string; intakeByEmployeeId: string | null; technicianEmployeeId: string | null; intakeBy: { supervisorId: string | null } | null; technician: { supervisorId: string | null } | null }) {
  if (!canAccessTicket(user, ticket)) throw new Error("Tiket ini berada di luar penugasan Anda.");
}

async function syncTicketEmployees(tx: Prisma.TransactionClient, employeeIds: Array<string | null>, at: Date, actorId: string) {
  for (const employeeId of new Set(employeeIds.filter((id): id is string => Boolean(id)))) await syncEmployeeOperationalKpis(tx, employeeId, at, actorId);
}

function ticketSnapshot(ticket: { status: string; resultStatus: string; rowVersion: number; technicianEmployeeId: string | null; customerConsentStatus: string; estimatedCost: { toString(): string }; finalCost: { toString(): string }; paidAmount: { toString(): string }; paymentStatus: string }) {
  return { status: ticket.status, resultStatus: ticket.resultStatus, rowVersion: ticket.rowVersion, technicianEmployeeId: ticket.technicianEmployeeId, customerConsentStatus: ticket.customerConsentStatus, estimatedCost: ticket.estimatedCost.toString(), finalCost: ticket.finalCost.toString(), paidAmount: ticket.paidAmount.toString(), paymentStatus: ticket.paymentStatus };
}

export async function createTicket(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.create") || user.employee?.position.code !== "POS-CS") return { error: "Tiket servis resmi hanya dapat dibuat oleh Pelayan." };
  const parsed = createSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa kembali data pelanggan, perangkat, keluhan, dan kompleksitas servis." };
  if (user.role !== "super_admin" && user.employee?.branchId !== parsed.data.branchId) return { error: "Cabang tidak sesuai dengan akun Anda." };

  try {
    const ticket = await prisma.$transaction(async (tx) => {
      const branch = await tx.branch.findFirst({ where: { id: parsed.data.branchId, isActive: true } });
      if (!branch) throw new Error("Cabang tidak ditemukan atau tidak aktif.");
      const now = new Date();
      const estimatedCompletionAt = addWeekdays(now, SERVICE_SLA_WORKDAYS[parsed.data.serviceComplexity]);
      const period = await tx.kpiPeriod.findFirst({ where: { startDate: { lte: now }, endDate: { gte: now }, status: { not: "CANCELLED" } }, orderBy: { year: "desc" } });
      const ticketNumber = `SRV-${now.toISOString().slice(0, 10).replaceAll("-", "")}-${randomUUID().slice(0, 8).toUpperCase()}`;
      const created = await tx.serviceTicket.create({
        data: {
          ticketNumber,
          branchId: branch.id,
          periodId: period?.id,
          intakeByEmployeeId: user.employee?.id,
          customerName: parsed.data.customerName,
          customerPhone: parsed.data.customerPhone,
          customerAddress: parsed.data.customerAddress || null,
          deviceBrand: parsed.data.deviceBrand,
          deviceModel: parsed.data.deviceModel,
          imeiOrSerial: parsed.data.imeiOrSerial || null,
          physicalCondition: parsed.data.physicalCondition || null,
          initialComplaint: parsed.data.initialComplaint,
          customerNeeds: parsed.data.customerNeeds || null,
          estimatedCost: 0,
          estimatedCompletionAt,
          serviceCategory: parsed.data.serviceCategory,
          serviceComplexity: parsed.data.serviceComplexity,
          slaBaselineDueAt: estimatedCompletionAt,
          slaDueAt: estimatedCompletionAt,
          slaSnapshotJson: { version: "v1", category: parsed.data.serviceCategory, complexity: parsed.data.serviceComplexity, workdays: SERVICE_SLA_WORKDAYS[parsed.data.serviceComplexity], captured_at: now.toISOString() },
        },
      });
      if (created.intakeByEmployeeId) await syncEmployeeOperationalKpis(tx, created.intakeByEmployeeId, now, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "create_service_ticket", subjectType: "ServiceTicket", subjectId: created.id, afterJson: { ticketNumber, branchId: branch.id, status: "INTAKE" } } });
      return created;
    });
    revalidatePath("/app/servis");
    return { success: "Tiket servis berhasil dibuat.", ticketId: ticket.id };
  } catch (error) {
    return { error: safeMessage(error) };
  }
}

const assignSchema = z.object({
  ticketId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
  technicianEmployeeId: z.string().min(1),
  reason: z.string().trim().min(3).max(1000),
});

export async function assignTechnician(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.supervise") && !hasCapability(user, "tickets.manage")) return { error: "Anda tidak berwenang menugaskan Teknisi." };
  const parsed = assignSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Pilih Teknisi dan tulis alasan penugasan." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (["COMPLETED", "DELIVERED", "CANCELLED", "CANCELLED_UNREPAIRABLE"].includes(ticket.status)) throw new Error("Tiket final tidak dapat ditugaskan ulang.");
      const technician = await tx.employee.findFirst({
        where: { id: parsed.data.technicianEmployeeId, branchId: ticket.branchId, status: "ACTIVE", position: { code: "POS-TEK", isActive: true } },
        select: { id: true, supervisorId: true, name: true },
      });
      if (!technician) throw new Error("Teknisi aktif di cabang tiket tidak ditemukan.");
      if (user.role === "supervisor" && technician.supervisorId !== user.employee?.id) throw new Error("Teknisi tersebut berada di luar tim Anda.");
      const updated = await tx.serviceTicket.updateMany({ where: { id: ticket.id, rowVersion: ticket.rowVersion }, data: { technicianEmployeeId: technician.id, rowVersion: { increment: 1 } } });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await tx.auditEvent.create({ data: { actorId: user.id, action: "assign_ticket_technician", subjectType: "ServiceTicket", subjectId: ticket.id, beforeJson: ticketSnapshot(ticket), afterJson: { technicianEmployeeId: technician.id, technicianName: technician.name, rowVersion: ticket.rowVersion + 1 }, reason: parsed.data.reason } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  return { success: "Teknisi berhasil ditugaskan." };
}

const progressSchema = z.object({
  ticketId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
  diagnosisNotes: z.string().trim().max(5000).optional(),
  actionNotes: z.string().trim().max(5000).optional(),
});

export async function saveTicketProgress(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.progress")) return { error: "Hanya Teknisi yang ditugaskan dapat menyimpan diagnosis dan tindakan." };
  const parsed = progressSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success || (!parsed.data.diagnosisNotes && !parsed.data.actionNotes)) return { error: "Isi diagnosis atau tindakan yang dikerjakan." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (ticket.technicianEmployeeId !== user.employee?.id) throw new Error("Hanya Teknisi penanggung jawab yang dapat mengubah progres.");
      if (!["DIAGNOSING", "WAITING_CONSENT", "WAITING_SPAREPART", "IN_PROGRESS"].includes(ticket.status)) throw new Error("Progres teknis tidak dapat diubah pada status ini.");
      if (ticket.isWarrantyReturn && ticket.warrantyReviewStatus !== "approved") throw new Error("Retur garansi harus disetujui Supervisor atau Manager terlebih dahulu.");
      const updated = await tx.serviceTicket.updateMany({
        where: { id: ticket.id, rowVersion: ticket.rowVersion },
        data: { diagnosisNotes: parsed.data.diagnosisNotes || undefined, actionNotes: parsed.data.actionNotes || undefined, rowVersion: { increment: 1 } },
      });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await tx.auditEvent.create({ data: { actorId: user.id, action: "update_ticket_progress", subjectType: "ServiceTicket", subjectId: ticket.id, beforeJson: ticketSnapshot(ticket), afterJson: { diagnosisUpdated: Boolean(parsed.data.diagnosisNotes), actionUpdated: Boolean(parsed.data.actionNotes), rowVersion: ticket.rowVersion + 1 } } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  return { success: "Diagnosis dan tindakan berhasil disimpan." };
}

const technicalEvidenceSchema = z.object({
  ticketId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
  type: z.string().trim().min(1).max(50),
  reference: z.string().trim().min(1).max(1000),
  note: z.string().trim().max(2000).optional(),
});

export async function addTechnicalEvidence(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.evidence")) return { error: "Hanya Teknisi penanggung jawab yang dapat menambah evidence." };
  const parsed = technicalEvidenceSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Jenis dan referensi evidence wajib diisi." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (ticket.technicianEmployeeId !== user.employee?.id) throw new Error("Hanya Teknisi penanggung jawab yang dapat menambah evidence.");
      assertTechnicalEvidenceCanBeAdded(ticket.status);
      if (ticket.isWarrantyReturn && ticket.warrantyReviewStatus !== "approved") throw new Error("Retur garansi harus disetujui sebelum evidence ditambahkan.");
      const evidence = Array.isArray(ticket.technicalEvidenceJson) ? ticket.technicalEvidenceJson : [];
      const entry = { type: parsed.data.type, reference: parsed.data.reference, note: parsed.data.note || null, file_path: null, file_name: null, mime_type: null, size_bytes: null, sha256_hash: null, scan_status: "clean", scanned_at: new Date().toISOString(), uploaded_at: new Date().toISOString(), uploaded_by_user_id: user.id, uploaded_by_employee_id: user.employee.id };
      const updated = await tx.serviceTicket.updateMany({ where: { id: ticket.id, rowVersion: ticket.rowVersion }, data: { technicalEvidenceJson: [...evidence, entry], rowVersion: { increment: 1 } } });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await tx.auditEvent.create({ data: { actorId: user.id, action: "add_ticket_technical_evidence", subjectType: "ServiceTicket", subjectId: ticket.id, beforeJson: { evidenceCount: evidence.length, rowVersion: ticket.rowVersion }, afterJson: { evidenceCount: evidence.length + 1, type: entry.type, reference: entry.reference, rowVersion: ticket.rowVersion + 1 }, reason: entry.note } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  return { success: "Evidence teknis ditambahkan." };
}

const consentSchema = z.object({
  ticketId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
  consentStatus: z.enum(["approved", "declined"]),
  notes: z.string().trim().max(2000).optional(),
});

export async function recordCustomerConsent(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.consent") && !hasCapability(user, "tickets.manage")) return { error: "Anda tidak berwenang mencatat persetujuan pelanggan." };
  const parsed = consentSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success || (parsed.data.consentStatus === "declined" && !parsed.data.notes)) return { error: "Status dan alasan penolakan pelanggan wajib diisi." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (["COMPLETED", "DELIVERED", "CANCELLED", "CANCELLED_UNREPAIRABLE"].includes(ticket.status)) throw new Error("Persetujuan tidak dapat diubah setelah tiket final.");
      if (!ticket.diagnosisNotes?.trim()) throw new Error("Diagnosis Teknisi harus tersedia sebelum persetujuan pelanggan dicatat.");
      if (!hasCapability(user, "tickets.manage") && ticket.intakeByEmployeeId !== user.employee?.id) throw new Error("Tiket ini ditangani Pelayan lain.");
      if (ticket.customerConsentStatus === "approved" && parsed.data.consentStatus === "declined" && !hasCapability(user, "tickets.manage")) throw new Error("Persetujuan yang sudah disahkan hanya dapat ditarik oleh Manager.");
      const now = new Date();
      const updated = await tx.serviceTicket.updateMany({ where: { id: ticket.id, rowVersion: ticket.rowVersion }, data: { customerConsentStatus: parsed.data.consentStatus, customerConsentAt: now, customerConsentByEmployeeId: user.employee?.id, customerConsentNotes: parsed.data.notes || null, rowVersion: { increment: 1 } } });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await syncTicketEmployees(tx, [ticket.intakeByEmployeeId, ticket.technicianEmployeeId], now, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "record_ticket_consent", subjectType: "ServiceTicket", subjectId: ticket.id, beforeJson: ticketSnapshot(ticket), afterJson: { customerConsentStatus: parsed.data.consentStatus, rowVersion: ticket.rowVersion + 1 }, reason: parsed.data.notes } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  return { success: "Persetujuan pelanggan berhasil dicatat." };
}

const costSchema = z.object({
  ticketId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
  kind: z.enum(["estimated", "final"]),
  amount: z.coerce.number().finite().min(0).max(1_000_000_000),
  note: z.string().trim().max(2000).optional(),
});

export async function recordTicketCost(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.cost") && !hasCapability(user, "tickets.manage")) return { error: "Hanya Kasir atau Manager yang dapat mencatat biaya." };
  const parsed = costSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Jenis dan nominal biaya tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (user.employee?.position.code === "POS-KSR" && ticket.cashierEmployeeId && ticket.cashierEmployeeId !== user.employee.id) throw new Error("Biaya tiket ini ditangani Kasir lain.");
      const current = Number(parsed.data.kind === "final" ? ticket.finalCost : ticket.estimatedCost);
      if (Math.abs(current - parsed.data.amount) > 0.000001 && !parsed.data.note) throw new Error("Perubahan nominal biaya wajib memiliki catatan.");
      if (parsed.data.kind === "final") {
        if (ticket.status !== "COMPLETED") throw new Error("Biaya final hanya dapat dicatat setelah pekerjaan teknis selesai.");
        if (parsed.data.amount < Number(ticket.paidAmount)) throw new Error("Biaya final tidak boleh lebih kecil dari pembayaran yang sudah dicatat.");
      } else if (!["INTAKE", "DIAGNOSING", "WAITING_CONSENT", "WAITING_SPAREPART", "IN_PROGRESS", "QC_READY"].includes(ticket.status)) throw new Error("Estimasi biaya tidak dapat diubah setelah pekerjaan teknis selesai.");
      const resetConsent = parsed.data.kind === "estimated" && Math.abs(current - parsed.data.amount) > 0.000001;
      const paymentStatus = parsed.data.kind === "final" ? (parsed.data.amount <= 0 ? "WAIVED" : Number(ticket.paidAmount) >= parsed.data.amount ? "PAID" : Number(ticket.paidAmount) > 0 ? "PARTIAL" : "UNPAID") : undefined;
      const updated = await tx.serviceTicket.updateMany({
        where: { id: ticket.id, rowVersion: ticket.rowVersion },
        data: {
          estimatedCost: parsed.data.kind === "estimated" ? parsed.data.amount : undefined,
          finalCost: parsed.data.kind === "final" ? parsed.data.amount : undefined,
          paymentStatus,
          cashierEmployeeId: user.employee?.position.code === "POS-KSR" ? user.employee.id : undefined,
          customerConsentStatus: resetConsent ? "pending" : undefined,
          customerConsentAt: resetConsent ? null : undefined,
          customerConsentByEmployeeId: resetConsent ? null : undefined,
          rowVersion: { increment: 1 },
        },
      });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await tx.auditEvent.create({ data: { actorId: user.id, action: parsed.data.kind === "final" ? "record_ticket_final_cost" : "record_ticket_estimated_cost", subjectType: "ServiceTicket", subjectId: ticket.id, beforeJson: ticketSnapshot(ticket), afterJson: { amount: parsed.data.amount, rowVersion: ticket.rowVersion + 1 }, reason: parsed.data.note } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  return { success: parsed.data.kind === "final" ? "Biaya final berhasil dicatat." : "Estimasi biaya berhasil dicatat; persetujuan lama direset bila nominal berubah." };
}

const QC_KEYS = ["display", "touch", "camera", "mic", "speaker", "cellular", "charging", "biometric"] as const;
const completionSchema = z.object({
  ticketId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
  resultStatus: z.enum(["success", "unrepairable", "customer_declined"]),
  diagnosisNotes: z.string().trim().min(3).max(5000),
  actionNotes: z.string().trim().min(3).max(5000),
  technicalEvidence: z.string().trim().max(1000).optional(),
  unrepairableReason: z.string().trim().max(2000).optional(),
  customerDeclinedReason: z.string().trim().max(2000).optional(),
});

export async function completeTicket(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.complete")) return { error: "Hanya Teknisi penanggung jawab yang dapat menyelesaikan tiket." };
  const parsed = completionSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Lengkapi hasil, diagnosis, tindakan, dan alasan yang diperlukan." };
  const submittedFile = formData.get("technicalEvidenceFile");
  if (submittedFile instanceof File && submittedFile.name && submittedFile.size === 0) return { error: "File evidence kosong." };
  const evidenceFile = submittedFile instanceof File && submittedFile.size > 0 ? submittedFile : null;
  const qc = Object.fromEntries(QC_KEYS.map((key) => [key, formData.get(key) === "on"])) as Record<(typeof QC_KEYS)[number], boolean>;
  const hasEvidence = Boolean(parsed.data.technicalEvidence || evidenceFile);
  if (parsed.data.resultStatus === "success" && (!hasEvidence || QC_KEYS.some((key) => !qc[key]))) return { error: "Servis sukses memerlukan seluruh QC lulus dan evidence." };
  if (parsed.data.resultStatus === "unrepairable" && (!parsed.data.unrepairableReason || !hasEvidence)) return { error: "Alasan dan evidence wajib untuk perangkat yang tidak dapat diperbaiki." };
  if (parsed.data.resultStatus === "customer_declined" && !parsed.data.customerDeclinedReason) return { error: "Alasan pelanggan menolak wajib dicatat." };
  const candidate = await prisma.serviceTicket.findUnique({ where: { id: parsed.data.ticketId }, include: { intakeBy: { select: { supervisorId: true } }, technician: { select: { supervisorId: true } } } });
  if (!candidate || !canAccessTicket(user, candidate) || candidate.technicianEmployeeId !== user.employee?.id || candidate.rowVersion !== parsed.data.rowVersion) return { error: "Tiket tidak ditemukan, bukan penugasan Anda, atau sudah berubah. Muat ulang halaman." };
  let storedPath: string | null = null;
  try {
    const now = new Date();
    let newEvidence: Record<string, string | number | null> | null = null;
    if (evidenceFile) {
      if (evidenceFile.size > MAX_EVIDENCE_BYTES) throw new Error("Ukuran evidence maksimal 10 MB.");
      const buffer = Buffer.from(await evidenceFile.arrayBuffer());
      const { extension, mimeType } = validateEvidenceFile(buffer, evidenceFile.name, evidenceFile.type);
      const relativePath = `storage/service-ticket-evidence/${randomUUID()}${extension}`;
      const absolutePath = join(process.cwd(), relativePath);
      await mkdir(join(process.cwd(), "storage", "service-ticket-evidence"), { recursive: true });
      await writeFile(absolutePath, buffer, { flag: "wx" });
      storedPath = absolutePath;
      await scanEvidenceFile(absolutePath);
      const fileName = evidenceFile.name.replace(/[\r\n"]/g, "_").slice(0, 255) || `evidence${extension}`;
      newEvidence = { type: "service_result", reference: parsed.data.technicalEvidence || fileName, file_path: relativePath, file_name: fileName, mime_type: mimeType, size_bytes: buffer.length, sha256_hash: createHash("sha256").update(buffer).digest("hex"), scan_status: "clean", scanned_at: now.toISOString(), uploaded_at: now.toISOString(), uploaded_by_user_id: user.id, uploaded_by_employee_id: user.employee?.id ?? null };
    } else if (parsed.data.technicalEvidence) {
      newEvidence = { type: "reference", reference: parsed.data.technicalEvidence, file_path: null, file_name: null, mime_type: null, size_bytes: null, sha256_hash: null, scan_status: "clean", scanned_at: now.toISOString(), uploaded_at: now.toISOString(), uploaded_by_user_id: user.id, uploaded_by_employee_id: user.employee?.id ?? null };
    }
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (ticket.technicianEmployeeId !== user.employee?.id) throw new Error("Hanya Teknisi penanggung jawab yang dapat menyelesaikan tiket.");
      if (ticket.isWarrantyReturn && ticket.warrantyReviewStatus !== "approved") throw new Error("Retur garansi belum disetujui Supervisor atau Manager.");
      if (parsed.data.resultStatus === "success" && ticket.status !== "QC_READY") throw new Error("Servis sukses hanya dapat diselesaikan setelah berstatus siap QC.");
      assertTicketTransition(ticket.status, "COMPLETED");
      if (ticket.sparepartRequests.some((request) => request.status === "pending" || (request.status === "fulfilled" && !request.confirmedAt))) throw new Error("Semua permintaan sparepart harus selesai dan dikonfirmasi Teknisi.");
      if (parsed.data.resultStatus === "success" && ticket.customerConsentStatus !== "approved") throw new Error("Persetujuan pelanggan wajib disetujui sebelum servis sukses ditutup.");
      const evidence = Array.isArray(ticket.technicalEvidenceJson) ? ticket.technicalEvidenceJson : [];
      const technicalEvidenceJson = newEvidence ? [...evidence, newEvidence] : evidence;
      const updated = await tx.serviceTicket.updateMany({
        where: { id: ticket.id, rowVersion: ticket.rowVersion },
        data: { status: "COMPLETED", resultStatus: parsed.data.resultStatus, diagnosisNotes: parsed.data.diagnosisNotes, actionNotes: parsed.data.actionNotes, qcChecklistJson: qc, technicalEvidenceJson, unrepairableReason: parsed.data.unrepairableReason || null, customerDeclinedReason: parsed.data.customerDeclinedReason || null, completedAt: now, slaBreachedAt: ticket.slaDueAt && now > ticket.slaDueAt ? ticket.slaBreachedAt ?? now : ticket.slaBreachedAt, rowVersion: { increment: 1 } },
      });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await syncTicketEmployees(tx, [ticket.technicianEmployeeId, ticket.intakeByEmployeeId], now, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "complete_service_ticket", subjectType: "ServiceTicket", subjectId: ticket.id, beforeJson: ticketSnapshot(ticket), afterJson: { status: "COMPLETED", resultStatus: parsed.data.resultStatus, evidenceSha256: newEvidence?.sha256_hash ?? null, rowVersion: ticket.rowVersion + 1 }, reason: parsed.data.unrepairableReason || parsed.data.customerDeclinedReason } });
    });
  } catch (error) {
    if (storedPath) await unlink(storedPath).catch(() => undefined);
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  revalidatePath("/app/servis");
  return { success: "Pekerjaan teknis selesai dan KPI tersinkron." };
}

const deliverySchema = z.object({
  ticketId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
  recipientType: z.enum(["customer", "representative"]),
  recipientName: z.string().trim().max(120).optional(),
  deliveryNotes: z.string().trim().max(2000).optional(),
});

export async function deliverTicket(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  const allowed = hasCapability(user, "tickets.deliver") || hasCapability(user, "tickets.supervise") || hasCapability(user, "tickets.manage");
  if (!allowed) return { error: "Anda tidak berwenang menyerahkan perangkat." };
  const parsed = deliverySchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success || (parsed.data.recipientType === "representative" && !parsed.data.recipientName)) return { error: "Identitas penerima perangkat wajib lengkap." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (ticket.status !== "COMPLETED") throw new Error("Perangkat hanya dapat diserahkan setelah pekerjaan teknis selesai.");
      const override = ticket.intakeByEmployeeId !== user.employee?.id;
      if (override && !hasCapability(user, "tickets.supervise") && !hasCapability(user, "tickets.manage")) throw new Error("Tiket ini ditangani Pelayan lain.");
      if (override && (!parsed.data.deliveryNotes || parsed.data.deliveryNotes.length < 3)) throw new Error("Delegasi penyerahan wajib memiliki alasan.");
      if (ticket.resultStatus === "success" && Number(ticket.estimatedCost) > 0 && Number(ticket.finalCost) <= 0) throw new Error("Biaya final wajib dicatat sebelum perangkat diserahkan.");
      if (Number(ticket.finalCost) > 0 && !["PAID", "WAIVED"].includes(ticket.paymentStatus)) throw new Error("Pembayaran harus lunas atau mendapat pengecualian Manager.");
      const now = new Date();
      const warrantyExpiresAt = new Date(now.valueOf() + 7 * 86_400_000);
      const updated = await tx.serviceTicket.updateMany({ where: { id: ticket.id, rowVersion: ticket.rowVersion }, data: { status: "DELIVERED", deliveredAt: now, deliveredByEmployeeId: user.employee?.id, deliveryRecipientType: parsed.data.recipientType, deliveryRecipientName: parsed.data.recipientName || ticket.customerName, deliveryNotes: parsed.data.deliveryNotes || null, warrantyExpiresAt, passcodeCiphertext: null, rowVersion: { increment: 1 } } });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await syncTicketEmployees(tx, [ticket.intakeByEmployeeId, ticket.technicianEmployeeId, ticket.cashierEmployeeId], now, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "deliver_service_ticket", subjectType: "ServiceTicket", subjectId: ticket.id, beforeJson: ticketSnapshot(ticket), afterJson: { status: "DELIVERED", recipientType: parsed.data.recipientType, recipientName: parsed.data.recipientName || ticket.customerName, rowVersion: ticket.rowVersion + 1 }, reason: parsed.data.deliveryNotes } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  revalidatePath("/app/servis");
  return { success: "Perangkat berhasil diserahkan." };
}

const paymentExceptionSchema = z.object({ ticketId: z.string().min(1), rowVersion: z.coerce.number().int().positive(), exceptionType: z.enum(["installment", "receivable", "waiver"]), reason: z.string().trim().min(3).max(2000) });

export async function approvePaymentException(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.manage") || !["owner_manager", "super_admin"].includes(user.role)) return { error: "Pengecualian pembayaran hanya dapat disahkan Manager." };
  const parsed = paymentExceptionSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Jenis dan alasan pengecualian wajib diisi." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (ticket.status !== "COMPLETED") throw new Error("Pengecualian pembayaran hanya berlaku setelah pekerjaan teknis selesai.");
      const now = new Date();
      const updated = await tx.serviceTicket.updateMany({ where: { id: ticket.id, rowVersion: ticket.rowVersion }, data: { paymentStatus: "WAIVED", paymentExceptionType: parsed.data.exceptionType, paymentExceptionReason: parsed.data.reason, paymentExceptionApprovedByUserId: user.id, paymentExceptionApprovedAt: now, rowVersion: { increment: 1 } } });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await tx.auditEvent.create({ data: { actorId: user.id, action: "approve_ticket_payment_exception", subjectType: "ServiceTicket", subjectId: ticket.id, beforeJson: ticketSnapshot(ticket), afterJson: { paymentStatus: "WAIVED", exceptionType: parsed.data.exceptionType, rowVersion: ticket.rowVersion + 1 }, reason: parsed.data.reason } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  return { success: "Pengecualian pembayaran disahkan Manager." };
}

const requestPartSchema = z.object({ ticketId: z.string().min(1), rowVersion: z.coerce.number().int().positive(), sparepartId: z.string().min(1), quantity: z.coerce.number().int().min(1).max(1000), notes: z.string().trim().max(2000).optional() });

export async function requestSparepart(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "spareparts.request")) return { error: "Hanya Teknisi penanggung jawab yang dapat meminta sparepart." };
  const parsed = requestPartSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Sparepart dan jumlah permintaan tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (ticket.technicianEmployeeId !== user.employee?.id) throw new Error("Hanya Teknisi penanggung jawab yang dapat meminta sparepart.");
      if (!["DIAGNOSING", "WAITING_SPAREPART", "IN_PROGRESS"].includes(ticket.status)) throw new Error("Status tiket tidak dapat memproses sparepart.");
      if (ticket.isWarrantyReturn && ticket.warrantyReviewStatus !== "approved") throw new Error("Retur garansi belum disetujui Supervisor atau Manager.");
      const sparepart = await tx.sparepart.findFirst({ where: { id: parsed.data.sparepartId, productType: "sparepart", OR: [{ branchId: ticket.branchId }, { branchId: null }] } });
      if (!sparepart) throw new Error("Sparepart tidak tersedia untuk cabang tiket.");
      if (ticket.sparepartRequests.some((request) => request.sparepartId === sparepart.id && ["pending", "fulfilled"].includes(request.status) && !request.confirmedAt)) throw new Error("Permintaan sparepart yang sama masih belum selesai.");
      const now = new Date();
      const request = await tx.sparepartRequest.create({ data: { serviceTicketId: ticket.id, sparepartId: sparepart.id, technicianEmployeeId: user.employee.id, quantity: parsed.data.quantity, status: "pending", requestedAt: now, slaDeadlineAt: new Date(now.valueOf() + 15 * 60_000), notes: parsed.data.notes || null, pendingUniqueKey: `${ticket.id}:${sparepart.id}` } });
      const updated = await tx.serviceTicket.updateMany({ where: { id: ticket.id, rowVersion: ticket.rowVersion }, data: { status: "WAITING_SPAREPART", rowVersion: { increment: 1 } } });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await tx.auditEvent.create({ data: { actorId: user.id, action: "request_ticket_sparepart", subjectType: "SparepartRequest", subjectId: request.id, afterJson: { ticketId: ticket.id, sparepartId: sparepart.id, quantity: request.quantity, status: request.status }, reason: parsed.data.notes } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  return { success: "Permintaan sparepart dikirim ke Gudang." };
}

const decidePartSchema = z.object({ requestId: z.string().min(1), ticketId: z.string().min(1), rowVersion: z.coerce.number().int().positive(), decision: z.enum(["fulfilled", "unavailable"]), note: z.string().trim().max(2000).optional() });

export async function decideSparepartRequest(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "spareparts.fulfill") && !hasCapability(user, "tickets.manage")) return { error: "Hanya Gudang atau Manager yang dapat memproses permintaan." };
  const parsed = decidePartSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success || (parsed.data.decision === "unavailable" && !parsed.data.note)) return { error: "Keputusan dan alasan ketersediaan wajib valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      await tx.$queryRaw`SELECT id FROM sparepart_requests WHERE id = ${parsed.data.requestId} FOR UPDATE`;
      const request = await tx.sparepartRequest.findFirst({ where: { id: parsed.data.requestId, serviceTicketId: ticket.id }, include: { sparepart: true } });
      if (!request) throw new Error("Permintaan sparepart tidak ditemukan.");
      if (request.status !== "pending") throw new Error("Permintaan sparepart sudah diproses.");
      const now = new Date();
      if (parsed.data.decision === "fulfilled") {
        await tx.$queryRaw`SELECT id FROM spareparts WHERE id = ${request.sparepartId} FOR UPDATE`;
        const sparepart = await tx.sparepart.findUniqueOrThrow({ where: { id: request.sparepartId } });
        if (sparepart.stockQuantity < request.quantity) throw new Error("Stok sparepart tidak mencukupi.");
        await tx.sparepart.update({ where: { id: sparepart.id }, data: { stockQuantity: { decrement: request.quantity } } });
        await tx.stockMovement.create({ data: { sparepartId: sparepart.id, movementType: "request_out", quantity: -request.quantity, stockBefore: sparepart.stockQuantity, stockAfter: sparepart.stockQuantity - request.quantity, referenceType: "sparepart_request", referenceId: request.id, note: "Penyerahan sparepart ke Teknisi", userId: user.id } });
        await tx.sparepartRequest.update({ where: { id: request.id }, data: { status: "fulfilled", fulfilledAt: now, warehouseEmployeeId: user.employee?.id, availabilityNote: null } });
      } else {
        await tx.sparepartRequest.update({ where: { id: request.id }, data: { status: "unavailable", availabilityNote: parsed.data.note, warehouseEmployeeId: user.employee?.id, pendingUniqueKey: null } });
      }
      const updated = await tx.serviceTicket.updateMany({ where: { id: ticket.id, rowVersion: ticket.rowVersion }, data: { rowVersion: { increment: 1 } } });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await syncTicketEmployees(tx, [user.employee?.id ?? null, ticket.technicianEmployeeId], now, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: parsed.data.decision === "fulfilled" ? "fulfill_ticket_sparepart" : "mark_ticket_sparepart_unavailable", subjectType: "SparepartRequest", subjectId: request.id, beforeJson: { status: request.status }, afterJson: { status: parsed.data.decision, rowVersion: ticket.rowVersion + 1 }, reason: parsed.data.note } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  return { success: parsed.data.decision === "fulfilled" ? "Sparepart diserahkan dan stok terpotong." : "Sparepart ditandai tidak tersedia." };
}

const confirmPartSchema = z.object({ requestId: z.string().min(1), ticketId: z.string().min(1), rowVersion: z.coerce.number().int().positive() });

export async function confirmSparepart(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "spareparts.confirm")) return { error: "Hanya Teknisi penanggung jawab yang dapat mengonfirmasi sparepart." };
  const parsed = confirmPartSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Permintaan sparepart tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (ticket.technicianEmployeeId !== user.employee?.id) throw new Error("Hanya Teknisi penanggung jawab yang dapat mengonfirmasi sparepart.");
      await tx.$queryRaw`SELECT id FROM sparepart_requests WHERE id = ${parsed.data.requestId} FOR UPDATE`;
      const request = await tx.sparepartRequest.findFirst({ where: { id: parsed.data.requestId, serviceTicketId: ticket.id } });
      if (!request || request.status !== "fulfilled" || request.confirmedAt) throw new Error("Sparepart belum dipenuhi atau sudah dikonfirmasi.");
      const now = new Date();
      await tx.sparepartRequest.update({ where: { id: request.id }, data: { confirmedAt: now, confirmedByEmployeeId: user.employee.id, pendingUniqueKey: null } });
      const confirmed = await tx.sparepartRequest.findMany({ where: { serviceTicketId: ticket.id, confirmedAt: { not: null } }, select: { requestedAt: true, confirmedAt: true } });
      const waitMinutes = totalSparepartWaitMinutes(confirmed.flatMap((item) => item.confirmedAt ? [{ requestedAt: item.requestedAt, confirmedAt: item.confirmedAt }] : []));
      const baseline = ticket.slaBaselineDueAt ?? ticket.estimatedCompletionAt;
      const due = baseline ? new Date(baseline.valueOf() + waitMinutes * 60_000) : null;
      const updated = await tx.serviceTicket.updateMany({ where: { id: ticket.id, rowVersion: ticket.rowVersion }, data: { sparepartWaitMinutes: waitMinutes, slaDueAt: due, estimatedCompletionAt: due ?? undefined, rowVersion: { increment: 1 } } });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await tx.auditEvent.create({ data: { actorId: user.id, action: "confirm_ticket_sparepart", subjectType: "SparepartRequest", subjectId: request.id, afterJson: { confirmedAt: now.toISOString(), waitMinutes, rowVersion: ticket.rowVersion + 1 } } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  return { success: "Sparepart dikonfirmasi dan SLA diperbarui." };
}

const warrantyReturnSchema = z.object({ ticketId: z.string().min(1), rowVersion: z.coerce.number().int().positive(), reason: z.string().trim().min(3).max(2000) });

export async function createWarrantyReturn(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.create") && !hasCapability(user, "tickets.manage")) return { error: "Anda tidak berwenang membuat retur garansi." };
  const parsed = warrantyReturnSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Alasan retur garansi wajib diisi." };
  try {
    const returned = await prisma.$transaction(async (tx) => {
      const original = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, original);
      assertExpectedVersion(original.rowVersion, parsed.data.rowVersion);
      const now = new Date();
      if (original.status !== "DELIVERED" || !original.warrantyExpiresAt || original.warrantyExpiresAt < now) throw new Error("Tiket tidak berada dalam masa garansi aktif.");
      const activeReturn = await tx.serviceTicket.findFirst({ where: { warrantyReturnedFromTicketId: original.id, status: { notIn: ["DELIVERED", "CANCELLED", "CANCELLED_UNREPAIRABLE"] } }, select: { id: true } });
      if (activeReturn) throw new Error("Retur garansi aktif untuk tiket ini sudah ada.");
      const period = await tx.kpiPeriod.findFirst({ where: { startDate: { lte: now }, endDate: { gte: now }, status: { not: "CANCELLED" } }, orderBy: { year: "desc" } });
      const complexity = original.serviceComplexity in SERVICE_SLA_WORKDAYS ? original.serviceComplexity as keyof typeof SERVICE_SLA_WORKDAYS : "light";
      const due = addWeekdays(now, SERVICE_SLA_WORKDAYS[complexity]);
      const ticket = await tx.serviceTicket.create({ data: { ticketNumber: `SRV-${now.toISOString().slice(0, 10).replaceAll("-", "")}-${randomUUID().slice(0, 8).toUpperCase()}`, customerName: original.customerName, customerPhone: original.customerPhone, customerAddress: original.customerAddress, deviceBrand: original.deviceBrand, deviceModel: original.deviceModel, imeiOrSerial: original.imeiOrSerial, physicalCondition: original.physicalCondition, initialComplaint: parsed.data.reason, customerNeeds: "Retur garansi", branchId: original.branchId, periodId: period?.id, intakeByEmployeeId: user.employee?.position.code === "POS-CS" ? user.employee.id : original.intakeByEmployeeId, status: "INTAKE", resultStatus: "pending", estimatedCost: 0, finalCost: 0, isWarrantyReturn: true, warrantyReturnedFromTicketId: original.id, warrantyReviewStatus: "pending", serviceCategory: original.serviceCategory, serviceComplexity: complexity, estimatedCompletionAt: due, slaBaselineDueAt: due, slaDueAt: due, slaSnapshotJson: { version: "v1", category: original.serviceCategory, complexity, workdays: SERVICE_SLA_WORKDAYS[complexity], captured_at: now.toISOString(), warranty_return: true } } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "create_warranty_return", subjectType: "ServiceTicket", subjectId: ticket.id, afterJson: { originalTicketId: original.id, ticketNumber: ticket.ticketNumber, warrantyReviewStatus: "pending" }, reason: parsed.data.reason } });
      return ticket;
    });
    revalidatePath(`/app/servis/${parsed.data.ticketId}`);
    revalidatePath("/app/servis");
    return { success: "Retur garansi dibuat dan menunggu validasi.", ticketId: returned.id };
  } catch (error) {
    return { error: safeMessage(error) };
  }
}

const warrantyReviewSchema = z.object({ ticketId: z.string().min(1), rowVersion: z.coerce.number().int().positive(), decision: z.enum(["approved", "rejected"]), reason: z.string().trim().min(3).max(2000) });

export async function reviewWarrantyReturn(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.supervise") && !hasCapability(user, "tickets.manage")) return { error: "Hanya Supervisor atau Manager yang dapat memvalidasi garansi." };
  const parsed = warrantyReviewSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Keputusan dan alasan validasi garansi wajib diisi." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await lockedTicket(tx, parsed.data.ticketId);
      assertTicketAccess(user, ticket);
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (!ticket.isWarrantyReturn || ticket.warrantyReviewStatus !== "pending") throw new Error("Retur garansi sudah diputuskan atau tidak valid.");
      if (ticket.status !== "INTAKE") throw new Error("Validasi garansi harus selesai sebelum diagnosis dimulai.");
      const now = new Date();
      const updated = await tx.serviceTicket.updateMany({ where: { id: ticket.id, rowVersion: ticket.rowVersion }, data: { warrantyReviewStatus: parsed.data.decision, warrantyReviewReason: parsed.data.reason, warrantyReviewedByUserId: user.id, warrantyReviewedAt: now, status: parsed.data.decision === "rejected" ? "CANCELLED" : undefined, cancellationReason: parsed.data.decision === "rejected" ? parsed.data.reason : undefined, cancelledAt: parsed.data.decision === "rejected" ? now : undefined, rowVersion: { increment: 1 } } });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await tx.auditEvent.create({ data: { actorId: user.id, action: "review_warranty_return", subjectType: "ServiceTicket", subjectId: ticket.id, beforeJson: ticketSnapshot(ticket), afterJson: { warrantyReviewStatus: parsed.data.decision, status: parsed.data.decision === "rejected" ? "CANCELLED" : ticket.status, rowVersion: ticket.rowVersion + 1 }, reason: parsed.data.reason } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  revalidatePath("/app/servis");
  return { success: parsed.data.decision === "approved" ? "Retur garansi disetujui." : "Retur garansi ditolak dan tiket dibatalkan." };
}

const statusSchema = z.object({ ticketId: z.string().min(1), rowVersion: z.coerce.number().int().positive(), nextStatus: z.string().min(1), note: z.string().trim().max(1000).optional() });

export async function updateTicketStatus(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  const parsed = statusSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Perubahan status tidak valid." };
  if (parsed.data.nextStatus.startsWith("CANCELLED") && !parsed.data.note) return { error: "Alasan pembatalan wajib diisi." };

  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await tx.serviceTicket.findUnique({ where: { id: parsed.data.ticketId }, include: { sparepartRequests: { select: { status: true, confirmedAt: true } } } });
      if (!ticket) throw new Error("Tiket servis tidak ditemukan.");
      if (!canAccessTicket(user, ticket)) throw new Error("Tiket ini berada di luar penugasan Anda.");
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      assertTicketTransition(ticket.status, parsed.data.nextStatus);
      if (ticket.isWarrantyReturn && ticket.warrantyReviewStatus !== "approved" && parsed.data.nextStatus !== "CANCELLED") throw new Error("Retur garansi harus divalidasi Supervisor atau Manager sebelum dikerjakan.");
      const capability = capabilityForTransition(ticket.status, parsed.data.nextStatus);
      if (!hasCapability(user, capability) && !hasCapability(user, "tickets.manage")) throw new Error("Anda tidak berwenang melakukan perubahan status ini.");
      const note = parsed.data.note?.trim() || null;
      const diagnosisNotes = parsed.data.nextStatus === "WAITING_CONSENT" ? note ?? ticket.diagnosisNotes : ticket.diagnosisNotes;
      const actionNotes = parsed.data.nextStatus === "QC_READY" ? note ?? ticket.actionNotes : ticket.actionNotes;
      assertTicketTransitionPrerequisites(parsed.data.nextStatus, {
        diagnosisNotes,
        actionNotes,
        customerConsentStatus: ticket.customerConsentStatus,
        hasPendingSparepart: ticket.sparepartRequests.some((request) => request.status === "pending"),
        hasUnconfirmedSparepart: ticket.sparepartRequests.some((request) => request.status === "fulfilled" && !request.confirmedAt),
      });
      const technical = ["tickets.claim", "tickets.progress", "tickets.complete"].includes(capability);
      if (capability === "tickets.claim" && (user.employee?.position.code !== "POS-TEK" || (ticket.technicianEmployeeId && ticket.technicianEmployeeId !== user.employee.id))) throw new Error("Tiket hanya dapat diambil oleh Teknisi yang akan menanganinya.");
      if (technical && capability !== "tickets.claim" && ticket.technicianEmployeeId !== user.employee?.id) throw new Error("Hanya Teknisi penanggung jawab yang dapat memperbarui pengerjaan.");
      if (parsed.data.nextStatus === "CANCELLED" && !hasCapability(user, "tickets.manage") && ticket.intakeByEmployeeId !== user.employee?.id) throw new Error("Tiket ini ditangani Pelayan lain.");
      const now = new Date();
      const updated = await tx.serviceTicket.updateMany({
        where: { id: ticket.id, rowVersion: ticket.rowVersion },
        data: {
          status: parsed.data.nextStatus as typeof ticket.status,
          technicianEmployeeId: capability === "tickets.claim" ? user.employee?.id : undefined,
          diagnosisNotes,
          actionNotes,
          startedAt: ["DIAGNOSING", "IN_PROGRESS"].includes(parsed.data.nextStatus) ? ticket.startedAt ?? now : undefined,
          completedAt: parsed.data.nextStatus === "COMPLETED" ? now : undefined,
          deliveredAt: parsed.data.nextStatus === "DELIVERED" ? now : undefined,
          deliveredByEmployeeId: parsed.data.nextStatus === "DELIVERED" ? user.employee?.id : undefined,
          cancellationReason: parsed.data.nextStatus === "CANCELLED" ? note : undefined,
          cancelledAt: parsed.data.nextStatus === "CANCELLED" ? now : undefined,
          rowVersion: { increment: 1 },
        },
      });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      for (const employeeId of new Set([ticket.intakeByEmployeeId, ticket.technicianEmployeeId, ticket.cashierEmployeeId, capability === "tickets.claim" ? user.employee?.id : null].filter((id): id is string => Boolean(id)))) {
        await syncEmployeeOperationalKpis(tx, employeeId, now, user.id);
      }
      await tx.auditEvent.create({ data: { actorId: user.id, action: "update_ticket_status", subjectType: "ServiceTicket", subjectId: ticket.id, beforeJson: { status: ticket.status, rowVersion: ticket.rowVersion }, afterJson: { status: parsed.data.nextStatus, rowVersion: ticket.rowVersion + 1 }, reason: note } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  revalidatePath("/app/servis");
  revalidatePath("/app");
  return { success: "Status servis diperbarui." };
}

const paymentSchema = z.object({ ticketId: z.string().min(1), rowVersion: z.coerce.number().int().positive(), paidAmount: z.coerce.number().min(0).max(1_000_000_000) });

export async function recordPayment(_: TicketActionState, formData: FormData): Promise<TicketActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.payment") && !hasCapability(user, "tickets.manage")) return { error: "Anda tidak berwenang mencatat pembayaran." };
  const parsed = paymentSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Nominal pembayaran tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const ticket = await tx.serviceTicket.findUnique({ where: { id: parsed.data.ticketId } });
      if (!ticket) throw new Error("Tiket servis tidak ditemukan.");
      if (!canAccessTicket(user, ticket)) throw new Error("Tiket ini berada di luar penugasan Anda.");
      assertExpectedVersion(ticket.rowVersion, parsed.data.rowVersion);
      if (ticket.status !== "COMPLETED") throw new Error("Pembayaran hanya dapat dicatat setelah pekerjaan teknis selesai.");
      if (user.employee?.position.code === "POS-KSR" && ticket.cashierEmployeeId && ticket.cashierEmployeeId !== user.employee.id) throw new Error("Pembayaran tiket ini ditangani Kasir lain.");
      const bill = Number(ticket.finalCost);
      if (bill <= 0) throw new Error("Catat biaya final sebelum menerima pembayaran.");
      if (parsed.data.paidAmount > bill) throw new Error("Pembayaran tidak boleh melebihi biaya final.");
      const status = parsed.data.paidAmount <= 0 ? "UNPAID" : parsed.data.paidAmount >= bill ? "PAID" : "PARTIAL";
      const updated = await tx.serviceTicket.updateMany({ where: { id: ticket.id, rowVersion: ticket.rowVersion }, data: { paidAmount: parsed.data.paidAmount, paymentStatus: status, paymentRecordedAt: new Date(), paymentRecordedByEmployeeId: user.employee?.id, cashierEmployeeId: user.employee?.position.code === "POS-KSR" ? user.employee.id : undefined, paymentExceptionType: null, paymentExceptionReason: null, paymentExceptionApprovedByUserId: null, paymentExceptionApprovedAt: null, rowVersion: { increment: 1 } } });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await tx.auditEvent.create({ data: { actorId: user.id, action: "record_ticket_payment", subjectType: "ServiceTicket", subjectId: ticket.id, beforeJson: { paidAmount: ticket.paidAmount.toString(), paymentStatus: ticket.paymentStatus }, afterJson: { paidAmount: parsed.data.paidAmount, paymentStatus: status } } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath(`/app/servis/${parsed.data.ticketId}`);
  return { success: "Pembayaran tersimpan." };
}
