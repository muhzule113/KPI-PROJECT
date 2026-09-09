"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import type { Prisma } from "@/generated/prisma/client";
import { prisma } from "@/lib/prisma";
import { canManageKpi, canReviewKpi } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { recalculateKpi } from "@/modules/kpi/recalculate";
import { syncEmployeeOperationalKpis } from "@/modules/kpi/operational-sync";
import { assertExpectedVersion, assertKpiTransition } from "@/modules/kpi/workflow";

export type TeamActionState = { error?: string; success?: string };

const baseSchema = z.object({
  kpiId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
});

const errorMessage = (error: unknown) =>
  error instanceof Error && !error.message.toLowerCase().includes("prisma")
    ? error.message
    : "Permintaan tidak dapat diproses. Muat ulang lalu coba kembali.";

function mutableStatus(status: string, managerDirect: boolean) {
  return managerDirect
    ? ["SUBMITTED", "UNDER_REVIEW", "REVISION_REQUIRED", "VERIFIED", "PENDING_APPROVAL"].includes(status)
    : ["SUBMITTED", "UNDER_REVIEW", "REVISION_REQUIRED"].includes(status);
}

async function reviewFor(tx: Prisma.TransactionClient, kpiId: string, reviewerId: string) {
  const existing = await tx.kpiReview.findFirst({ where: { employeeKpiId: kpiId, reviewerId } });
  return existing ?? tx.kpiReview.create({ data: { employeeKpiId: kpiId, reviewerId } });
}

const itemSchema = baseSchema.extend({
  itemId: z.string().min(1),
  itemVersion: z.coerce.number().int().positive(),
  decision: z.enum(["valid", "revision"]),
  reason: z.string().trim().max(1000).optional(),
});

export async function reviewItem(_: TeamActionState, formData: FormData): Promise<TeamActionState> {
  const user = await requireUser();
  const parsed = itemSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Keputusan indikator tidak valid." };
  if (parsed.data.decision === "revision" && !parsed.data.reason) return { error: "Alasan revisi wajib diisi." };

  try {
    await prisma.$transaction(async (tx) => {
      const kpi = await tx.employeeKpi.findUnique({ where: { id: parsed.data.kpiId } });
      if (!kpi) throw new Error("KPI tidak ditemukan.");
      const managerDirect = canManageKpi(user, kpi) && kpi.positionCodeSnapshot === "POS-SPV";
      if (!canReviewKpi(user, kpi) && !managerDirect) throw new Error("Anda tidak berwenang menilai KPI ini.");
      if (!mutableStatus(kpi.status, managerDirect)) throw new Error("KPI pada tahap ini tidak dapat dinilai.");
      assertExpectedVersion(kpi.rowVersion, parsed.data.rowVersion);
      const item = await tx.employeeKpiItem.findFirst({ where: { id: parsed.data.itemId, employeeKpiId: kpi.id } });
      if (!item) throw new Error("Indikator tidak ditemukan.");
      if (item.formulaKeySnapshot === "rubric") throw new Error("Gunakan penilaian kriteria untuk indikator rubrik.");
      assertExpectedVersion(item.rowVersion, parsed.data.itemVersion);
      const review = await reviewFor(tx, kpi.id, user.id);
      const nextStatus = parsed.data.decision === "valid" ? (managerDirect ? "ASSESSED" : "VERIFIED") : "REVISION_REQUIRED";
      const updated = await tx.employeeKpiItem.updateMany({
        where: { id: item.id, rowVersion: item.rowVersion },
        data: {
          status: nextStatus,
          managerDecision: managerDirect ? (parsed.data.decision === "valid" ? "valid" : "needs_correction") : undefined,
          managerNote: managerDirect ? parsed.data.reason ?? null : undefined,
          managerDecidedById: managerDirect ? user.id : undefined,
          managerDecidedAt: managerDirect ? new Date() : undefined,
          rowVersion: { increment: 1 },
        },
      });
      if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
      await tx.kpiReviewItem.upsert({
        where: { kpiReviewId_employeeKpiItemId: { kpiReviewId: review.id, employeeKpiItemId: item.id } },
        create: { kpiReviewId: review.id, employeeKpiItemId: item.id, decision: parsed.data.decision, supervisorNote: parsed.data.reason, reason: parsed.data.reason },
        update: { decision: parsed.data.decision, supervisorNote: parsed.data.reason, reason: parsed.data.reason },
      });
      if (kpi.status === "SUBMITTED") await tx.employeeKpi.update({ where: { id: kpi.id }, data: { status: "UNDER_REVIEW", rowVersion: { increment: 1 } } });
      else await tx.employeeKpi.update({ where: { id: kpi.id }, data: { rowVersion: { increment: 1 } } });
      await recalculateKpi(tx, kpi.id, managerDirect ? "manager_review" : "supervisor_review", user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: managerDirect ? "manager_assess_kpi_item" : "review_kpi_item", subjectType: "EmployeeKpiItem", subjectId: item.id, beforeJson: { status: item.status }, afterJson: { status: nextStatus, decision: parsed.data.decision }, reason: parsed.data.reason } });
    });
  } catch (error) {
    return { error: errorMessage(error) };
  }
  revalidatePath(`/app/tim/${parsed.data.kpiId}`);
  revalidatePath("/app/tim");
  return { success: parsed.data.decision === "valid" ? "Indikator diterima." : "Indikator ditandai untuk revisi." };
}

