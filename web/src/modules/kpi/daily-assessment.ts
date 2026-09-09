import { Prisma } from "@/generated/prisma/client";
import { canManageKpi, canReviewKpi, hasCapability, type AccessProfile } from "@/modules/access/capabilities";
import { aggregateValues, ATTENDANCE_ITEM_CODES, manualRatingOptions, rubricCriteria, SYSTEM_SOURCE_TYPES } from "@/modules/kpi/daily-values";
import { recalculateKpi } from "@/modules/kpi/recalculate";

export type DailyRole = "supervisor" | "manager";
export type DailyDecision = "approved" | "revision_required";
export type DailyAssessmentInput = {
  entryId: string;
  rowVersion: number;
  role: DailyRole;
  decision: DailyDecision;
  note?: string;
  actual?: number;
  ratingCode?: string;
  criterionIds?: string[];
};

const mutableKpiStatuses = new Set(["DRAFT", "SUBMITTED", "UNDER_REVIEW", "REVISION_REQUIRED", "VERIFIED", "PENDING_APPROVAL"]);

function jsonObject(value: Prisma.JsonValue | null): Record<string, unknown> {
  return value !== null && !Array.isArray(value) && typeof value === "object" ? value : {};
}

function excluded(entry: { systemActualJson: Prisma.JsonValue | null }) {
  return jsonObject(entry.systemActualJson).excluded_from_ratio === true;
}

function effectiveValue(entry: {
  managerActualDecimal: { toNumber(): number } | null;
  managerScorePercentage: { toNumber(): number } | null;
  supervisorActualDecimal: { toNumber(): number } | null;
  supervisorScorePercentage: { toNumber(): number } | null;
  employeeActualDecimal: { toNumber(): number } | null;
  systemActualDecimal: { toNumber(): number } | null;
}, managerPrimary: boolean) {
  const value = managerPrimary
    ? entry.managerScorePercentage ?? entry.managerActualDecimal
    : entry.supervisorScorePercentage ?? entry.supervisorActualDecimal;
  return value?.toNumber() ?? entry.employeeActualDecimal?.toNumber() ?? entry.systemActualDecimal?.toNumber() ?? null;
}

function sameJson(left: Prisma.JsonValue | null, right: Prisma.InputJsonValue | typeof Prisma.JsonNull) {
  return JSON.stringify(left ?? null) === JSON.stringify(right === Prisma.JsonNull ? null : right);
}

export async function aggregateDailyKpi(tx: Prisma.TransactionClient, kpiId: string, actorId: string) {
  const kpi = await tx.employeeKpi.findUniqueOrThrow({
    where: { id: kpiId },
    include: { items: { include: { dailyEntries: { orderBy: { entryDate: "asc" } } } } },
  });
  const managerPrimary = kpi.positionCodeSnapshot === "POS-SPV";
  let changed = false;

  for (const item of kpi.items) {
    const entries = item.dailyEntries.filter((entry) => !excluded(entry));
    const approved = entries.filter((entry) => managerPrimary ? entry.managerStatus === "APPROVED" : entry.supervisorStatus === "APPROVED");
    const system = SYSTEM_SOURCE_TYPES.has(item.sourceTypeSnapshot.toLowerCase()) || ATTENDANCE_ITEM_CODES.has(item.definitionCodeSnapshot);
    const completed = entries.length > 0 && approved.length === entries.length;
    let actual: number | null = item.actualDecimal?.toNumber() ?? null;
    let actualJson: Prisma.InputJsonValue | typeof Prisma.JsonNull = item.actualJson ?? Prisma.JsonNull;
    let status: "DRAFT" | "VERIFIED" | "ASSESSED" = completed && actual !== null ? (managerPrimary ? "ASSESSED" : "VERIFIED") : "DRAFT";

    if (!system) {
      const values = approved.map((entry) => effectiveValue(entry, managerPrimary)).filter((value): value is number => value !== null);
      actual = aggregateValues(values, item.formulaKeySnapshot === "rubric" || manualRatingOptions(item.rubricSnapshot).length > 0 || ["%", "persen"].includes(item.targetUnitSnapshot.toLowerCase()));
      actualJson = {
        _daily_aggregate: true,
        aggregation: item.formulaKeySnapshot === "rubric" || manualRatingOptions(item.rubricSnapshot).length > 0 || ["%", "persen"].includes(item.targetUnitSnapshot.toLowerCase()) ? "average" : "sum",
        approved_days: values.length,
        first_date: approved[0]?.entryDate.toISOString().slice(0, 10) ?? null,
        last_date: approved.at(-1)?.entryDate.toISOString().slice(0, 10) ?? null,
      };
      status = completed && values.length === entries.length ? (managerPrimary ? "ASSESSED" : "VERIFIED") : "DRAFT";
    }

    const itemChanged = (item.actualDecimal?.toNumber() ?? null) !== actual || item.status !== status || !sameJson(item.actualJson, actualJson);
    if (!itemChanged) continue;
    await tx.employeeKpiItem.update({
      where: { id: item.id },
      data: {
        actualDecimal: actual,
        actualJson,
        status,
        managerDecision: null,
        managerNote: null,
        managerEvidenceJson: Prisma.JsonNull,
        managerDecidedById: null,
        managerDecidedAt: null,
        rowVersion: { increment: 1 },
      },
    });
    if (!system && actual !== null) await tx.kpiActualEntry.create({
      data: { employeeKpiItemId: item.id, inputById: actorId, actualValue: actual, actualJson, notes: "Agregasi penilaian harian penilai yang ditugaskan." },
    });
    changed = true;
  }

  if (!changed) return null;
  if (["VERIFIED", "PENDING_APPROVAL"].includes(kpi.status)) {
    await tx.employeeKpi.update({ where: { id: kpi.id }, data: { status: "UNDER_REVIEW", verifiedAt: null, rowVersion: { increment: 1 } } });
    await tx.kpiApproval.deleteMany({ where: { employeeKpiId: kpi.id } });
  }
  return recalculateKpi(tx, kpi.id, "daily_aggregation", actorId);
}

