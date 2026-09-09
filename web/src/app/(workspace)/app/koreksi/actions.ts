"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { Prisma } from "@/generated/prisma/client";
import { prisma } from "@/lib/prisma";
import { canApproveKpiCorrection, canRequestKpiCorrection } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { recalculateKpi } from "@/modules/kpi/recalculate";

export type CorrectionActionState = { error?: string; success?: string };

const requestSchema = z.object({ employeeKpiId: z.string().min(1), itemId: z.string().min(1), actual: z.coerce.number().finite().min(-1_000_000_000).max(1_000_000_000), reason: z.string().trim().min(5).max(2000), evidenceType: z.string().trim().min(1).max(30), evidenceReference: z.string().trim().min(1).max(500) });
const decisionSchema = z.object({ correctionId: z.string().min(1), decision: z.enum(["approved", "rejected"]), reason: z.string().trim().max(1000).optional() });
const payloadSchema = z.object({ items: z.array(z.object({ id: z.string().min(1), code: z.string(), actual: z.number().finite() })).min(1), evidence: z.array(z.object({ type: z.string().min(1), reference: z.string().min(1) })).min(1) });
const officialSources = new Set(["system", "import", "cross_role"]);
const safeMessage = (error: unknown) => error instanceof Error && !error.message.toLowerCase().includes("prisma") ? error.message : "Koreksi KPI tidak dapat diproses.";

