"use server";

import { randomUUID } from "node:crypto";
import { revalidatePath } from "next/cache";
import { z } from "zod";
import type { Prisma } from "@/generated/prisma/client";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { ATTENDANCE_STATUSES } from "@/modules/kpi/operational-metrics";
import { syncEmployeeOperationalKpis } from "@/modules/kpi/operational-sync";
import { stockDifference } from "@/modules/inventory/stock-opname";

export type OperationalActionState = { error?: string; success?: string };

const dateValue = z.string().regex(/^\d{4}-\d{2}-\d{2}$/).transform((value, context) => {
  const date = new Date(`${value}T00:00:00.000Z`);
  if (Number.isNaN(date.valueOf()) || date.toISOString().slice(0, 10) !== value) {
    context.addIssue({ code: "custom", message: "Tanggal tidak valid." });
    return z.NEVER;
  }
  return date;
});
const message = (error: unknown) => error instanceof Error && !error.message.toLowerCase().includes("prisma")
  ? error.message
  : "Data operasional tidak dapat disimpan. Muat ulang lalu coba lagi.";

async function openPeriodFor(db: Prisma.TransactionClient, date: Date) {
  const period = await db.kpiPeriod.findFirst({
    where: { status: "OPEN", startDate: { lte: date }, endDate: { gte: date } },
    orderBy: { year: "desc" },
  });
  if (!period) throw new Error("Tidak ada periode KPI terbuka untuk tanggal tersebut.");
  if (date > new Date()) throw new Error("Aktivitas untuk tanggal mendatang tidak dapat dicatat.");
  return period;
}

const attendanceSchema = z.object({
  employeeId: z.string().min(1),
  attendanceDate: dateValue,
  status: z.enum(ATTENDANCE_STATUSES),
  note: z.string().trim().max(255).optional(),
});

export async function saveAttendance(_: OperationalActionState, formData: FormData): Promise<OperationalActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "attendance.team.manage") && !hasCapability(user, "attendance.manage")) return { error: "Anda tidak berwenang mencatat absensi." };
  const parsed = attendanceSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa karyawan, tanggal, status, dan panjang catatan absensi." };
  if (["permission", "sick_leave", "absent"].includes(parsed.data.status) && !parsed.data.note) return { error: "Catatan wajib diisi untuk izin, sakit, atau alpha." };

  try {
    await prisma.$transaction(async (tx) => {
      const period = await openPeriodFor(tx, parsed.data.attendanceDate);
      const roster = await tx.employeeKpi.findUnique({
        where: { periodId_employeeId: { periodId: period.id, employeeId: parsed.data.employeeId } },
        select: { employeeId: true, supervisorIdSnapshot: true, managerIdSnapshot: true, branchIdSnapshot: true },
      });
      const allowed = user.role === "super_admin" || Boolean(user.employee && roster && roster.employeeId !== user.employee.id && roster.branchIdSnapshot === user.employee.branchId && (
        roster.supervisorIdSnapshot === user.employee.id || (hasCapability(user, "attendance.manage") && roster.managerIdSnapshot === user.employee.id)
      ));
      if (!roster || !allowed) throw new Error("Karyawan bukan bagian dari roster yang boleh Anda kelola.");
      const before = await tx.attendance.findUnique({ where: { employeeId_attendanceDate: { employeeId: parsed.data.employeeId, attendanceDate: parsed.data.attendanceDate } } });
      const attendance = await tx.attendance.upsert({
        where: { employeeId_attendanceDate: { employeeId: parsed.data.employeeId, attendanceDate: parsed.data.attendanceDate } },
        create: { employeeId: parsed.data.employeeId, branchId: roster.branchIdSnapshot, attendanceDate: parsed.data.attendanceDate, status: parsed.data.status, note: parsed.data.note || null, recordedById: user.id },
        update: { branchId: roster.branchIdSnapshot, status: parsed.data.status, note: parsed.data.note || null, recordedById: user.id },
      });
      await syncEmployeeOperationalKpis(tx, parsed.data.employeeId, parsed.data.attendanceDate, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "record_attendance", subjectType: "Attendance", subjectId: attendance.id, beforeJson: before ? { status: before.status, note: before.note } : undefined, afterJson: { status: attendance.status, note: attendance.note }, reason: attendance.note } });
    });
  } catch (error) {
    return { error: message(error) };
  }
  revalidatePath("/app/operasional");
  revalidatePath("/app/tim");
  return { success: "Absensi tersimpan dan fakta KPI diperbarui." };
}

