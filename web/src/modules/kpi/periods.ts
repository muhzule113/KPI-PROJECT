import { Prisma, type PeriodStatus } from "@/generated/prisma/client";
import { datesForDailyItem } from "@/modules/kpi/daily-values";
import { validateTemplateConfiguration } from "@/modules/kpi/period-readiness";
import { assertPeriodTransition } from "@/modules/kpi/workflow";

async function periodContext(tx: Prisma.TransactionClient, periodId: string) {
  const period = await tx.kpiPeriod.findUnique({ where: { id: periodId }, include: { branches: true } });
  if (!period) throw new Error("Periode KPI tidak ditemukan.");
  const branchIds = period.branches.map((link) => link.branchId);
  const placements = branchIds.length ? await tx.employeePlacement.findMany({
    where: {
      branchId: { in: branchIds },
      effectiveFrom: { lte: period.endDate },
      OR: [{ effectiveUntil: null }, { effectiveUntil: { gte: period.startDate } }],
      employee: {
        status: "ACTIVE",
        joinedAt: { lte: period.endDate },
        OR: [{ endedAt: null }, { endedAt: { gte: period.startDate } }],
      },
    },
    orderBy: { effectiveFrom: "asc" },
    include: { employee: { include: { user: true } }, position: true, branch: true, supervisor: { include: { user: true, position: true } } },
  }) : [];
  const grouped = new Map<string, typeof placements>();
  for (const placement of placements) grouped.set(placement.employeeId, [...(grouped.get(placement.employeeId) ?? []), placement]);
  const selected = [...grouped.values()].map((rows) => rows.find((row) => row.effectiveFrom <= period.startDate && (!row.effectiveUntil || row.effectiveUntil >= period.startDate)) ?? rows[0]);
  return { period, branchIds, placements: selected };
}

async function placementOn(tx: Prisma.TransactionClient, employeeId: string, date: Date) {
  return tx.employeePlacement.findFirst({
    where: { employeeId, effectiveFrom: { lte: date }, OR: [{ effectiveUntil: null }, { effectiveUntil: { gte: date } }] },
    orderBy: { effectiveFrom: "desc" },
    include: { employee: { include: { user: true } }, position: true, supervisor: { include: { user: true, position: true } } },
  });
}

async function managerFor(tx: Prisma.TransactionClient, supervisorId: string | null, branchId: string, date: Date) {
  let candidateId = supervisorId;
  const visited = new Set<string>();
  while (candidateId && !visited.has(candidateId)) {
    visited.add(candidateId);
    const placement = await placementOn(tx, candidateId, date);
    if (!placement) return null;
    if (placement.branchId === branchId && placement.employee.status === "ACTIVE" && placement.employee.user?.isActive && placement.employee.user.role === "owner_manager" && ["POS-OWN", "POS-EXEC"].includes(placement.position.code)) return placement.employee;
    candidateId = placement.supervisorId;
  }
  return null;
}

async function activeTemplate(tx: Prisma.TransactionClient, positionId: string, date: Date) {
  return tx.kpiTemplate.findFirst({
    where: { positionId, isActive: true },
    include: {
      versions: {
        where: { status: "ACTIVE", OR: [{ effectiveFrom: null }, { effectiveFrom: { lte: date } }], AND: [{ OR: [{ effectiveUntil: null }, { effectiveUntil: { gte: date } }] }] },
        orderBy: { versionNumber: "desc" },
        take: 1,
        include: {
          ratingScheme: { include: { bands: { orderBy: { sortOrder: "asc" } } } },
          items: { orderBy: { sortOrder: "asc" }, include: { definition: true, rubric: { include: { criteria: { orderBy: { sortOrder: "asc" } } } } } },
        },
      },
    },
  });
}

