import { Prisma, type WorkStatus } from "@/generated/prisma/client";
import { todayInMakassar, isoDate } from "@/lib/date";
import type { AccessProfile } from "@/modules/access/policy";
import { canEnterDailySheet, canReviewDailySheet } from "@/modules/access/policy";
import { notifyUsers } from "@/modules/notifications";
import { kpiSubjectFromSnapshot, lockMonthlyKpi, recalculateMonthlyKpi } from "@/modules/kpi/monthly-operations";
import { validateDailyIndicatorValue } from "@/modules/kpi/value-validation";
import { reviewDailySheet as decideDailyReview, submitDailySheet } from "@/modules/kpi/workflow";

export type DailyValueInput = { itemId: string; value: number };

function validatedValues(
  items: Array<{ id: string; nameSnapshot: string; kindSnapshot: "NUMERIC" | "RATING"; unitSnapshot: string; targetSnapshot: { toNumber(): number } }>,
  workStatus: WorkStatus,
  values: DailyValueInput[],
  note?: string,
) {
  if (workStatus !== "WORKED") {
    if (values.length) throw new Error("Hari nonkerja tidak boleh memiliki nilai indikator.");
    return new Map<string, number>();
  }
  const valueMap = new Map(values.map((entry) => [entry.itemId, entry.value]));
  if (valueMap.size !== values.length || valueMap.size !== items.length || items.some((item) => !valueMap.has(item.id))) {
    throw new Error("Seluruh indikator wajib diisi tepat satu kali.");
  }
  for (const item of items) {
    const value = valueMap.get(item.id)!;
    validateDailyIndicatorValue({ name: item.nameSnapshot, kind: item.kindSnapshot, unit: item.unitSnapshot }, value);
    if (item.kindSnapshot === "RATING" && value < item.targetSnapshot.toNumber() && !note?.trim()) throw new Error("Catatan wajib diisi jika rating berada di bawah target.");
  }
  return valueMap;
}

