import type { Prisma } from "@/generated/prisma/client";
import { prisma } from "@/lib/prisma";
import { processQueuedCashierImports } from "@/modules/imports/processing";
import { syncPeriodFactsThrough } from "@/modules/kpi/period-sync";
import { makassarDate, makassarDayBoundsUtc } from "@/modules/jobs/time";

export async function sendDeadlineReminders(tx: Prisma.TransactionClient, now: Date) {
  const notifications: Prisma.SystemNotificationCreateManyInput[] = [];
  for (const days of [3, 1]) {
    const { start, end } = makassarDayBoundsUtc(now, days);
    const suffix = `h${days}`;
    const [submissions, inputPeriods, reviewPeriods] = await Promise.all([
      tx.reportSubmission.findMany({ where: { submittedAt: null, deadlineAt: { gte: start, lt: end } }, include: { employee: { select: { userId: true } } } }),
      tx.kpiPeriod.findMany({ where: { status: "OPEN", submissionDeadline: { gte: start, lt: end } }, include: { employeeKpis: { where: { status: { in: ["DRAFT", "REVISION_REQUIRED"] } }, include: { employee: { select: { userId: true } } } } } }),
      tx.kpiPeriod.findMany({ where: { status: { in: ["SUBMISSION_CLOSED", "IN_REVIEW"] }, reviewDeadline: { gte: start, lt: end } }, include: { employeeKpis: { where: { status: { in: ["SUBMITTED", "UNDER_REVIEW", "REVISION_REQUIRED", "VERIFIED"] } }, include: { supervisor: { select: { userId: true } } } } } }),
    ]);
    for (const submission of submissions) if (submission.employee.userId) notifications.push({ userId: submission.employee.userId, title: "Deadline laporan mendekat", body: `Laporan harus selesai dalam ${days} hari. Buka data terbaru untuk melanjutkan.`, type: `report_due_${suffix}`, entityType: "ReportSubmission", entityId: submission.id, actionUrl: "/app/operasional", dedupeKey: `report-due:${suffix}:${submission.id}` });
    for (const period of inputPeriods) for (const kpi of period.employeeKpis) if (kpi.employee.userId) notifications.push({ userId: kpi.employee.userId, title: `Deadline KPI ${period.name} mendekat`, body: `Pekerjaan KPI yang belum lengkap harus diselesaikan dalam ${days} hari.`, type: `submission_due_${suffix}`, entityType: "EmployeeKpi", entityId: kpi.id, actionUrl: `/app/kpi-saya/${kpi.id}`, dedupeKey: `submission-due:${suffix}:${kpi.id}` });
    for (const period of reviewPeriods) for (const kpi of period.employeeKpis) if (kpi.supervisor?.userId) notifications.push({ userId: kpi.supervisor.userId, title: `Deadline review ${period.name} mendekat`, body: `Review ${kpi.employeeNameSnapshot} harus diselesaikan dalam ${days} hari.`, type: `review_due_${suffix}`, entityType: "EmployeeKpi", entityId: kpi.id, actionUrl: `/app/tim/${kpi.id}`, dedupeKey: `review-due:${suffix}:${kpi.id}` });
  }
  return notifications.length ? (await tx.systemNotification.createMany({ data: notifications, skipDuplicates: true })).count : 0;
}

export async function runScheduledMaintenance(now = new Date()) {
  const processedImports = await processQueuedCashierImports();
  // ponytail: one transaction is adequate for the current store-scale dataset; split jobs per period if the 5-minute ceiling is reached.
  const result = await prisma.$transaction(async (tx) => {
    const [lock] = await tx.$queryRaw<Array<{ locked: boolean }>>`SELECT pg_try_advisory_xact_lock(24090901) AS locked`;
    if (!lock?.locked) return { skipped: true, syncedPeriods: 0, notifications: 0 };
    const today = makassarDate(now);
    const periods = await tx.kpiPeriod.findMany({ where: { status: { in: ["OPEN", "SUBMISSION_CLOSED", "IN_REVIEW", "WAITING_APPROVAL"] }, startDate: { lte: today } }, select: { id: true, endDate: true } });
    for (const period of periods) await syncPeriodFactsThrough(tx, period.id, today > period.endDate ? period.endDate : today);
    const notifications = await sendDeadlineReminders(tx, now);
    await tx.auditEvent.create({ data: { actorType: "system", action: "scheduled_maintenance_completed", subjectType: "System", subjectId: "scheduled-maintenance", afterJson: { throughDate: today.toISOString().slice(0, 10), syncedPeriods: periods.length, notifications } } });
    return { skipped: false, syncedPeriods: periods.length, notifications };
  }, { maxWait: 10_000, timeout: 300_000 });
  return { ...result, processedImports };
}