export async function validatePeriodReadiness(tx: Prisma.TransactionClient, periodId: string) {
  const { period, branchIds, placements } = await periodContext(tx, periodId);
  const issues: string[] = [];
  if (!branchIds.length) issues.push("Pilih minimal satu cabang peserta periode KPI.");
  if (period.submissionDeadline >= period.reviewDeadline) issues.push("Deadline input harus lebih awal dari deadline review.");
  if (period.reviewDeadline >= period.approvalDeadline) issues.push("Deadline review harus lebih awal dari deadline approval.");
  const subjects = placements.filter((placement) => !["POS-OWN", "POS-EXEC"].includes(placement.position.code));
  if (!subjects.length) issues.push("Tidak ada karyawan aktif pada cabang peserta periode.");

  const checkedPositions = new Set<string>();
  for (const placement of subjects) {
    const expectedRole = placement.position.code === "POS-SPV" ? "owner_manager" : "supervisor";
    const reviewerPlacement = placement.supervisorId ? await placementOn(tx, placement.supervisorId, period.startDate) : null;
    if (!reviewerPlacement || reviewerPlacement.branchId !== placement.branchId || reviewerPlacement.employee.id === placement.employeeId || reviewerPlacement.employee.user?.role !== expectedRole || !reviewerPlacement.employee.user.isActive) issues.push(`Karyawan '${placement.employee.name}' belum memiliki penilai aktif yang sesuai pada cabang yang sama.`);
    if (!await managerFor(tx, placement.supervisorId, placement.branchId, period.startDate)) issues.push(`Karyawan '${placement.employee.name}' belum memiliki Manager aktif untuk snapshot.`);

    if (checkedPositions.has(placement.positionId)) continue;
    checkedPositions.add(placement.positionId);
    const template = await activeTemplate(tx, placement.positionId, period.startDate);
    const version = template?.versions[0];
    if (!template || !version) {
      issues.push(`Jabatan '${placement.position.name}' belum memiliki versi Template KPI aktif yang berlaku.`);
      continue;
    }
    issues.push(...validateTemplateConfiguration(template.name, version.items.map((item) => ({
      name: item.definition.name,
      weight: Number(item.weight),
      formula: item.formulaKey,
      target: item.targetValue == null ? null : Number(item.targetValue),
      targetJson: item.targetJson,
      formulaParams: item.formulaParams,
      definitionActive: item.definition.isActive,
      rubricCriteria: item.rubric?.criteria.length ?? 0,
    }))));
  }
  return { isReady: issues.length === 0, issues: [...new Set(issues)], eligibleCount: subjects.length };
}

