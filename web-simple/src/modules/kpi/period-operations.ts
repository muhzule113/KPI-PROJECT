import { Prisma, type UserRole } from "@/generated/prisma/client";
import type { AccessProfile } from "@/modules/access/policy";
import { notifyUsers } from "@/modules/notifications";
import { assessmentDates, validateRatingBands, validateTemplate } from "@/modules/kpi/period";

const periodName = (year: number, month: number) => new Intl.DateTimeFormat("id-ID", {
  month: "long",
  year: "numeric",
  timeZone: "UTC",
}).format(new Date(Date.UTC(year, month - 1, 1)));

export async function createPeriod(
  tx: Prisma.TransactionClient,
  actor: AccessProfile,
  input: { year: number; month: number },
) {
  if (!actor.active || actor.role !== "ADMIN") throw new Error("Hanya Super Admin yang dapat membuat periode.");
  if (!Number.isInteger(input.year) || input.year < 2020 || input.year > 2100 || !Number.isInteger(input.month) || input.month < 1 || input.month > 12) {
    throw new Error("Bulan dan tahun periode tidak valid.");
  }
  const startDate = new Date(Date.UTC(input.year, input.month - 1, 1));
  const endDate = new Date(Date.UTC(input.year, input.month, 0));
  const period = await tx.kpiPeriod.create({
    data: { name: periodName(input.year, input.month), year: input.year, month: input.month, startDate, endDate, createdById: actor.userId },
  });
  await tx.auditEvent.create({
    data: { actorId: actor.userId, action: "create_period", subjectType: "KpiPeriod", subjectId: period.id, afterJson: { year: input.year, month: input.month } },
  });
  return period;
}

