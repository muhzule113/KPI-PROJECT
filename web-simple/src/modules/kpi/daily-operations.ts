import { Prisma, type ValueKind, type ValueStatus, type WorkStatus } from "@/generated/prisma/client";
import { todayInMakassar, isoDate } from "@/lib/date";
import type { AccessProfile } from "@/modules/access/policy";
import { canEnterDailySheet, canReviewDailySheet } from "@/modules/access/policy";
import { notifyUsers } from "@/modules/notifications";
import { categoryOptionsFromSnapshot, resolveCategoryOption } from "@/modules/kpi/category-options";
import { kpiSubjectFromSnapshot, lockMonthlyKpi, recalculateMonthlyKpi } from "@/modules/kpi/monthly-operations";
import { resolveSystemValue } from "@/modules/kpi/system-value";
import { validateDailyIndicatorValue } from "@/modules/kpi/value-validation";
import { reviewDailySheet as decideDailyReview, submitDailySheet } from "@/modules/kpi/workflow";

// `status` adalah keadaan entri (terisi / belum / tidak berlaku); `value` dan `categoryOptionId`
// hanya bermakna saat status AVAILABLE. Jenis SYSTEM/IMPORTED mengabaikan kiriman klien.
export type DailyValueInput = {
  itemId: string;
  status: "PENDING" | "AVAILABLE" | "NOT_APPLICABLE";
  value?: number | null;
  categoryOptionId?: string | null;
};

type ResolvedDailyValue = {
  status: ValueStatus;
  value: number | null;
  categoryOptionId: string | null;
};

type SheetItem = {
  id: string;
  codeSnapshot: string;
  nameSnapshot: string;
  kindSnapshot: ValueKind;
  unitSnapshot: string;
  targetSnapshot: { toNumber(): number };
  categoryOptionsSnapshot: unknown;
};

const EMPTY_VALUE: ResolvedDailyValue = { status: "PENDING", value: null, categoryOptionId: null };

function validatedValues(
  items: SheetItem[],
  workStatus: WorkStatus,
  entries: DailyValueInput[],
  note: string | undefined,
) {
  if (workStatus !== "WORKED") {
    if (entries.length) throw new Error("Hari nonkerja tidak boleh memiliki nilai indikator.");
    return new Map<string, ResolvedDailyValue>();
  }
  const entryMap = new Map(entries.map((entry) => [entry.itemId, entry]));
  if (entryMap.size !== entries.length || entryMap.size !== items.length || items.some((item) => !entryMap.has(item.id))) {
    throw new Error("Seluruh indikator wajib diisi tepat satu kali.");
  }

  const resolved = new Map<string, ResolvedDailyValue>();
  for (const item of items) {
    const entry = entryMap.get(item.id)!;

    if (item.kindSnapshot === "SYSTEM" || item.kindSnapshot === "IMPORTED") {
      const outcome = resolveSystemValue();
      resolved.set(item.id, outcome.status === "AVAILABLE"
        ? { status: "AVAILABLE", value: outcome.value, categoryOptionId: null }
        : { status: "MISSING", value: null, categoryOptionId: null });
      continue;
    }
    if (entry.status !== "AVAILABLE") {
      resolved.set(item.id, { ...EMPTY_VALUE, status: entry.status });
      continue;
    }

    const rule = { name: item.nameSnapshot, kind: item.kindSnapshot, unit: item.unitSnapshot };
    if (item.kindSnapshot === "CATEGORY") {
      // Periode baru menyimpan angka mentah; hanya snapshot legacy yang masih menerima option id.
      if (entry.value !== null && entry.value !== undefined) {
        validateDailyIndicatorValue(rule, entry.value);
        resolved.set(item.id, { status: "AVAILABLE", value: entry.value, categoryOptionId: null });
        continue;
      }
      if (!entry.categoryOptionId) throw new Error(`Nilai angka untuk ${item.nameSnapshot} wajib diisi.`);
      const option = resolveCategoryOption(categoryOptionsFromSnapshot(item.categoryOptionsSnapshot), entry.categoryOptionId);
      if (!("score" in option) || option.score === null) throw new Error(`Snapshot kategori ${item.nameSnapshot} tidak mendukung data legacy.`);
      const score = Number(option.score);
      validateDailyIndicatorValue(rule, score);
      resolved.set(item.id, { status: "AVAILABLE", value: score, categoryOptionId: option.id });
      continue;
    }

    const value = entry.value;
    if (value === null || value === undefined || !Number.isFinite(value)) throw new Error(`Nilai ${item.nameSnapshot} wajib diisi.`);
    validateDailyIndicatorValue(rule, value);
    if (item.kindSnapshot === "RATING" && value < item.targetSnapshot.toNumber() && !note?.trim()) {
      throw new Error("Catatan wajib diisi jika rating berada di bawah target.");
    }
    resolved.set(item.id, { status: "AVAILABLE", value, categoryOptionId: null });
  }
  return resolved;
}

