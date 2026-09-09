import { Prisma } from "@/generated/prisma/client";
import type { AccessProfile, KpiSubject } from "@/modules/access/policy";
import { canFinalizeMonthly } from "@/modules/access/policy";
import { isoDate, todayInMakassar } from "@/lib/date";
import { notifyUsers } from "@/modules/notifications";
import { calculateFinalScore, calculateMonthlyIndicator, type MonthlyIndicatorResult } from "@/modules/kpi/calculation";
import { ratingBandsFromSnapshot, ratingForScore } from "@/modules/kpi/period";
import { finalizeReadiness, reopenMonthlyResult } from "@/modules/kpi/workflow";

export async function lockMonthlyKpi(tx: Prisma.TransactionClient, monthlyKpiId: string) {
  // An idempotent UPDATE takes a row lock without relying on raw driver queries.
  const locked = await tx.monthlyKpi.updateMany({ where: { id: monthlyKpiId }, data: { rowVersion: { increment: 0 } } });
  if (locked.count !== 1) throw new Error("KPI bulanan tidak ditemukan.");
}

export function kpiSubjectFromSnapshot(kpi: {
  employeeId: string;
  subjectRoleSnapshot: string;
  branchIdSnapshot: string;
  supervisorIdSnapshot: string | null;
  managerIdSnapshot: string;
  status: string;
}): KpiSubject {
  if (kpi.subjectRoleSnapshot !== "EMPLOYEE" && kpi.subjectRoleSnapshot !== "SUPERVISOR") throw new Error("Role pemilik KPI tidak valid.");
  if (!["IN_PROGRESS", "READY", "FINALIZED", "REOPENED"].includes(kpi.status)) throw new Error("Status KPI bulanan tidak valid.");
  return {
    employeeId: kpi.employeeId,
    role: kpi.subjectRoleSnapshot,
    branchId: kpi.branchIdSnapshot,
    supervisorId: kpi.supervisorIdSnapshot,
    managerId: kpi.managerIdSnapshot,
    status: kpi.status as KpiSubject["status"],
  };
}

export async function recalculateMonthlyKpi(tx: Prisma.TransactionClient, monthlyKpiId: string, now = new Date()) {
  await lockMonthlyKpi(tx, monthlyKpiId);
  const kpi = await tx.monthlyKpi.findUniqueOrThrow({
    where: { id: monthlyKpiId },
    include: {
      period: true,
      dailySheets: { select: { status: true, effectiveWorkStatus: true } },
      items: {
        orderBy: { sortOrderSnapshot: "asc" },
        include: {
          dailyValues: {
            where: { dailySheet: { status: "APPROVED", effectiveWorkStatus: "WORKED" } },
            select: { effectiveValue: true },
          },
        },
      },
    },
  });
  if (kpi.status === "FINALIZED") return { finalScore: kpi.finalScore?.toFixed(2) ?? null, workedDays: kpi.dailySheets.filter((sheet) => sheet.effectiveWorkStatus === "WORKED").length };

  const results: MonthlyIndicatorResult[] = [];
  for (const item of kpi.items) {
    const result = calculateMonthlyIndicator({
      kind: item.kindSnapshot,
      aggregation: item.aggregationSnapshot,
      direction: item.directionSnapshot,
      values: item.dailyValues.flatMap((value) => value.effectiveValue === null ? [] : [value.effectiveValue.toString()]),
      target: item.targetSnapshot.toString(),
      failureLimit: item.failureLimitSnapshot?.toString() ?? null,
      weight: item.weightSnapshot.toString(),
    });
    results.push(result);
    await tx.monthlyKpiItem.update({
      where: { id: item.id },
      data: {
        actual: result.actual,
        achievementPercentage: result.achievement,
        weightedScore: result.weightedScore,
        calculationStatus: result.status,
        calculationNote: result.note ?? null,
      },
    });
  }

  const finalScore = calculateFinalScore(results);
  const rating = finalScore === null ? null : ratingForScore(finalScore, ratingBandsFromSnapshot(kpi.ratingBandsSnapshot));
  const workedDays = kpi.dailySheets.filter((sheet) => sheet.status === "APPROVED" && sheet.effectiveWorkStatus === "WORKED").length;
  const allSheetsApproved = kpi.dailySheets.length > 0 && kpi.dailySheets.every((sheet) => sheet.status === "APPROVED");
  const calculable = workedDays === 0 || results.every((result) => result.status === "CALCULATED");
  const ready = todayInMakassar(now) > isoDate(kpi.period.endDate) && allSheetsApproved && calculable;
  const status = ready ? "READY" : kpi.status === "REOPENED" ? "REOPENED" : "IN_PROGRESS";

  await tx.monthlyKpi.update({
    where: { id: kpi.id },
    data: { finalScore, ratingCode: rating?.code ?? null, ratingLabel: rating?.label ?? null, status },
  });
  return { finalScore, workedDays, status, results };
}