const workLogSchema = z.object({
  workDate: dateValue,
  recordsInput: z.coerce.number().int().min(0).max(1_000_000),
  recordsCorrected: z.coerce.number().int().min(0).max(1_000_000),
  documentsEligible: z.coerce.number().int().min(0).max(1_000_000),
  documentsComplete: z.coerce.number().int().min(0).max(1_000_000),
  reconciliationsTotal: z.coerce.number().int().min(0).max(1_000_000),
  reconciliationsSuccess: z.coerce.number().int().min(0).max(1_000_000),
  notes: z.string().trim().max(1000).optional(),
});

export async function saveWorkLog(_: OperationalActionState, formData: FormData): Promise<OperationalActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "work-logs.manage") || !user.employee) return { error: "Anda tidak berwenang mencatat log kerja." };
  const parsed = workLogSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Semua jumlah harus berupa angka bulat positif yang valid." };
  const data = parsed.data;
  if (data.recordsCorrected > data.recordsInput || data.documentsComplete > data.documentsEligible || data.reconciliationsSuccess > data.reconciliationsTotal) return { error: "Jumlah koreksi/selesai/berhasil tidak boleh melebihi totalnya." };

  try {
    await prisma.$transaction(async (tx) => {
      const period = await openPeriodFor(tx, data.workDate);
      const before = await tx.adminWorkLog.findUnique({ where: { employeeId_workDate: { employeeId: user.employee!.id, workDate: data.workDate } } });
      const log = await tx.adminWorkLog.upsert({
        where: { employeeId_workDate: { employeeId: user.employee!.id, workDate: data.workDate } },
        create: { ...data, notes: data.notes || null, employeeId: user.employee!.id, periodId: period.id, recordedById: user.id },
        update: { ...data, notes: data.notes || null, periodId: period.id, recordedById: user.id },
      });
      await syncEmployeeOperationalKpis(tx, user.employee!.id, data.workDate, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "record_admin_work_log", subjectType: "AdminWorkLog", subjectId: log.id, beforeJson: before ? { workDate: before.workDate, recordsInput: before.recordsInput } : undefined, afterJson: { workDate: log.workDate, recordsInput: log.recordsInput, documentsComplete: log.documentsComplete, reconciliationsSuccess: log.reconciliationsSuccess } } });
    });
  } catch (error) {
    return { error: message(error) };
  }
  revalidatePath("/app/operasional");
  revalidatePath("/app/kpi-saya");
  return { success: "Log kerja tersimpan dan fakta KPI diperbarui." };
}

const complaintSchema = z.object({
  complaintDate: dateValue,
  employeeId: z.string().optional(),
  serviceTicketId: z.string().optional(),
  channel: z.enum(["in_store", "phone", "whatsapp", "google_review"]),
  category: z.string().trim().max(100).optional(),
  severity: z.enum(["low", "medium", "high", "critical"]),
  description: z.string().trim().min(5).max(2000),
});