async function generateSnapshots(tx: Prisma.TransactionClient, periodId: string) {
  const { period, placements } = await periodContext(tx, periodId);
  let generated = 0;
  for (const placement of placements.filter((row) => !["POS-OWN", "POS-EXEC"].includes(row.position.code))) {
    const template = await activeTemplate(tx, placement.positionId, period.startDate);
    const version = template?.versions[0];
    if (!version) continue;
    const manager = await managerFor(tx, placement.supervisorId, placement.branchId, period.startDate);
    const existing = await tx.employeeKpi.findUnique({ where: { periodId_employeeId: { periodId, employeeId: placement.employeeId } }, include: { items: true } });
    const kpi = existing ?? await tx.employeeKpi.create({
      data: {
        periodId,
        employeeId: placement.employeeId,
        employeeNumberSnapshot: placement.employee.employeeNumber,
        employeeNameSnapshot: placement.employee.name,
        templateVersionId: version.id,
        supervisorIdSnapshot: placement.supervisorId,
        managerIdSnapshot: manager?.id,
        branchIdSnapshot: placement.branchId,
        positionIdSnapshot: placement.positionId,
        placementIdSnapshot: placement.id,
        positionCodeSnapshot: placement.position.code,
        eligibility: placement.employee.joinedAt > period.startDate || placement.effectiveFrom > period.startDate ? "partial" : "full",
        scoreCapSnapshot: version.ratingScheme?.scoreCap ?? 100,
        ratingBandsSnapshot: version.ratingScheme?.bands.map((band) => ({ code: band.code, label: band.label, min_score: band.minScore.toString(), max_score: band.maxScore.toString(), sort_order: band.sortOrder })) ?? [],
      },
      include: { items: true },
    });
    if (!existing) generated++;
    for (const item of version.items) {
      const supervisorRated = item.sourceType.toLowerCase() === "supervisor";
      const rubricSnapshot = item.rubric || supervisorRated ? {
        ...(item.rubric ? {
          rubric_name: item.rubric.name,
          criteria: item.rubric.criteria.map((criterion) => ({ id: criterion.id, criterion_text: criterion.criterionText, points: Number(criterion.points), is_mandatory: criterion.isMandatory })),
        } : {}),
        ...(supervisorRated ? {
          manual_rating_options: version.ratingScheme?.bands.filter((band) => band.manualScore != null).map((band) => ({ code: band.code, label: band.label, score: Number(band.manualScore) })) ?? [],
        } : {}),
      } : null;
      const employeeItem = kpi.items.find((existingItem) => existingItem.kpiDefinitionId === item.kpiDefinitionId) ?? await tx.employeeKpiItem.create({ data: {
          employeeKpiId: kpi.id,
          kpiDefinitionId: item.kpiDefinitionId,
          definitionCodeSnapshot: item.definition.code,
          nameSnapshot: item.definition.name,
          weightSnapshot: item.weight,
          targetValueSnapshot: item.targetValue,
          targetUnitSnapshot: item.targetUnit,
          targetJsonSnapshot: item.targetJson ?? Prisma.JsonNull,
          formulaKeySnapshot: item.formulaKey,
          formulaParamsSnapshot: item.formulaParams ?? Prisma.JsonNull,
          sourceTypeSnapshot: item.sourceType,
          evidenceRequiredSnapshot: item.evidenceRequired,
          rubricSnapshot: rubricSnapshot ?? Prisma.JsonNull,
        } });
      const entryDates = datesForDailyItem({
        startDate: period.startDate,
        endDate: period.endDate,
        code: item.definition.code,
        sourceType: item.sourceType,
        targetJson: item.targetJson,
        formulaParams: item.formulaParams,
      });
      if (entryDates.length) await tx.kpiDailyEntry.createMany({
        data: entryDates.map((entryDate) => ({ employeeKpiItemId: employeeItem.id, entryDate, entryStatus: "submitted" })),
        skipDuplicates: true,
      });
    }
    const reportTypes = placement.position.code === "POS-ADM"
      ? ["admin_daily", "admin_monthly"]
      : placement.position.code === "POS-SPV" ? ["supervisor_monthly"] : [];
    for (const reportType of reportTypes) {
      const reportDates: Date[] = [];
      if (reportType === "admin_daily") {
        for (let date = new Date(period.startDate); date <= period.endDate; date = new Date(date.valueOf() + 86_400_000)) {
          if (![0, 6].includes(date.getUTCDay())) reportDates.push(date);
        }
      } else reportDates.push(period.endDate);
      await tx.reportSubmission.createMany({
        data: reportDates.map((reportDate) => ({
          periodId,
          employeeId: placement.employeeId,
          reportType,
          reportDate,
          deadlineAt: reportType === "admin_daily" ? new Date(`${reportDate.toISOString().slice(0, 10)}T10:00:00.000Z`) : period.reviewDeadline,
        })),
        skipDuplicates: true,
      });
    }
  }
  const total = await tx.employeeKpi.count({ where: { periodId } });
  await tx.kpiPeriod.update({ where: { id: periodId }, data: { totalEligibleEmployees: total } });
  return generated;
}