export async function requestKpiCorrection(_: CorrectionActionState, formData: FormData): Promise<CorrectionActionState> {
  const user = await requireUser();
  const parsed = requestSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Lengkapi indikator, nilai baru, alasan, dan evidence koreksi." };
  try {
    await prisma.$transaction(async (tx) => {
      await tx.$queryRaw`SELECT id FROM employee_kpis WHERE id = ${parsed.data.employeeKpiId} FOR UPDATE`;
      const kpi = await tx.employeeKpi.findUnique({ where: { id: parsed.data.employeeKpiId }, include: { items: true, manager: { select: { userId: true } } } });
      if (!kpi) throw new Error("KPI tidak ditemukan.");
      if (!canRequestKpiCorrection(user, kpi)) throw new Error("Anda tidak berwenang mengajukan koreksi KPI ini.");
      if (kpi.status !== "LOCKED") throw new Error("Koreksi resmi hanya berlaku untuk KPI yang sudah dikunci.");
      if (await tx.kpiCorrectionRequest.findFirst({ where: { employeeKpiId: kpi.id, status: "PENDING" }, select: { id: true } })) throw new Error("KPI ini sudah memiliki koreksi yang menunggu keputusan.");
      const item = kpi.items.find((candidate) => candidate.id === parsed.data.itemId);
      if (!item) throw new Error("Indikator KPI tidak ditemukan.");
      if (officialSources.has(item.sourceTypeSnapshot.toLowerCase())) throw new Error("Indikator sumber resmi harus dikoreksi pada modul asal, bukan ditimpa manual.");
      const beforeJson = { final_score: kpi.finalScore?.toString() ?? null, rating_code: kpi.ratingCode, rating_label: kpi.ratingLabel, revision_number: kpi.revisionNumber, items: kpi.items.map((candidate) => ({ id: candidate.id, code: candidate.definitionCodeSnapshot, actual: candidate.actualDecimal?.toString() ?? null, weighted_score: candidate.weightedScore?.toString() ?? null })) };
      const afterJson = { items: [{ id: item.id, code: item.definitionCodeSnapshot, actual: parsed.data.actual }], evidence: [{ type: parsed.data.evidenceType, reference: parsed.data.evidenceReference }] };
      const correction = await tx.kpiCorrectionRequest.create({ data: { employeeKpiId: kpi.id, requestedById: user.id, reason: parsed.data.reason, beforeJson, afterJson, status: "PENDING", rowVersionSnapshot: kpi.rowVersion, pendingUniqueKey: kpi.id } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "request_kpi_correction", subjectType: "KpiCorrectionRequest", subjectId: correction.id, beforeJson, afterJson, reason: parsed.data.reason } });
      if (kpi.manager?.userId && kpi.manager.userId !== user.id) await tx.systemNotification.create({ data: { userId: kpi.manager.userId, title: "Permintaan koreksi KPI", body: `${kpi.employeeNameSnapshot} memiliki permintaan koreksi yang menunggu dual authorization.`, type: "kpi_correction_requested", entityType: "KpiCorrectionRequest", entityId: correction.id, actionUrl: "/app/koreksi", dedupeKey: `correction-request:${correction.id}` } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath("/app/koreksi");
  return { success: "Permintaan koreksi diajukan dan menunggu pihak kedua." };
}

export async function decideKpiCorrection(_: CorrectionActionState, formData: FormData): Promise<CorrectionActionState> {
  const user = await requireUser();
  const parsed = decisionSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success || (parsed.data.decision === "rejected" && (!parsed.data.reason || parsed.data.reason.length < 3))) return { error: "Keputusan atau alasan penolakan tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      await tx.$queryRaw`SELECT id FROM kpi_correction_requests WHERE id = ${parsed.data.correctionId} FOR UPDATE`;
      const correction = await tx.kpiCorrectionRequest.findUnique({ where: { id: parsed.data.correctionId }, include: { employeeKpi: { include: { items: true, employee: { select: { userId: true } } } } } });
      if (!correction) throw new Error("Permintaan koreksi tidak ditemukan.");
      if (correction.status !== "PENDING") throw new Error("Permintaan koreksi sudah diputuskan.");
      if (!canApproveKpiCorrection(user, correction)) throw new Error("Dual authorization menolak keputusan oleh pengaju atau pihak yang tidak berwenang.");
      if (parsed.data.decision === "rejected") {
        await tx.kpiCorrectionRequest.update({ where: { id: correction.id }, data: { status: "REJECTED", approvedById: user.id, rejectionReason: parsed.data.reason, pendingUniqueKey: null } });
        await tx.auditEvent.create({ data: { actorId: user.id, action: "reject_kpi_correction", subjectType: "KpiCorrectionRequest", subjectId: correction.id, beforeJson: { status: "PENDING" }, afterJson: { status: "REJECTED" }, reason: parsed.data.reason } });
      } else {
        if (correction.rowVersionSnapshot !== correction.employeeKpi.rowVersion) throw new Error("KPI berubah setelah koreksi diajukan. Ajukan koreksi baru.");
        const payload = payloadSchema.safeParse(correction.afterJson);
        if (!payload.success) throw new Error("Payload koreksi tidak valid.");
        const itemMap = new Map(correction.employeeKpi.items.map((item) => [item.id, item]));
        for (const update of payload.data.items) {
          const item = itemMap.get(update.id);
          if (!item || officialSources.has(item.sourceTypeSnapshot.toLowerCase())) throw new Error("Indikator koreksi tidak valid atau berasal dari sumber resmi.");
          await tx.employeeKpiItem.update({ where: { id: item.id }, data: { actualDecimal: update.actual, status: item.status === "LOCKED" ? "LOCKED" : "VERIFIED", rowVersion: { increment: 1 } } });
          await tx.kpiActualEntry.create({ data: { employeeKpiItemId: item.id, inputById: user.id, actualValue: update.actual, actualJson: { correction_id: correction.id, evidence: payload.data.evidence }, notes: correction.reason } });
        }
        await tx.employeeKpi.update({ where: { id: correction.employeeKpiId }, data: { revisionNumber: { increment: 1 }, rowVersion: { increment: 1 } } });
        await recalculateKpi(tx, correction.employeeKpiId, "correction", user.id);
        const revised = await tx.employeeKpi.findUniqueOrThrow({ where: { id: correction.employeeKpiId }, select: { finalScore: true, ratingCode: true, ratingLabel: true, revisionNumber: true, rowVersion: true } });
        const now = new Date();
        await tx.kpiCorrectionRequest.update({ where: { id: correction.id }, data: { status: "APPLIED", approvedById: user.id, appliedAt: now, pendingUniqueKey: null } });
        await tx.auditEvent.create({ data: { actorId: user.id, action: "apply_kpi_correction", subjectType: "EmployeeKpi", subjectId: correction.employeeKpiId, beforeJson: correction.beforeJson ?? Prisma.JsonNull, afterJson: { final_score: revised.finalScore?.toString() ?? null, rating_code: revised.ratingCode, rating_label: revised.ratingLabel, revision_number: revised.revisionNumber, row_version: revised.rowVersion, evidence: payload.data.evidence }, reason: correction.reason } });
      }
      const ownerId = correction.employeeKpi.employee.userId;
      if (ownerId) await tx.systemNotification.create({ data: { userId: ownerId, title: parsed.data.decision === "approved" ? "Koreksi KPI diterapkan" : "Koreksi KPI ditolak", body: parsed.data.decision === "approved" ? "Koreksi telah disetujui pihak kedua dan versi KPI bertambah." : `Koreksi ditolak: ${parsed.data.reason}`, type: `kpi_correction_${parsed.data.decision}`, entityType: "KpiCorrectionRequest", entityId: correction.id, actionUrl: `/app/kpi-saya/${correction.employeeKpiId}`, dedupeKey: `correction-${parsed.data.decision}:${correction.id}` } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath("/app/koreksi");
  revalidatePath("/app/kpi-saya");
  revalidatePath("/app/tim");
  return { success: parsed.data.decision === "approved" ? "Koreksi diterapkan dan revision counter bertambah." : "Permintaan koreksi ditolak." };
}