export async function openPeriod(tx: Prisma.TransactionClient, actor: AccessProfile, periodId: string, now = new Date()) {
  if (!actor.active || actor.role !== "ADMIN") throw new Error("Hanya Super Admin yang dapat membuka periode.");
  const period = await tx.kpiPeriod.findUnique({ where: { id: periodId } });
  if (!period) throw new Error("Periode tidak ditemukan.");
  if (period.status !== "DRAFT") throw new Error("Hanya periode DRAFT yang dapat dibuka.");
  if (await tx.monthlyKpi.count({ where: { periodId } })) throw new Error("Snapshot periode sudah pernah dibuat.");
  const claimed = await tx.kpiPeriod.updateMany({ where: { id: period.id, status: "DRAFT" }, data: { status: "OPEN", openedAt: now } });
  if (claimed.count !== 1) throw new Error("Periode telah berubah. Muat ulang sebelum membukanya.");

  const ratingScheme = await tx.kpiRatingScheme.findFirst({
    where: { status: "ACTIVE" },
    include: { bands: { orderBy: { sortOrder: "asc" } } },
  });
  if (!ratingScheme) throw new Error("Skala predikat aktif belum tersedia.");
  const ratingValidation = validateRatingBands(ratingScheme.bands.map((band) => ({
    code: band.code,
    label: band.label,
    minScore: band.minScore.toString(),
    sortOrder: band.sortOrder,
  })));
  if (!ratingValidation.ok) throw new Error(ratingValidation.reason);

  const employees = await tx.employee.findMany({
    where: {
      status: { in: ["ACTIVE", "RESIGNED"] },
      joinedAt: { lte: period.endDate },
      OR: [{ endedAt: null }, { endedAt: { gte: period.startDate } }],
      position: { isActive: true, isKpiSubject: true },
      user: { role: { in: ["EMPLOYEE", "SUPERVISOR"] } },
    },
    orderBy: [{ branch: { name: "asc" } }, { name: "asc" }],
    include: {
      user: true,
      branch: true,
      position: { include: { template: { include: { versions: { where: { status: "ACTIVE" }, orderBy: { versionNumber: "desc" }, take: 1, include: { indicators: { orderBy: { sortOrder: "asc" } } } } } } } },
      supervisor: { include: { user: true, branch: true } },
      manager: { include: { user: true, branch: true } },
    },
  });
  if (!employees.length) throw new Error("Tidak ada pegawai yang memenuhi syarat pada periode ini.");

  const problems: string[] = [];
  for (const employee of employees) {
    const subjectRole = employee.user.role;
    const templateVersion = employee.position.template?.versions[0];
    if (!employee.manager || employee.manager.status !== "ACTIVE" || employee.manager.branchId !== employee.branchId || employee.manager.user.role !== "MANAGER" || !employee.manager.user.isActive) {
      problems.push(`${employee.name}: Manager aktif dalam cabang yang sama belum ditetapkan.`);
    }
    if (subjectRole === "EMPLOYEE" && (!employee.supervisor || employee.supervisor.status !== "ACTIVE" || employee.supervisor.branchId !== employee.branchId || employee.supervisor.user.role !== "SUPERVISOR" || !employee.supervisor.user.isActive)) {
      problems.push(`${employee.name}: Supervisor aktif dalam cabang yang sama belum ditetapkan.`);
    }
    if (!templateVersion) {
      problems.push(`${employee.name}: template aktif untuk jabatan ${employee.position.name} belum tersedia.`);
    } else {
      const validation = validateTemplate(templateVersion.indicators.map((indicator) => ({
        code: indicator.code,
        kind: indicator.kind,
        aggregation: indicator.aggregation,
        direction: indicator.direction,
        target: indicator.target.toString(),
        failureLimit: indicator.failureLimit?.toString() ?? null,
        weight: indicator.weight.toString(),
      })));
      if (!validation.ok) problems.push(`${employee.name}: ${validation.reason}`);
    }
  }
  if (problems.length) throw new Error(`Periode belum siap. ${problems.join(" ")}`);

  let sheetCount = 0;
  for (const employee of employees) {
    const templateVersion = employee.position.template!.versions[0];
    const dates = assessmentDates({
      periodStart: period.startDate.toISOString().slice(0, 10),
      periodEnd: period.endDate.toISOString().slice(0, 10),
      joinedAt: employee.joinedAt.toISOString().slice(0, 10),
      endedAt: employee.endedAt?.toISOString().slice(0, 10) ?? null,
    });
    sheetCount += dates.length;
    const monthlyKpi = await tx.monthlyKpi.create({
      data: {
        periodId: period.id,
        employeeId: employee.id,
        employeeNumberSnapshot: employee.employeeNumber,
        employeeNameSnapshot: employee.name,
        subjectRoleSnapshot: employee.user.role as UserRole,
        branchIdSnapshot: employee.branchId,
        branchNameSnapshot: employee.branch.name,
        positionIdSnapshot: employee.positionId,
        positionCodeSnapshot: employee.position.code,
        positionNameSnapshot: employee.position.name,
        supervisorIdSnapshot: employee.supervisorId,
        managerIdSnapshot: employee.managerId!,
        ratingBandsSnapshot: ratingScheme.bands.map((band) => ({ code: band.code, label: band.label, minScore: band.minScore.toString(), sortOrder: band.sortOrder })),
        items: {
          create: templateVersion.indicators.map((indicator) => ({
            codeSnapshot: indicator.code,
            nameSnapshot: indicator.name,
            descriptionSnapshot: indicator.description,
            kindSnapshot: indicator.kind,
            unitSnapshot: indicator.unit,
            aggregationSnapshot: indicator.aggregation,
            directionSnapshot: indicator.direction,
            targetSnapshot: indicator.target,
            failureLimitSnapshot: indicator.failureLimit,
            weightSnapshot: indicator.weight,
            sortOrderSnapshot: indicator.sortOrder,
          })),
        },
        dailySheets: { create: dates.map((date) => ({ entryDate: new Date(`${date}T00:00:00.000Z`) })) },
      },
    });
    const recipientIds = employee.user.role === "SUPERVISOR"
      ? [employee.manager!.userId]
      : [employee.supervisor!.userId, employee.manager!.userId];
    await notifyUsers(tx, recipientIds, {
      title: "Periode KPI dibuka",
      body: `${period.name} untuk ${employee.name} siap dinilai setiap hari.`,
      type: "period_opened",
      actionUrl: "/app/harian",
      dedupeKey: `period-opened:${monthlyKpi.id}`,
    });
  }

  await tx.auditEvent.create({
    data: { actorId: actor.userId, action: "open_period", subjectType: "KpiPeriod", subjectId: period.id, beforeJson: { status: "DRAFT" }, afterJson: { status: "OPEN", employeeCount: employees.length, sheetCount } },
  });
  return { periodId: period.id, employeeCount: employees.length, sheetCount };
}
