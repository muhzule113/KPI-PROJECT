import type { Prisma } from "@/generated/prisma/client";
import { datesThrough } from "@/modules/kpi/operational-metrics";
import { syncEmployeeOperationalKpis } from "@/modules/kpi/operational-sync";

export async function syncPeriodFactsThrough(tx: Prisma.TransactionClient, periodId: string, throughDate: Date, actorId?: string) {
  await tx.$queryRaw`SELECT id FROM kpi_periods WHERE id = ${periodId} FOR UPDATE`;
  const period = await tx.kpiPeriod.findUnique({ where: { id: periodId } });
  if (!period || !["OPEN", "SUBMISSION_CLOSED", "IN_REVIEW", "WAITING_APPROVAL"].includes(period.status)) throw new Error("Sinkronisasi hanya tersedia untuk periode aktif sebelum publikasi.");
  if (throughDate < period.startDate || throughDate > period.endDate) throw new Error("Tanggal sinkronisasi harus berada dalam rentang periode.");
  const rows = await tx.employeeKpi.findMany({ where: { periodId: period.id, status: { notIn: ["APPROVED", "LOCKED"] } }, select: { employeeId: true }, distinct: ["employeeId"] });
  const dates = datesThrough(period.startDate, throughDate);
  for (const row of rows) await syncEmployeeOperationalKpis(tx, row.employeeId, throughDate, actorId, dates);
  await tx.auditEvent.create({ data: { actorType: actorId ? "user" : "system", actorId, action: "daily_kpi_sync_completed", subjectType: "KpiPeriod", subjectId: period.id, afterJson: { throughDate: throughDate.toISOString().slice(0, 10), preparedKpis: rows.length } } });
  return { name: period.name, count: rows.length, throughDate };
}