export async function finalizeMonthlyKpi(
  tx: Prisma.TransactionClient,
  actor: AccessProfile,
  input: { monthlyKpiId: string; rowVersion: number; noScoreReason?: string },
  now = new Date(),
) {
  const before = await tx.monthlyKpi.findUnique({ where: { id: input.monthlyKpiId } });
  if (!before) throw new Error("KPI bulanan tidak ditemukan.");
  if (!canFinalizeMonthly(actor, kpiSubjectFromSnapshot(before))) throw new Error("Anda tidak berwenang memfinalkan KPI ini.");
  if (before.rowVersion !== input.rowVersion) throw new Error("Data telah berubah. Muat ulang sebelum memfinalkan.");
  if (before.status === "FINALIZED") throw new Error("KPI ini sudah difinalkan.");

  await recalculateMonthlyKpi(tx, before.id, now);
  const kpi = await tx.monthlyKpi.findUniqueOrThrow({
    where: { id: before.id },
    include: { period: true, employee: { include: { user: true } }, dailySheets: true, items: true },
  });
  const workedDays = kpi.dailySheets.filter((sheet) => sheet.status === "APPROVED" && sheet.effectiveWorkStatus === "WORKED").length;
  const readiness = finalizeReadiness({
    today: todayInMakassar(now),
    periodEndDate: isoDate(kpi.period.endDate),
    sheetStatuses: kpi.dailySheets.map((sheet) => sheet.status),
    workedDays,
    calculationStatuses: kpi.items.map((item) => item.calculationStatus),
    noScoreReason: input.noScoreReason,
  });
  if (!readiness.ok) throw new Error(readiness.reason);

  const withoutScore = readiness.withoutScore === true;
  const updated = await tx.monthlyKpi.updateMany({
    where: { id: kpi.id, rowVersion: input.rowVersion },
    data: {
      status: "FINALIZED",
      finalScore: withoutScore ? null : kpi.finalScore,
      ratingCode: withoutScore ? null : kpi.ratingCode,
      ratingLabel: withoutScore ? null : kpi.ratingLabel,
      noScoreReason: withoutScore ? input.noScoreReason!.trim() : null,
      finalizedById: actor.userId,
      finalizedAt: now,
      rowVersion: { increment: 1 },
    },
  });
  if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum memfinalkan.");
  await tx.auditEvent.create({
    data: {
      actorId: actor.userId,
      action: "finalize_monthly_kpi",
      subjectType: "MonthlyKpi",
      subjectId: kpi.id,
      beforeJson: { status: before.status, finalScore: before.finalScore?.toString() ?? null, rowVersion: before.rowVersion },
      afterJson: { status: "FINALIZED", finalScore: withoutScore ? null : kpi.finalScore?.toString() ?? null, withoutScore },
      reason: withoutScore ? input.noScoreReason!.trim() : null,
    },
  });
  await notifyUsers(tx, [kpi.employee.userId], {
    title: "KPI bulanan telah final",
    body: `Hasil ${kpi.period.name} sudah dapat Anda lihat.`,
    type: "monthly_finalized",
    actionUrl: `/app/kpi-saya/${kpi.id}`,
    dedupeKey: `monthly-finalized:${kpi.id}:${kpi.rowVersion + 1}`,
  });

  const remaining = await tx.monthlyKpi.count({ where: { periodId: kpi.periodId, status: { not: "FINALIZED" } } });
  if (remaining === 0) await tx.kpiPeriod.update({ where: { id: kpi.periodId }, data: { status: "COMPLETED", completedAt: now } });
  return { monthlyKpiId: kpi.id, finalScore: withoutScore ? null : kpi.finalScore?.toFixed(2) ?? null, withoutScore };
}

export async function reopenFinalizedKpi(
  tx: Prisma.TransactionClient,
  actor: AccessProfile,
  input: { monthlyKpiId: string; rowVersion: number; reason: string },
  now = new Date(),
) {
  const kpi = await tx.monthlyKpi.findUnique({
    where: { id: input.monthlyKpiId },
    include: { manager: { include: { user: true } } },
  });
  if (!kpi) throw new Error("KPI bulanan tidak ditemukan.");
  if (kpi.rowVersion !== input.rowVersion) throw new Error("Data telah berubah. Muat ulang sebelum membuka hasil.");
  const status = reopenMonthlyResult({ actorRole: actor.role, currentStatus: kpi.status, reason: input.reason });
  const updated = await tx.monthlyKpi.updateMany({
    where: { id: kpi.id, rowVersion: input.rowVersion },
    data: {
      status,
      finalScore: null,
      ratingCode: null,
      ratingLabel: null,
      noScoreReason: null,
      finalizedById: null,
      finalizedAt: null,
      reopenedById: actor.userId,
      reopenedAt: now,
      reopenReason: input.reason.trim(),
      revisionNumber: { increment: 1 },
      rowVersion: { increment: 1 },
    },
  });
  if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum membuka hasil.");
  await tx.kpiPeriod.update({ where: { id: kpi.periodId }, data: { status: "OPEN", completedAt: null } });
  await tx.auditEvent.create({
    data: {
      actorId: actor.userId,
      action: "reopen_monthly_kpi",
      subjectType: "MonthlyKpi",
      subjectId: kpi.id,
      beforeJson: { status: kpi.status, finalScore: kpi.finalScore?.toString() ?? null, rowVersion: kpi.rowVersion },
      afterJson: { status, revisionNumber: kpi.revisionNumber + 1 },
      reason: input.reason.trim(),
    },
  });
  await notifyUsers(tx, [kpi.manager.userId], {
    title: "KPI bulanan dibuka kembali",
    body: `${kpi.employeeNameSnapshot} perlu diperiksa dan difinalkan ulang.`,
    type: "monthly_reopened",
    actionUrl: `/app/rekap/${kpi.id}`,
    dedupeKey: `monthly-reopened:${kpi.id}:${kpi.revisionNumber + 1}`,
  });
  return { monthlyKpiId: kpi.id, status };
}