export async function createComplaint(_: OperationalActionState, formData: FormData): Promise<OperationalActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "complaints.create") && !hasCapability(user, "complaints.manage")) return { error: "Anda tidak berwenang mencatat komplain." };
  const parsed = complaintSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success || (!parsed.data.employeeId && !parsed.data.serviceTicketId)) return { error: "Pilih karyawan atau tiket terkait dan lengkapi data komplain." };
  try {
    await prisma.$transaction(async (tx) => {
      const period = await openPeriodFor(tx, parsed.data.complaintDate);
      const [employee, ticket] = await Promise.all([
        parsed.data.employeeId ? tx.employee.findUnique({ where: { id: parsed.data.employeeId }, select: { id: true, branchId: true } }) : null,
        parsed.data.serviceTicketId ? tx.serviceTicket.findUnique({ where: { id: parsed.data.serviceTicketId }, select: { id: true, branchId: true } }) : null,
      ]);
      if (parsed.data.employeeId && !employee || parsed.data.serviceTicketId && !ticket) throw new Error("Karyawan atau tiket terkait tidak ditemukan.");
      const branchId = employee?.branchId ?? ticket?.branchId;
      if (employee && ticket && employee.branchId !== ticket.branchId) throw new Error("Karyawan dan tiket harus berasal dari cabang yang sama.");
      if (user.role !== "super_admin" && branchId !== user.employee?.branchId) throw new Error("Data komplain berada di luar cabang Anda.");
      const complaint = await tx.complaint.create({ data: { ...parsed.data, employeeId: parsed.data.employeeId || null, serviceTicketId: parsed.data.serviceTicketId || null, category: parsed.data.category || null, code: `CMP-${randomUUID().slice(0, 8).toUpperCase()}`, recordedById: user.id } });
      if (complaint.employeeId) await syncEmployeeOperationalKpis(tx, complaint.employeeId, complaint.complaintDate, user.id);
      if (complaint.employeeId) {
        const roster = await tx.employeeKpi.findUnique({ where: { periodId_employeeId: { periodId: period.id, employeeId: complaint.employeeId } }, select: { supervisorIdSnapshot: true } });
        if (roster?.supervisorIdSnapshot) await syncEmployeeOperationalKpis(tx, roster.supervisorIdSnapshot, complaint.complaintDate, user.id);
      }
      await tx.auditEvent.create({ data: { actorId: user.id, action: "create_complaint", subjectType: "Complaint", subjectId: complaint.id, afterJson: { code: complaint.code, employeeId: complaint.employeeId, serviceTicketId: complaint.serviceTicketId, severity: complaint.severity } } });
    });
  } catch (error) {
    return { error: message(error) };
  }
  revalidatePath("/app/operasional");
  return { success: "Komplain tercatat dan masuk pemantauan." };
}

const coachingSchema = z.object({
  employeeId: z.string().min(1),
  coachingDate: dateValue,
  topic: z.string().trim().min(3).max(200),
  notes: z.string().trim().max(2000).optional(),
  targetMet: z.enum(["", "true", "false"]).optional(),
  followUpDate: z.union([dateValue, z.literal("")]).optional(),
});

export async function saveCoaching(_: OperationalActionState, formData: FormData): Promise<OperationalActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "coaching.manage") || !user.employee) return { error: "Anda tidak berwenang mencatat coaching." };
  const parsed = coachingSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa karyawan, tanggal, topik, dan tindak lanjut coaching." };
  try {
    await prisma.$transaction(async (tx) => {
      const period = await openPeriodFor(tx, parsed.data.coachingDate);
      const roster = await tx.employeeKpi.findUnique({ where: { periodId_employeeId: { periodId: period.id, employeeId: parsed.data.employeeId } }, select: { supervisorIdSnapshot: true, branchIdSnapshot: true } });
      if (!roster || (user.role !== "super_admin" && (roster.supervisorIdSnapshot !== user.employee!.id || roster.branchIdSnapshot !== user.employee!.branchId))) throw new Error("Karyawan bukan anggota tim Anda pada snapshot periode ini.");
      const coaching = await tx.coachingLog.create({ data: { supervisorId: user.employee!.id, employeeId: parsed.data.employeeId, coachingDate: parsed.data.coachingDate, topic: parsed.data.topic, notes: parsed.data.notes || null, targetMet: parsed.data.targetMet ? parsed.data.targetMet === "true" : null, followUpDate: parsed.data.followUpDate || null, periodId: period.id, recordedById: user.id } });
      await syncEmployeeOperationalKpis(tx, user.employee!.id, parsed.data.coachingDate, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "record_coaching", subjectType: "CoachingLog", subjectId: coaching.id, afterJson: { employeeId: coaching.employeeId, coachingDate: coaching.coachingDate, topic: coaching.topic } } });
    });
  } catch (error) {
    return { error: message(error) };
  }
  revalidatePath("/app/operasional");
  return { success: "Coaching tersimpan dan cakupan KPI Supervisor diperbarui." };
}

const stockSchema = z.object({ sparepartId: z.string().min(1), quantity: z.coerce.number().int().min(1).max(1_000_000), note: z.string().trim().min(3).max(1000) });
const createOpnameSchema = z.object({ branchId: z.string().min(1), deadline: z.union([dateValue, z.literal("")]).optional() });
const opnameSchema = z.object({ stockOpnameId: z.string().min(1) });