const rubricSchema = baseSchema.extend({ itemId: z.string().min(1), itemVersion: z.coerce.number().int().positive() });

type RubricCriterion = { id: string; criterionText: string; points: number };

function criteriaFrom(value: Prisma.JsonValue | null): RubricCriterion[] {
  if (!value || Array.isArray(value) || typeof value !== "object" || !Array.isArray(value.criteria)) return [];
  return value.criteria.flatMap((criterion) => {
    if (!criterion || Array.isArray(criterion) || typeof criterion !== "object") return [];
    const id = criterion.id;
    const text = criterion.criterion_text;
    const points = Number(criterion.points);
    return (typeof id === "string" || typeof id === "number") && typeof text === "string" && Number.isFinite(points)
      ? [{ id: String(id), criterionText: text, points }]
      : [];
  });
}

export async function assessRubric(_: TeamActionState, formData: FormData): Promise<TeamActionState> {
  const user = await requireUser();
  const parsed = rubricSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Penilaian rubrik tidak valid." };
  const fulfilled = new Set(formData.getAll("criterion").map(String));

  try {
    await prisma.$transaction(async (tx) => {
      const kpi = await tx.employeeKpi.findUnique({ where: { id: parsed.data.kpiId } });
      if (!kpi) throw new Error("KPI tidak ditemukan.");
      const managerDirect = canManageKpi(user, kpi) && kpi.positionCodeSnapshot === "POS-SPV";
      if (!canReviewKpi(user, kpi) && !managerDirect) throw new Error("Anda tidak berwenang menilai KPI ini.");
      if (!mutableStatus(kpi.status, managerDirect)) throw new Error("KPI pada tahap ini tidak dapat dinilai.");
      assertExpectedVersion(kpi.rowVersion, parsed.data.rowVersion);
      const item = await tx.employeeKpiItem.findFirst({ where: { id: parsed.data.itemId, employeeKpiId: kpi.id } });
      if (!item || item.formulaKeySnapshot !== "rubric") throw new Error("Indikator rubrik tidak ditemukan.");
      assertExpectedVersion(item.rowVersion, parsed.data.itemVersion);
      const criteria = criteriaFrom(item.rubricSnapshot);
      if (!criteria.length) throw new Error("Kriteria rubrik belum dikonfigurasi.");
      if ([...fulfilled].some((id) => !criteria.some((criterion) => criterion.id === id))) throw new Error("Kriteria rubrik tidak valid.");
      const total = criteria.reduce((sum, criterion) => sum + criterion.points, 0);
      const earned = criteria.filter((criterion) => fulfilled.has(criterion.id)).reduce((sum, criterion) => sum + criterion.points, 0);
      if (total <= 0) throw new Error("Total poin rubrik tidak valid.");
      const achievement = Math.min(100, (earned / total) * 100);
      const review = await reviewFor(tx, kpi.id, user.id);
      const previous = await tx.kpiAssessment.findFirst({ where: { employeeKpiItemId: item.id }, orderBy: { createdAt: "desc" } });
      const assessment = previous
        ? await tx.kpiAssessment.update({ where: { id: previous.id }, data: { kpiReviewId: review.id, assessedById: user.id, scorePoints: earned, totalPoints: total, calculatedAchievement: achievement } })
        : await tx.kpiAssessment.create({ data: { employeeKpiItemId: item.id, kpiReviewId: review.id, assessedById: user.id, scorePoints: earned, totalPoints: total, calculatedAchievement: achievement } });
      await tx.kpiAssessmentAnswer.deleteMany({ where: { kpiAssessmentId: assessment.id } });
      await tx.kpiAssessmentAnswer.createMany({ data: criteria.map((criterion) => ({ kpiAssessmentId: assessment.id, criterionId: criterion.id, criterionText: criterion.criterionText, isFulfilled: fulfilled.has(criterion.id), pointsEarned: fulfilled.has(criterion.id) ? criterion.points : 0 })) });
      await tx.employeeKpiItem.update({ where: { id: item.id }, data: { actualDecimal: achievement, status: managerDirect ? "ASSESSED" : "VERIFIED", managerDecision: managerDirect ? "valid" : undefined, managerDecidedById: managerDirect ? user.id : undefined, managerDecidedAt: managerDirect ? new Date() : undefined, rowVersion: { increment: 1 } } });
      await tx.employeeKpi.update({ where: { id: kpi.id }, data: { status: kpi.status === "SUBMITTED" ? "UNDER_REVIEW" : undefined, rowVersion: { increment: 1 } } });
      await recalculateKpi(tx, kpi.id, managerDirect ? "manager_rubric" : "supervisor_rubric", user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "assess_kpi_rubric", subjectType: "EmployeeKpiItem", subjectId: item.id, beforeJson: { status: item.status }, afterJson: { status: managerDirect ? "ASSESSED" : "VERIFIED", achievement } } });
    });
  } catch (error) {
    return { error: errorMessage(error) };
  }
  revalidatePath(`/app/tim/${parsed.data.kpiId}`);
  return { success: "Penilaian rubrik tersimpan." };
}