export async function saveDailySheet(
  tx: Prisma.TransactionClient,
  actor: AccessProfile,
  input: {
    sheetId: string;
    rowVersion: number;
    workStatus: WorkStatus;
    note?: string;
    values: DailyValueInput[];
    submit: boolean;
    correctionReason?: string;
  },
  now = new Date(),
) {
  const sheet = await tx.dailySheet.findUnique({
    where: { id: input.sheetId },
    include: {
      values: true,
      monthlyKpi: {
        include: {
          period: true,
          items: { orderBy: { sortOrderSnapshot: "asc" } },
          manager: { include: { user: true } },
        },
      },
    },
  });
  if (!sheet) throw new Error("Lembar harian tidak ditemukan.");
  await lockMonthlyKpi(tx, sheet.monthlyKpiId);
  const currentMonthly = await tx.monthlyKpi.findUniqueOrThrow({ where: { id: sheet.monthlyKpiId }, include: { period: true } });
  if (sheet.rowVersion !== input.rowVersion) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan.");
  const subject = kpiSubjectFromSnapshot(sheet.monthlyKpi);
  if (!canEnterDailySheet(actor, subject)) throw new Error("Anda tidak berwenang mengisi lembar ini.");
  if (currentMonthly.status === "FINALIZED") throw new Error("KPI bulanan sudah final dan tidak dapat diubah.");
  if (currentMonthly.period.status !== "OPEN") throw new Error("Periode tidak sedang terbuka.");
  if (isoDate(sheet.entryDate) > todayInMakassar(now)) throw new Error("Penilaian untuk tanggal mendatang tidak diperbolehkan.");

  const correctingApproved = sheet.status === "APPROVED" && actor.role === "MANAGER" && subject.role === "SUPERVISOR";
  if (correctingApproved && (!input.submit || !input.correctionReason?.trim())) throw new Error("Alasan koreksi Manager wajib diisi.");
  if (!correctingApproved && !["PENDING", "DRAFT", "REVISION_REQUIRED"].includes(sheet.status)) throw new Error("Lembar harian tidak dapat diubah pada status saat ini.");

  const valueMap = validatedValues(sheet.monthlyKpi.items, input.workStatus, input.values, input.note);
  const priorValues = new Map(sheet.values.map((value) => [value.monthlyKpiItemId, value.enteredValue?.toNumber() ?? null]));
  const changed = sheet.workStatus !== input.workStatus || sheet.monthlyKpi.items.some((item) => priorValues.get(item.id) !== (valueMap.get(item.id) ?? null));
  if (correctingApproved && !changed) throw new Error("Tidak ada perubahan yang perlu disimpan.");

  const nextStatus = correctingApproved
    ? "APPROVED"
    : input.submit
      ? submitDailySheet({ actorRole: actor.role, subjectRole: subject.role, currentStatus: sheet.status, workStatus: input.workStatus, valuesComplete: input.workStatus !== "WORKED" || valueMap.size === sheet.monthlyKpi.items.length })
      : "DRAFT";
  const approved = nextStatus === "APPROVED";
  const timestamp = now;
  const updated = await tx.dailySheet.updateMany({
    where: { id: sheet.id, rowVersion: sheet.rowVersion },
    data: {
      workStatus: input.workStatus,
      managerWorkStatus: null,
      effectiveWorkStatus: approved ? input.workStatus : null,
      status: nextStatus,
      note: input.note?.trim() || null,
      enteredById: actor.userId,
      submittedAt: input.submit ? timestamp : null,
      managerReviewedById: approved ? actor.userId : null,
      managerReviewedAt: approved ? timestamp : null,
      managerReason: correctingApproved ? input.correctionReason!.trim() : sheet.status === "REVISION_REQUIRED" ? sheet.managerReason : null,
      rowVersion: { increment: 1 },
    },
  });
  if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan.");
  await tx.dailyValue.deleteMany({ where: { dailySheetId: sheet.id } });
  if (input.workStatus === "WORKED") {
    await tx.dailyValue.createMany({
      data: sheet.monthlyKpi.items.map((item) => ({
        dailySheetId: sheet.id,
        monthlyKpiItemId: item.id,
        enteredValue: valueMap.get(item.id)!,
        effectiveValue: approved ? valueMap.get(item.id)! : null,
      })),
    });
  }
  await tx.auditEvent.create({
    data: {
      actorId: actor.userId,
      action: correctingApproved ? "correct_supervisor_daily_sheet" : input.submit ? "submit_daily_sheet" : "save_daily_sheet",
      subjectType: "DailySheet",
      subjectId: sheet.id,
      beforeJson: { status: sheet.status, workStatus: sheet.workStatus, rowVersion: sheet.rowVersion },
      afterJson: { status: nextStatus, workStatus: input.workStatus, rowVersion: sheet.rowVersion + 1 },
      reason: correctingApproved ? input.correctionReason!.trim() : input.note?.trim() || null,
    },
  });

  if (nextStatus === "SUBMITTED") {
    await notifyUsers(tx, [sheet.monthlyKpi.manager.userId], {
      title: "Penilaian harian menunggu review",
      body: `${sheet.monthlyKpi.employeeNameSnapshot} untuk ${isoDate(sheet.entryDate)} telah dikirim Supervisor.`,
      type: "daily_submitted",
      actionUrl: `/app/review?date=${isoDate(sheet.entryDate)}`,
      dedupeKey: `daily-submitted:${sheet.id}:${sheet.rowVersion + 1}`,
    });
  }
  if (approved) await recalculateMonthlyKpi(tx, sheet.monthlyKpiId, now);
  return { sheetId: sheet.id, status: nextStatus, rowVersion: sheet.rowVersion + 1 };
}