async function assertStockActor(tx: Prisma.TransactionClient, user: Awaited<ReturnType<typeof requireUser>>, stockOpnameId: string) {
  await tx.$queryRaw`SELECT id FROM stock_opnames WHERE id = ${stockOpnameId} FOR UPDATE`;
  const opname = await tx.stockOpname.findUnique({ where: { id: stockOpnameId }, include: { period: true, items: { include: { sparepart: true }, orderBy: { createdAt: "asc" } } } });
  if (!opname) throw new Error("Stock opname tidak ditemukan.");
  if (!hasCapability(user, "stock-opname.manage")) throw new Error("Anda tidak berwenang mengelola stock opname.");
  if (user.role !== "super_admin" && (!user.employee || opname.branchId !== user.employee.branchId || opname.createdById !== user.id)) throw new Error("Stock opname berada di luar penugasan atau cabang Anda.");
  if (opname.period?.status !== "OPEN") throw new Error("Data opname pada periode yang tidak OPEN hanya dapat dibaca.");
  return opname;
}

async function syncWarehouseKpis(tx: Prisma.TransactionClient, periodId: string, branchId: string, occurredAt: Date, actorId: string) {
  const warehouseEmployees = await tx.employeeKpi.findMany({ where: { periodId, branchIdSnapshot: branchId, positionCodeSnapshot: "POS-GUD" }, select: { employeeId: true } });
  for (const { employeeId } of warehouseEmployees) await syncEmployeeOperationalKpis(tx, employeeId, occurredAt, actorId);
}

export async function restockSparepart(_: OperationalActionState, formData: FormData): Promise<OperationalActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "spareparts.manage")) return { error: "Anda tidak berwenang melakukan restok." };
  const parsed = stockSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Pilih sparepart, isi jumlah positif, dan jelaskan sumber restok." };
  try {
    await prisma.$transaction(async (tx) => {
      await tx.$queryRaw`SELECT id FROM spareparts WHERE id = ${parsed.data.sparepartId} FOR UPDATE`;
      const part = await tx.sparepart.findUnique({ where: { id: parsed.data.sparepartId } });
      if (!part?.branchId) throw new Error("Sparepart cabang tidak ditemukan.");
      if (user.role !== "super_admin" && part.branchId !== user.employee?.branchId) throw new Error("Sparepart berada di luar cabang Anda.");
      const period = await tx.kpiPeriod.findFirst({ where: { status: "OPEN", branches: { some: { branchId: part.branchId } } }, orderBy: [{ year: "desc" }, { month: "desc" }] });
      if (!period) throw new Error("Tidak ada periode OPEN untuk cabang sparepart.");
      const after = part.stockQuantity + parsed.data.quantity;
      await tx.sparepart.update({ where: { id: part.id }, data: { stockQuantity: after } });
      const movement = await tx.stockMovement.create({ data: { sparepartId: part.id, movementType: "restock_in", quantity: parsed.data.quantity, stockBefore: part.stockQuantity, stockAfter: after, referenceType: "manual_restock", note: parsed.data.note, userId: user.id } });
      await syncWarehouseKpis(tx, period.id, part.branchId, period.startDate, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "restock_sparepart", subjectType: "StockMovement", subjectId: movement.id, beforeJson: { stockQuantity: part.stockQuantity }, afterJson: { sparepartId: part.id, quantity: parsed.data.quantity, stockQuantity: after }, reason: parsed.data.note } });
    });
  } catch (error) {
    return { error: message(error) };
  }
  revalidatePath("/app/operasional");
  return { success: "Restok tersimpan pada ledger dan KPI Gudang diperbarui." };
}