const outcomeSchema = baseSchema.extend({ action: z.enum(["forward", "revision"]), reason: z.string().trim().max(1000).optional() });

export async function finishReview(_: TeamActionState, formData: FormData): Promise<TeamActionState> {
  const user = await requireUser();
  const parsed = outcomeSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Permintaan review tidak valid." };
  if (parsed.data.action === "revision" && !parsed.data.reason) return { error: "Alasan revisi wajib diisi." };

  try {
    await prisma.$transaction(async (tx) => {
      const kpi = await tx.employeeKpi.findUnique({ where: { id: parsed.data.kpiId }, include: { items: true, employee: { select: { userId: true } }, manager: { select: { userId: true } }, period: true } });
      if (!kpi || !canReviewKpi(user, kpi)) throw new Error("Anda tidak berwenang mereview KPI ini.");
      assertExpectedVersion(kpi.rowVersion, parsed.data.rowVersion);
      const review = await reviewFor(tx, kpi.id, user.id);

      if (parsed.data.action === "revision") {
        const revisionItems = kpi.items.filter((item) => item.status === "REVISION_REQUIRED");
        if (!revisionItems.length) throw new Error("Tandai setidaknya satu indikator yang perlu direvisi.");
        if (kpi.status !== "REVISION_REQUIRED") assertKpiTransition(kpi.status, "REVISION_REQUIRED");
        await tx.employeeKpi.update({ where: { id: kpi.id }, data: { status: "REVISION_REQUIRED", revisionNumber: { increment: 1 }, rowVersion: { increment: 1 } } });
        await tx.kpiReview.update({ where: { id: review.id }, data: { status: "REVISION_REQUESTED", notes: parsed.data.reason } });
        await tx.auditEvent.create({ data: { actorId: user.id, action: "request_kpi_revision", subjectType: "EmployeeKpi", subjectId: kpi.id, beforeJson: { status: kpi.status }, afterJson: { status: "REVISION_REQUIRED", revisionNumber: kpi.revisionNumber + 1 }, reason: parsed.data.reason } });
        if (kpi.employee.userId) await tx.systemNotification.create({ data: { userId: kpi.employee.userId, title: `KPI perlu revisi: ${kpi.period.name}`, body: parsed.data.reason!, type: "revision_required", entityType: "EmployeeKpi", entityId: kpi.id, actionUrl: `/app/kpi-saya/${kpi.id}`, dedupeKey: `kpi-revision:${kpi.id}:${kpi.revisionNumber + 1}` } });
        return;
      }

      const unfinished = kpi.items.filter((item) => !["VERIFIED", "ASSESSED"].includes(item.status));
      if (unfinished.length) throw new Error(`Selesaikan indikator: ${unfinished.map((item) => item.nameSnapshot).join(", ")}.`);
      assertKpiTransition(kpi.status, "PENDING_APPROVAL");
      const result = await recalculateKpi(tx, kpi.id, "review", user.id);
      if (result.finalScore == null) throw new Error("Ada indikator yang belum dapat dihitung.");
      await tx.employeeKpi.update({ where: { id: kpi.id }, data: { status: "PENDING_APPROVAL", verifiedAt: new Date(), rowVersion: { increment: 1 } } });
      await tx.kpiReview.update({ where: { id: review.id }, data: { status: "VERIFIED", notes: parsed.data.reason } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "forward_kpi_to_manager", subjectType: "EmployeeKpi", subjectId: kpi.id, beforeJson: { status: kpi.status }, afterJson: { status: "PENDING_APPROVAL", finalScore: result.finalScore }, reason: parsed.data.reason } });
      if (kpi.manager?.userId) await tx.systemNotification.create({ data: { userId: kpi.manager.userId, title: `KPI menunggu persetujuan: ${kpi.employeeNameSnapshot}`, body: `Rekap KPI ${kpi.period.name} sudah lengkap.`, type: "kpi_verified", entityType: "EmployeeKpi", entityId: kpi.id, actionUrl: `/app/tim/${kpi.id}`, dedupeKey: `kpi-verified:${kpi.id}:${kpi.revisionNumber}` } });
    });
  } catch (error) {
    return { error: errorMessage(error) };
  }
  revalidatePath(`/app/tim/${parsed.data.kpiId}`);
  revalidatePath("/app/tim");
  revalidatePath("/app");
  return { success: parsed.data.action === "forward" ? "KPI diteruskan kepada Manajer." : "Permintaan revisi dikirim." };
}