const valueSignature = (value: ResolvedDailyValue) =>
  JSON.stringify([value.status, value.value === null ? null : String(value.value), value.categoryOptionId]);

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
          employee: { include: { user: true } },
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
  const priorValues = new Map(sheet.values.map((value) => [value.monthlyKpiItemId, valueSignature({
    status: value.status,
    value: value.enteredValue?.toNumber() ?? null,
    categoryOptionId: value.categoryOptionId,
  })]));
  const changed = sheet.workStatus !== input.workStatus ||
    sheet.monthlyKpi.items.some((item) => priorValues.get(item.id) !== valueSignature(valueMap.get(item.id)!));
  if (correctingApproved && !changed) throw new Error("Tidak ada perubahan yang perlu disimpan.");

  const valuesComplete = input.workStatus !== "WORKED" ||
    sheet.monthlyKpi.items.every((item) => valueMap.get(item.id)!.status !== "PENDING");
  const nextStatus = correctingApproved
    ? "APPROVED"
    : input.submit
      ? submitDailySheet({ actorRole: actor.role, subjectRole: subject.role, currentStatus: sheet.status, workStatus: input.workStatus, valuesComplete })
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
      data: sheet.monthlyKpi.items.map((item) => {
        const value = valueMap.get(item.id)!;
        return {
          dailySheetId: sheet.id,
          monthlyKpiItemId: item.id,
          enteredValue: value.value,
          status: value.status,
          categoryOptionId: value.categoryOptionId,
          effectiveValue: approved && value.status === "AVAILABLE" ? value.value : null,
        };
      }),
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
    await notifyUsers(tx, [sheet.monthlyKpi.employee.userId], {
      title: "Penilaian harian sedang direview",
      body: `Penilaian Anda untuk ${isoDate(sheet.entryDate)} telah dikirim Supervisor dan menunggu review Manager.`,
      type: "own_daily_submitted",
      actionUrl: `/app/kpi-saya/${sheet.monthlyKpi.id}?sheet=${sheet.id}`,
      dedupeKey: `daily-submitted-owner:${sheet.id}:${sheet.rowVersion + 1}`,
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
          employee: { include: { user: true, supervisor: { include: { user: true } } } },
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
  const currentValues = new Map(sheet.values.map((value) => [value.monthlyKpiItemId, value]));
  const asResolved = (value: (typeof sheet.values)[number]): ResolvedDailyValue => ({
    status: value.status,
    value: value.enteredValue?.toNumber() ?? null,
    categoryOptionId: value.categoryOptionId,
  });
  const reviewedValues = input.decision === "CORRECT"
    ? validatedValues(sheet.monthlyKpi.items, effectiveWorkStatus, input.values ?? [], input.reason)
    : new Map(sheet.monthlyKpi.items.flatMap((item) => {
        const current = currentValues.get(item.id);
        return current ? [[item.id, asResolved(current)] as const] : [];
      }));
  const isCorrected = (itemId: string) => {
    const current = currentValues.get(itemId);
    const next = reviewedValues.get(itemId);
    return !current || !next || valueSignature(asResolved(current)) !== valueSignature(next);
  };
  const changed = input.decision === "CORRECT" && (
    effectiveWorkStatus !== sheet.workStatus || sheet.monthlyKpi.items.some((item) => isCorrected(item.id))
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
      const current = currentValues.get(item.id);
      const next = reviewedValues.get(item.id) ?? EMPTY_VALUE;
      const corrected = input.decision === "CORRECT" && isCorrected(item.id);
      await tx.dailyValue.upsert({
        where: { dailySheetId_monthlyKpiItemId: { dailySheetId: sheet.id, monthlyKpiItemId: item.id } },
        update: {
          managerValue: corrected ? next.value : null,
          managerStatus: corrected ? next.status : null,
          managerCategoryOptionId: corrected ? next.categoryOptionId : null,
          effectiveValue: next.status === "AVAILABLE" ? next.value : null,
        },
        create: {
          dailySheetId: sheet.id,
          monthlyKpiItemId: item.id,
          enteredValue: current?.enteredValue ?? null,
          status: current?.status ?? next.status,
          categoryOptionId: current?.categoryOptionId ?? null,
          managerValue: corrected ? next.value : null,
          managerStatus: corrected ? next.status : null,
          managerCategoryOptionId: corrected ? next.categoryOptionId : null,
          effectiveValue: next.status === "AVAILABLE" ? next.value : null,
        },
      });
    }
  } else {
    await tx.dailyValue.updateMany({
      where: { dailySheetId: sheet.id },
      data: { managerValue: null, managerStatus: null, managerCategoryOptionId: null, effectiveValue: null },
    });
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
  const ownerOutcome = input.decision === "RETURN" ? "dikembalikan untuk diperbaiki" : input.decision === "CORRECT" ? "disetujui dengan koreksi Manager" : "disetujui Manager";
  await notifyUsers(tx, [sheet.monthlyKpi.employee.userId], {
    title: input.decision === "RETURN" ? "Penilaian harian dikembalikan" : input.decision === "CORRECT" ? "Penilaian harian dikoreksi" : "Penilaian harian disetujui",
    body: `Penilaian Anda untuk ${isoDate(sheet.entryDate)} ${ownerOutcome}.`,
    type: input.decision === "RETURN" ? "own_daily_returned" : input.decision === "CORRECT" ? "own_daily_corrected" : "own_daily_approved",
    actionUrl: `/app/kpi-saya/${sheet.monthlyKpi.id}?sheet=${sheet.id}`,
    dedupeKey: `daily-reviewed-owner:${sheet.id}:${sheet.rowVersion + 1}`,
  });
  await recalculateMonthlyKpi(tx, sheet.monthlyKpiId, now);
  return { sheetId: sheet.id, status: nextStatus, rowVersion: sheet.rowVersion + 1 };
}