export async function transitionPeriod(tx: Prisma.TransactionClient, periodId: string, next: PeriodStatus, actorId: string, reason?: string) {
  await tx.$queryRaw`SELECT id FROM kpi_periods WHERE id = ${periodId} FOR UPDATE`;
  const period = await tx.kpiPeriod.findUnique({ where: { id: periodId }, include: { branches: true } });
  if (!period) throw new Error("Periode KPI tidak ditemukan.");
  assertPeriodTransition(period.status, next);
  if (next === "CANCELLED" && !reason?.trim()) throw new Error("Alasan pembatalan wajib diisi.");

  if (next === "READY") {
    const readiness = await validatePeriodReadiness(tx, periodId);
    if (!readiness.isReady) throw new Error(`Periode belum siap: ${readiness.issues.join(" ")}`);
    await generateSnapshots(tx, periodId);
  }
  if (next === "OPEN") {
    if (!period.totalEligibleEmployees || !await tx.employeeKpi.count({ where: { periodId } })) throw new Error("Periode belum memiliki snapshot KPI. Jalankan READY terlebih dahulu.");
    if ([period.submissionDeadline, period.reviewDeadline, period.approvalDeadline].some((deadline) => deadline <= new Date())) throw new Error("Periode dengan deadline lampau tidak dapat dibuka.");
    const overlap = await tx.kpiPeriod.findFirst({ where: { id: { not: periodId }, status: "OPEN", startDate: { lte: period.endDate }, endDate: { gte: period.startDate }, branches: { some: { branchId: { in: period.branches.map((row) => row.branchId) } } } } });
    if (overlap) throw new Error("Rentang periode bertumpang tindih dengan periode OPEN pada cabang yang sama.");
    await tx.employeeKpi.updateMany({ where: { periodId, status: "DRAFT" }, data: { status: "SUBMITTED", submittedAt: new Date(), rowVersion: { increment: 1 } } });
  }
  if (next === "IN_REVIEW") {
    const blocked = await tx.employeeKpi.count({ where: { periodId, status: { in: ["DRAFT", "REVISION_REQUIRED"] } } });
    if (blocked) throw new Error("Masih ada KPI yang belum menyelesaikan penilaian harian.");
    await tx.employeeKpi.updateMany({ where: { periodId, status: "SUBMITTED" }, data: { status: "UNDER_REVIEW", rowVersion: { increment: 1 } } });
  }
  if (next === "WAITING_APPROVAL") {
    const blocked = await tx.employeeKpi.count({ where: { periodId, positionCodeSnapshot: { not: "POS-SPV" }, status: { notIn: ["VERIFIED", "PENDING_APPROVAL", "APPROVED", "LOCKED"] } } });
    if (blocked) throw new Error("Masih ada KPI staf yang belum diverifikasi Supervisor.");
    await tx.employeeKpi.updateMany({ where: { periodId, status: "VERIFIED" }, data: { status: "PENDING_APPROVAL", rowVersion: { increment: 1 } } });
  }
  if (next === "PUBLISHED") {
    const total = await tx.employeeKpi.count({ where: { periodId } });
    const blocked = await tx.employeeKpi.count({ where: { periodId, status: { notIn: ["APPROVED", "LOCKED"] } } });
    if (!total || blocked) throw new Error("Periode belum dapat dipublikasikan karena masih ada KPI yang belum disetujui.");
  }
  if (next === "LOCKED") {
    const blocked = await tx.employeeKpi.count({ where: { periodId, status: { notIn: ["APPROVED", "LOCKED"] } } });
    if (blocked) throw new Error("Periode belum dapat dikunci karena masih ada KPI yang belum final.");
    await tx.employeeKpi.updateMany({ where: { periodId, status: "APPROVED" }, data: { status: "LOCKED", lockedAt: new Date(), rowVersion: { increment: 1 } } });
    await tx.employeeKpiItem.updateMany({ where: { employeeKpi: { periodId }, status: { not: "LOCKED" } }, data: { status: "LOCKED", rowVersion: { increment: 1 } } });
  }

  const now = new Date();
  const updated = await tx.kpiPeriod.update({ where: { id: periodId }, data: {
    status: next,
    openedAt: next === "OPEN" ? now : undefined,
    closedAt: next === "SUBMISSION_CLOSED" ? now : undefined,
    publishedAt: next === "PUBLISHED" ? now : undefined,
    lockedAt: next === "LOCKED" ? now : undefined,
  } });
  await tx.auditEvent.create({ data: { actorId, action: `period_${next.toLowerCase()}`, subjectType: "KpiPeriod", subjectId: periodId, beforeJson: { status: period.status }, afterJson: { status: next }, reason } });
  if (["OPEN", "PUBLISHED"].includes(next)) {
    const recipients = await tx.employeeKpi.findMany({ where: { periodId }, select: { id: true, employee: { select: { userId: true } } } });
    await tx.systemNotification.createMany({ data: recipients.flatMap((row) => row.employee.userId ? [{ userId: row.employee.userId, title: next === "OPEN" ? `Periode KPI dibuka: ${period.name}` : `Hasil KPI dipublikasikan: ${period.name}`, body: next === "OPEN" ? "Catat pekerjaan melalui menu operasional. Penilaian dilakukan oleh penilai yang ditugaskan." : "Hasil akhir KPI Anda sudah dapat dilihat.", type: next === "OPEN" ? "period_opened" : "period_published", entityType: "EmployeeKpi", entityId: row.id, actionUrl: `/app/kpi-saya/${row.id}`, dedupeKey: `${next.toLowerCase()}:${periodId}:${row.id}` }] : []), skipDuplicates: true });
  }
  return updated;
}