const approvalSchema = baseSchema.extend({ action: z.enum(["approve", "return"]), reason: z.string().trim().max(1000).optional() });

export async function decideKpi(_: TeamActionState, formData: FormData): Promise<TeamActionState> {
  const user = await requireUser();
  const parsed = approvalSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Keputusan persetujuan tidak valid." };
  const selectedItems = new Set(formData.getAll("itemId").map(String));
  if (parsed.data.action === "return" && (!parsed.data.reason || !selectedItems.size)) return { error: "Pilih indikator dan isi alasan pengembalian." };

  try {
    await prisma.$transaction(async (tx) => {
      const kpi = await tx.employeeKpi.findUnique({ where: { id: parsed.data.kpiId }, include: { items: true, employee: { select: { userId: true } }, supervisor: { select: { userId: true } }, period: true } });
      if (!kpi || !canManageKpi(user, kpi)) throw new Error("Anda tidak berwenang menyetujui KPI ini.");
      assertExpectedVersion(kpi.rowVersion, parsed.data.rowVersion);

      if (parsed.data.action === "return") {
        if (kpi.status !== "PENDING_APPROVAL") throw new Error("KPI ini tidak berada dalam antrean persetujuan.");
        const validIds = kpi.items.filter((item) => selectedItems.has(item.id)).map((item) => item.id);
        if (validIds.length !== selectedItems.size) throw new Error("Pilihan indikator tidak valid.");
        assertKpiTransition(kpi.status, "UNDER_REVIEW");
        await tx.employeeKpiItem.updateMany({ where: { id: { in: validIds } }, data: { status: "REVISION_REQUIRED", managerDecision: "needs_correction", managerNote: parsed.data.reason, managerDecidedById: user.id, managerDecidedAt: new Date(), rowVersion: { increment: 1 } } });
        await tx.kpiDailyEntry.updateMany({ where: { employeeKpiItemId: { in: validIds } }, data: { entryStatus: "revision_required", supervisorStatus: "PENDING", managerStatus: "PENDING", managerNote: parsed.data.reason, rowVersion: { increment: 1 } } });
        await tx.employeeKpi.update({ where: { id: kpi.id }, data: { status: "UNDER_REVIEW", verifiedAt: null, rowVersion: { increment: 1 } } });
        if (kpi.supervisorIdSnapshot) await syncEmployeeOperationalKpis(tx, kpi.supervisorIdSnapshot, new Date(), user.id);
        await tx.kpiApproval.create({ data: { employeeKpiId: kpi.id, approverId: user.id, action: "returned", reason: parsed.data.reason, rowVersionSnapshot: kpi.rowVersion + 1 } });
        await tx.auditEvent.create({ data: { actorId: user.id, action: "return_kpi_to_supervisor", subjectType: "EmployeeKpi", subjectId: kpi.id, beforeJson: { status: kpi.status }, afterJson: { status: "UNDER_REVIEW", itemIds: validIds }, reason: parsed.data.reason } });
        if (kpi.supervisor?.userId) await tx.systemNotification.create({ data: { userId: kpi.supervisor.userId, title: `KPI dikembalikan: ${kpi.employeeNameSnapshot}`, body: parsed.data.reason!, type: "kpi_returned", entityType: "EmployeeKpi", entityId: kpi.id, actionUrl: `/app/tim/${kpi.id}`, dedupeKey: `kpi-returned:${kpi.id}:${kpi.rowVersion + 1}` } });
        return;
      }

      let currentStatus = kpi.status;
      if (kpi.positionCodeSnapshot === "POS-SPV" && ["SUBMITTED", "UNDER_REVIEW", "REVISION_REQUIRED", "VERIFIED"].includes(currentStatus)) {
        const unfinished = kpi.items.filter((item) => !["VERIFIED", "ASSESSED"].includes(item.status));
        if (unfinished.length) throw new Error(`Selesaikan indikator: ${unfinished.map((item) => item.nameSnapshot).join(", ")}.`);
        assertKpiTransition(currentStatus, "PENDING_APPROVAL");
        await tx.employeeKpi.update({ where: { id: kpi.id }, data: { status: "PENDING_APPROVAL", verifiedAt: new Date() } });
        currentStatus = "PENDING_APPROVAL";
      }
      if (currentStatus !== "PENDING_APPROVAL") throw new Error("KPI ini tidak berada dalam antrean persetujuan.");
      if (kpi.items.some((item) => !["VERIFIED", "ASSESSED", "LOCKED"].includes(item.status) || ["needs_correction", "data_exception"].includes(item.managerDecision ?? ""))) throw new Error("Masih ada indikator yang belum selesai atau perlu koreksi.");
      const result = await recalculateKpi(tx, kpi.id, "approval", user.id);
      if (result.finalScore == null) throw new Error("Ada indikator yang belum dapat dihitung.");
      assertKpiTransition("PENDING_APPROVAL", "APPROVED");
      await tx.employeeKpi.update({ where: { id: kpi.id }, data: { status: "APPROVED", approvedAt: new Date(), rowVersion: { increment: 1 } } });
      if (kpi.supervisorIdSnapshot) await syncEmployeeOperationalKpis(tx, kpi.supervisorIdSnapshot, new Date(), user.id);
      await tx.kpiApproval.create({ data: { employeeKpiId: kpi.id, approverId: user.id, action: "approved", reason: parsed.data.reason, rowVersionSnapshot: kpi.rowVersion + 1 } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "approve_kpi", subjectType: "EmployeeKpi", subjectId: kpi.id, beforeJson: { status: currentStatus }, afterJson: { status: "APPROVED", finalScore: result.finalScore }, reason: parsed.data.reason } });
      if (kpi.employee.userId) await tx.systemNotification.create({ data: { userId: kpi.employee.userId, title: `KPI disetujui: ${kpi.period.name}`, body: "Nilai akan terlihat setelah periode diterbitkan.", type: "kpi_approved", entityType: "EmployeeKpi", entityId: kpi.id, actionUrl: `/app/kpi-saya/${kpi.id}`, dedupeKey: `kpi-approved:${kpi.id}` } });
    });
  } catch (error) {
    return { error: errorMessage(error) };
  }
  revalidatePath(`/app/tim/${parsed.data.kpiId}`);
  revalidatePath("/app/tim");
  revalidatePath("/app");
  return { success: parsed.data.action === "approve" ? "KPI disetujui dan menunggu publikasi." : "KPI dikembalikan kepada Supervisor." };
}