export async function createStockOpname(_: OperationalActionState, formData: FormData): Promise<OperationalActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "stock-opname.manage")) return { error: "Anda tidak berwenang membuat stock opname." };
  const parsed = createOpnameSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Cabang atau deadline opname tidak valid." };
  if (user.role !== "super_admin" && parsed.data.branchId !== user.employee?.branchId) return { error: "Cabang opname harus sesuai akun Anda." };
  try {
    await prisma.$transaction(async (tx) => {
      const period = await tx.kpiPeriod.findFirst({ where: { status: "OPEN", branches: { some: { branchId: parsed.data.branchId } } }, orderBy: [{ year: "desc" }, { month: "desc" }] });
      if (!period) throw new Error("Tidak ada periode OPEN untuk cabang tersebut.");
      if (parsed.data.deadline && parsed.data.deadline < period.startDate || parsed.data.deadline && parsed.data.deadline > period.endDate) throw new Error("Deadline harus berada di dalam periode KPI.");
      const existing = await tx.stockOpname.findFirst({ where: { periodId: period.id, branchId: parsed.data.branchId, createdById: user.id, status: { not: "completed" } }, select: { code: true } });
      if (existing) throw new Error(`Selesaikan opname ${existing.code} sebelum membuat sesi baru.`);
      const parts = await tx.sparepart.findMany({ where: { branchId: parsed.data.branchId }, orderBy: { name: "asc" }, select: { id: true, stockQuantity: true } });
      if (!parts.length) throw new Error("Cabang belum memiliki sparepart untuk diopname.");
      const opname = await tx.stockOpname.create({ data: { code: `OPN-${period.year}${String(period.month).padStart(2, "0")}-${randomUUID().slice(0, 6).toUpperCase()}`, periodId: period.id, branchId: parsed.data.branchId, deadline: parsed.data.deadline || null, createdById: user.id, items: { create: parts.map((part) => ({ sparepartId: part.id, systemStock: part.stockQuantity })) } } });
      await syncWarehouseKpis(tx, period.id, parsed.data.branchId, period.startDate, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "create_stock_opname", subjectType: "StockOpname", subjectId: opname.id, afterJson: { code: opname.code, periodId: period.id, branchId: parsed.data.branchId, itemCount: parts.length, deadline: opname.deadline } } });
    });
  } catch (error) {
    return { error: message(error) };
  }
  revalidatePath("/app/operasional");
  return { success: "Snapshot stok dibuat. Isi seluruh hitungan fisik sebelum menyelesaikan opname." };
}

export async function saveStockOpnameCounts(_: OperationalActionState, formData: FormData): Promise<OperationalActionState> {
  const user = await requireUser();
  const parsed = opnameSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Stock opname tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const opname = await assertStockActor(tx, user, parsed.data.stockOpnameId);
      if (opname.status === "completed") throw new Error("Stock opname yang selesai tidak dapat diubah.");
      const counts = opname.items.map((item) => {
        const raw = formData.get(`physicalStock:${item.id}`);
        const physicalStock = typeof raw === "string" && /^\d+$/.test(raw) ? Number(raw) : NaN;
        if (!Number.isSafeInteger(physicalStock) || physicalStock < 0 || physicalStock > 1_000_000_000) throw new Error(`Stok fisik ${item.sparepart.name} tidak valid.`);
        return { id: item.id, physicalStock };
      });
      for (const count of counts) await tx.stockOpnameItem.update({ where: { id: count.id }, data: { physicalStock: count.physicalStock } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "save_stock_opname_counts", subjectType: "StockOpname", subjectId: opname.id, afterJson: { countedItems: counts.length } } });
    });
  } catch (error) {
    return { error: message(error) };
  }
  revalidatePath("/app/operasional");
  return { success: "Hitungan fisik tersimpan." };
}

export async function completeStockOpname(_: OperationalActionState, formData: FormData): Promise<OperationalActionState> {
  const user = await requireUser();
  const parsed = opnameSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Stock opname tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const opname = await assertStockActor(tx, user, parsed.data.stockOpnameId);
      if (opname.status === "completed") throw new Error("Stock opname sudah diselesaikan.");
      if (!opname.items.length || opname.items.some((item) => item.physicalStock === null)) throw new Error("Isi stok fisik seluruh item sebelum menyelesaikan opname.");
      let adjusted = 0;
      for (const item of opname.items) {
        await tx.$queryRaw`SELECT id FROM spareparts WHERE id = ${item.sparepartId} FOR UPDATE`;
        const part = await tx.sparepart.findUniqueOrThrow({ where: { id: item.sparepartId } });
        if (part.branchId !== opname.branchId) throw new Error("Item stok berada di luar cabang opname.");
        let difference: number;
        try { difference = stockDifference(item.systemStock, part.stockQuantity, item.physicalStock!); } catch { throw new Error(`Stok ${part.name} berubah sejak snapshot atau hitungan fisik tidak valid. Buat opname baru.`); }
        await tx.stockOpnameItem.update({ where: { id: item.id }, data: { difference, isCounted: true } });
        if (difference !== 0) {
          await tx.sparepart.update({ where: { id: part.id }, data: { stockQuantity: item.physicalStock! } });
          await tx.stockMovement.create({ data: { sparepartId: part.id, movementType: "opname_adjustment", quantity: difference, stockBefore: part.stockQuantity, stockAfter: item.physicalStock!, referenceType: "stock_opname", referenceId: opname.id, note: `Penyesuaian hasil opname ${opname.code}`, userId: user.id } });
          adjusted += 1;
        }
      }
      const completedAt = new Date();
      await tx.stockOpname.update({ where: { id: opname.id }, data: { status: "completed", completedAt } });
      await syncWarehouseKpis(tx, opname.periodId!, opname.branchId, opname.period!.startDate, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "complete_stock_opname", subjectType: "StockOpname", subjectId: opname.id, beforeJson: { status: opname.status }, afterJson: { status: "completed", countedItems: opname.items.length, adjustedItems: adjusted, completedAt } } });
    });
  } catch (error) {
    return { error: message(error) };
  }
  revalidatePath("/app/operasional");
  return { success: "Opname selesai, stok disesuaikan, dan KPI Gudang dihitung ulang." };
}