export async function assessDailyEntry(tx: Prisma.TransactionClient, user: AccessProfile, input: DailyAssessmentInput) {
  await tx.$queryRaw`SELECT id FROM kpi_daily_entries WHERE id = ${input.entryId} FOR UPDATE`;
  const entry = await tx.kpiDailyEntry.findUnique({
    where: { id: input.entryId },
    include: { item: { include: { employeeKpi: { include: { period: true, employee: { select: { userId: true } } } } } } },
  });
  if (!entry) throw new Error("Penilaian KPI harian tidak ditemukan.");
  if (entry.rowVersion !== input.rowVersion) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
  const item = entry.item;
  const kpi = item.employeeKpi;
  if (!mutableKpiStatuses.has(kpi.status)) throw new Error("KPI ini sudah final dan tidak dapat diubah melalui penilaian harian.");
  if (!["OPEN", "SUBMISSION_CLOSED", "IN_REVIEW", "WAITING_APPROVAL"].includes(kpi.period.status)) throw new Error("Periode KPI harian tidak sedang terbuka.");
  const deadline = input.role === "manager" ? kpi.period.approvalDeadline : kpi.period.reviewDeadline;
  if (deadline < new Date()) throw new Error("Batas waktu penilaian KPI harian telah berakhir.");
  if (input.role === "supervisor") {
    if (!hasCapability(user, "kpi.supervisor.daily") || !canReviewKpi(user, kpi)) throw new Error("Anda tidak berwenang menilai KPI harian ini.");
  } else if (!hasCapability(user, "kpi.manager.daily") || !canManageKpi(user, kpi)) {
    throw new Error("Anda tidak berwenang menilai KPI harian ini.");
  }
  const managerPrimary = input.role === "manager" && kpi.positionCodeSnapshot === "POS-SPV";
  if (input.role === "manager" && !managerPrimary && entry.supervisorStatus !== "APPROVED") throw new Error("Penilaian Supervisor harus disetujui terlebih dahulu.");
  const note = input.note?.trim() || null;
  if (input.decision === "revision_required" && !note) throw new Error("Alasan revisi wajib diisi.");

  const system = SYSTEM_SOURCE_TYPES.has(item.sourceTypeSnapshot.toLowerCase()) || ATTENDANCE_ITEM_CODES.has(item.definitionCodeSnapshot);
  const ratings = manualRatingOptions(item.rubricSnapshot);
  const criteria = rubricCriteria(item.rubricSnapshot);
  let actual: number | null = null;
  let actualJson: Prisma.InputJsonValue | typeof Prisma.JsonNull = Prisma.JsonNull;
  let answers: Prisma.InputJsonValue | typeof Prisma.JsonNull = Prisma.JsonNull;
  let score: number | null = null;

  if (input.decision === "approved") {
    if (system) {
      const systemValue = entry.systemActualDecimal?.toNumber() ?? item.actualDecimal?.toNumber() ?? null;
      if (systemValue === null) throw new Error("Nilai sumber resmi belum tersedia. Lengkapi atau sinkronkan data operasional terlebih dahulu.");
    } else if (ratings.length) {
      const rating = ratings.find((option) => option.code === input.ratingCode);
      if (!rating) throw new Error("Pilih predikat penilaian yang valid.");
      score = rating.score;
      actualJson = { rating_code: rating.code, rating_label: rating.label };
      if ((input.role === "supervisor" || managerPrimary) && item.targetValueSnapshot !== null && score < item.targetValueSnapshot.toNumber() && !note) throw new Error("Catatan wajib diisi jika predikat berada di bawah target.");
    } else if (item.formulaKeySnapshot === "rubric") {
      if (!criteria.length) throw new Error("Rubrik indikator belum memiliki kriteria yang valid.");
      const selected = new Set(input.criterionIds ?? []);
      if ([...selected].some((id) => !criteria.some((criterion) => criterion.id === id))) throw new Error("Kriteria rubrik tidak valid.");
      const total = criteria.reduce((sum, criterion) => sum + criterion.points, 0);
      if (total <= 0) throw new Error("Total poin rubrik tidak valid.");
      answers = criteria.map((criterion) => ({ criterion_id: criterion.id, criterion_text: criterion.text, points: criterion.points, is_fulfilled: selected.has(criterion.id) }));
      score = Math.round(criteria.filter((criterion) => selected.has(criterion.id)).reduce((sum, criterion) => sum + criterion.points, 0) / total * 10_000) / 100;
    } else {
      const baseline = entry.supervisorActualDecimal?.toNumber() ?? entry.employeeActualDecimal?.toNumber() ?? null;
      actual = input.actual ?? (input.role === "manager" && !managerPrimary ? baseline : null);
      if (actual === null || !Number.isFinite(actual)) throw new Error("Nilai aktual harian wajib diisi.");
      if (input.role === "manager" && !managerPrimary && baseline !== null && Math.abs(actual - baseline) > 0.000001 && !note) throw new Error("Perubahan nilai oleh Manager wajib menyertakan alasan.");
    }
    if (input.role === "manager" && !managerPrimary && entry.supervisorScorePercentage !== null && score !== null && Math.abs(score - entry.supervisorScorePercentage.toNumber()) > 0.000001 && !note) throw new Error("Perubahan penilaian oleh Manager wajib menyertakan alasan.");
  }

  const before = { entryStatus: entry.entryStatus, supervisorStatus: entry.supervisorStatus, managerStatus: entry.managerStatus, rowVersion: entry.rowVersion };
  const approved = input.decision === "approved";
  const data: Prisma.KpiDailyEntryUncheckedUpdateManyInput = input.role === "supervisor" ? {
    entryStatus: approved ? "submitted" : "revision_required",
    supervisorActualDecimal: approved && !system && !ratings.length && item.formulaKeySnapshot !== "rubric" ? actual : null,
    supervisorActualJson: approved ? actualJson : Prisma.JsonNull,
    supervisorAnswersJson: approved ? answers : Prisma.JsonNull,
    supervisorScorePercentage: approved ? score : null,
    supervisorNote: note,
    supervisorAssessedById: user.id,
    supervisorStatus: input.decision === "approved" ? "APPROVED" : "REVISION_REQUIRED",
    supervisorAssessedAt: new Date(),
    managerActualDecimal: null,
    managerActualJson: Prisma.JsonNull,
    managerAnswersJson: Prisma.JsonNull,
    managerScorePercentage: null,
    managerNote: null,
    managerAssessedById: null,
    managerStatus: "PENDING",
    managerAssessedAt: null,
    rowVersion: { increment: 1 },
  } : {
    entryStatus: approved ? "submitted" : "revision_required",
    managerActualDecimal: approved ? (system || ratings.length || item.formulaKeySnapshot === "rubric" ? null : actual) : null,
    managerActualJson: approved ? (managerPrimary || ratings.length ? actualJson : entry.supervisorActualJson ?? Prisma.JsonNull) : Prisma.JsonNull,
    managerAnswersJson: approved ? (managerPrimary || item.formulaKeySnapshot === "rubric" ? answers : entry.supervisorAnswersJson ?? Prisma.JsonNull) : Prisma.JsonNull,
    managerScorePercentage: approved ? (managerPrimary ? score : score ?? entry.supervisorScorePercentage) : null,
    managerNote: note,
    managerAssessedById: user.id,
    managerStatus: input.decision === "approved" ? "APPROVED" : "REVISION_REQUIRED",
    managerAssessedAt: new Date(),
    rowVersion: { increment: 1 },
  };
  const updated = await tx.kpiDailyEntry.updateMany({ where: { id: entry.id, rowVersion: entry.rowVersion }, data });
  if (updated.count !== 1) throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
  if (kpi.status === "DRAFT") await tx.employeeKpi.update({ where: { id: kpi.id }, data: { status: "SUBMITTED", submittedAt: new Date(), rowVersion: { increment: 1 } } });
  await aggregateDailyKpi(tx, kpi.id, user.id);
  await tx.auditEvent.create({
    data: {
      actorId: user.id,
      action: input.role === "manager" ? "assess_daily_kpi_manager" : "assess_daily_kpi_supervisor",
      subjectType: "KpiDailyEntry",
      subjectId: entry.id,
      beforeJson: before,
      afterJson: { decision: input.decision, actual, score, rowVersion: entry.rowVersion + 1 },
      reason: note,
    },
  });
  if (kpi.employee.userId) await tx.systemNotification.create({
    data: {
      userId: kpi.employee.userId,
      title: input.decision === "approved" ? "Penilaian KPI harian disetujui" : "Penilaian KPI harian perlu koreksi",
      body: `${item.nameSnapshot} untuk ${entry.entryDate.toISOString().slice(0, 10)} ${input.decision === "approved" ? "telah disetujui" : "dikembalikan dengan catatan"}.`,
      type: input.decision === "approved" ? "daily_kpi_approved" : "daily_kpi_revision",
      entityType: "KpiDailyEntry",
      entityId: entry.id,
      actionUrl: `/app/kpi-saya/${kpi.id}`,
      dedupeKey: `daily:${input.role}:${entry.id}:${entry.rowVersion + 1}`,
    },
  });
  return { kpiId: kpi.id, entryDate: entry.entryDate };
}