export async function reviewDailySheet(
  tx: Prisma.TransactionClient,
  actor: AccessProfile,
  input: {
    sheetId: string;
    rowVersion: number;
    decision: "APPROVE" | "CORRECT" | "RETURN";
    workStatus?: WorkStatus;
    values?: DailyValueInput[];
    reason?: string;
  },
  now = new Date(),
) {
  const sheet = await tx.dailySheet.findUnique({
    where: { id: input.sheetId },
    include: {
      values: true,
      monthlyKpi: {
        include: {
          items: { orderBy: { sortOrderSnapshot: "asc" } },
          employee: { include: { supervisor: { include: { user: true } } } },
        },
      },
    },
  });
  if (!sheet) throw new Error("Lembar harian tidak ditemukan.");
  await lockMonthlyKpi(tx, sheet.monthlyKpiId);
  const currentMonthly = await tx.monthlyKpi.findUniqueOrThrow({ where: { id: sheet.monthlyKpiId }, select: { status: true } });
  if (sheet.rowVersion !== input.rowVersion) throw new Error("Data telah berubah. Muat ulang sebelum mereview.");
  const subject = kpiSubjectFromSnapshot(sheet.monthlyKpi);
  if (!canReviewDailySheet(actor, subject)) throw new Error("Anda tidak berwenang mereview lembar ini.");
  if (currentMonthly.status === "FINALIZED") throw new Error("KPI bulanan sudah final dan tidak dapat diubah.");

  const allowApproved = sheet.status === "APPROVED";
  const effectiveWorkStatus = input.decision === "CORRECT" ? input.workStatus : sheet.workStatus;
  if (!effectiveWorkStatus) throw new Error("Status kerja harian belum tersedia.");
  const currentValues = new Map(sheet.values.map((value) => [value.monthlyKpiItemId, value.enteredValue?.toNumber() ?? null]));
  const reviewedValues = input.decision === "CORRECT"
    ? validatedValues(sheet.monthlyKpi.items, effectiveWorkStatus, input.values ?? [], input.reason)
    : new Map(sheet.monthlyKpi.items.flatMap((item) => {
        const value = currentValues.get(item.id);
        return value === null || value === undefined ? [] : [[item.id, value] as const];
      }));
  const changed = input.decision === "CORRECT" && (
    effectiveWorkStatus !== sheet.workStatus ||
    sheet.monthlyKpi.items.some((item) => currentValues.get(item.id) !== (reviewedValues.get(item.id) ?? null))
  );
  const nextStatus = decideDailyReview({ currentStatus: sheet.status, decision: input.decision, changed, reason: input.reason, allowApproved });
  const approved = nextStatus === "APPROVED";
  const timestamp = now;
  const updated = await tx.dailySheet.updateMany({
    where: { id: sheet.id, rowVersion: sheet.rowVersion },
    data: {
      status: nextStatus,
      managerWorkStatus: input.decision === "CORRECT" && effectiveWorkStatus !== sheet.workStatus ? effectiveWorkStatus : null,
      effectiveWorkStatus: approved ? effectiveWorkStatus : null,
      managerReviewedById: actor.userId,
      managerReviewedAt: timestamp,
      managerReason: input.reason?.trim() || null,
      rowVersion: { increment: 1 },
    },
  });
  if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum mereview.");

  if (approved && effectiveWorkStatus === "WORKED") {
    for (const item of sheet.monthlyKpi.items) {
      const enteredValue = currentValues.get(item.id) ?? null;
      const effectiveValue = reviewedValues.get(item.id)!;
      await tx.dailyValue.upsert({
        where: { dailySheetId_monthlyKpiItemId: { dailySheetId: sheet.id, monthlyKpiItemId: item.id } },
        update: { managerValue: input.decision === "CORRECT" && enteredValue !== effectiveValue ? effectiveValue : null, effectiveValue },
        create: { dailySheetId: sheet.id, monthlyKpiItemId: item.id, enteredValue, managerValue: effectiveValue, effectiveValue },
      });
    }
  } else {
    await tx.dailyValue.updateMany({ where: { dailySheetId: sheet.id }, data: { managerValue: null, effectiveValue: null } });
  }
  await tx.auditEvent.create({
    data: {
      actorId: actor.userId,
      action: input.decision === "RETURN" ? "return_daily_sheet" : input.decision === "CORRECT" ? "correct_daily_sheet" : "approve_daily_sheet",
      subjectType: "DailySheet",
      subjectId: sheet.id,
      beforeJson: { status: sheet.status, workStatus: sheet.effectiveWorkStatus ?? sheet.workStatus, rowVersion: sheet.rowVersion },
      afterJson: { status: nextStatus, workStatus: approved ? effectiveWorkStatus : null, changed, rowVersion: sheet.rowVersion + 1 },
      reason: input.reason?.trim() || null,
    },
  });
  await notifyUsers(tx, [sheet.monthlyKpi.employee.supervisor?.userId], {
    title: input.decision === "RETURN" ? "Penilaian harian dikembalikan" : "Penilaian harian selesai direview",
    body: `${sheet.monthlyKpi.employeeNameSnapshot} untuk ${isoDate(sheet.entryDate)} ${input.decision === "RETURN" ? "perlu diperbaiki" : "telah disetujui Manager"}.`,
    type: input.decision === "RETURN" ? "daily_returned" : "daily_approved",
    actionUrl: `/app/harian?date=${isoDate(sheet.entryDate)}`,
    dedupeKey: `daily-reviewed:${sheet.id}:${sheet.rowVersion + 1}`,
  });
  await recalculateMonthlyKpi(tx, sheet.monthlyKpiId, now);
  return { sheetId: sheet.id, status: nextStatus, rowVersion: sheet.rowVersion + 1 };
}