const reportSchema = z.object({ reportId: z.string().min(1) });

export async function submitReport(_: OperationalActionState, formData: FormData): Promise<OperationalActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "reports.submit") || !user.employee) return { error: "Anda tidak berwenang mengirim laporan ini." };
  const parsed = reportSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Laporan tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const report = await tx.reportSubmission.findUnique({ where: { id: parsed.data.reportId }, include: { period: true } });
      if (!report || report.employeeId !== user.employee!.id) throw new Error("Laporan bukan milik akun Anda.");
      if (report.submittedAt) throw new Error("Laporan sudah pernah dikirim.");
      if (["LOCKED", "CANCELLED"].includes(report.period.status)) throw new Error("Periode laporan sudah terkunci atau dibatalkan.");
      let snapshot: Prisma.InputJsonValue;
      if (report.reportType === "admin_daily") {
        const workLog = await tx.adminWorkLog.findUnique({ where: { employeeId_workDate: { employeeId: report.employeeId, workDate: report.reportDate } } });
        if (!workLog) throw new Error("Isi work-log pada tanggal laporan sebelum mengirim.");
        snapshot = { work_log_id: workLog.id, records_input: workLog.recordsInput, records_corrected: workLog.recordsCorrected, documents_complete: workLog.documentsComplete, reconciliations_success: workLog.reconciliationsSuccess };
      } else if (report.reportType === "admin_monthly") {
        const logs = await tx.adminWorkLog.findMany({ where: { periodId: report.periodId, employeeId: report.employeeId }, orderBy: { workDate: "asc" } });
        snapshot = logs.map((log) => ({ id: log.id, work_date: log.workDate.toISOString().slice(0, 10), records_input: log.recordsInput, documents_complete: log.documentsComplete }));
      } else {
        const team = await tx.employeeKpi.findMany({ where: { periodId: report.periodId, supervisorIdSnapshot: report.employeeId, eligibility: "full" }, select: { id: true, status: true, finalScore: true } });
        if (team.some((kpi) => !["PENDING_APPROVAL", "APPROVED", "LOCKED"].includes(kpi.status))) throw new Error("Teruskan seluruh KPI tim eligible kepada Manajer terlebih dahulu.");
        snapshot = team.map((kpi) => ({ id: kpi.id, status: kpi.status, final_score: kpi.finalScore?.toString() ?? null }));
      }
      const now = new Date();
      const updated = await tx.reportSubmission.updateMany({ where: { id: report.id, submittedAt: null }, data: { submittedAt: now, isOnTime: now <= report.deadlineAt, status: "submitted", contentSnapshot: snapshot, submittedById: user.id } });
      if (updated.count !== 1) throw new Error("Laporan telah berubah. Muat ulang halaman.");
      await syncEmployeeOperationalKpis(tx, report.employeeId, report.reportDate, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "submit_report", subjectType: "ReportSubmission", subjectId: report.id, afterJson: { reportType: report.reportType, submittedAt: now, isOnTime: now <= report.deadlineAt } } });
    });
  } catch (error) {
    return { error: message(error) };
  }
  revalidatePath("/app/operasional");
  revalidatePath("/app/kpi-saya");
  return { success: "Laporan berhasil dikirim dan KPI diperbarui." };
}
