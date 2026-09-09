import type Decimal from "decimal.js";
import type { Prisma } from "@/generated/prisma/client";
import { calculateItem, calculateTotal, ratingFor, type CalculationResult, type FormulaKey } from "@/modules/kpi/calculation";

const formulas = new Set<FormulaKey>(["higher_is_better", "lower_is_better", "zero_tolerance", "rubric"]);

function decimalRecord(value: Prisma.JsonValue | null): Record<string, Decimal.Value> | null {
  if (!value || Array.isArray(value) || typeof value !== "object") return null;
  return Object.fromEntries(
    Object.entries(value).filter((entry): entry is [string, string | number] => typeof entry[1] === "string" || typeof entry[1] === "number"),
  );
}

function ratingBands(value: Prisma.JsonValue | null) {
  if (!Array.isArray(value)) return [];
  return value.flatMap((item) => {
    if (!item || Array.isArray(item) || typeof item !== "object") return [];
    const code = item.code;
    const label = item.label;
    const minScore = item.min_score;
    const maxScore = item.max_score;
    return typeof code === "string" && typeof label === "string" && ["string", "number"].includes(typeof minScore) && ["string", "number"].includes(typeof maxScore)
      ? [{ code, label, minScore: minScore as string | number, maxScore: maxScore as string | number }]
      : [];
  });
}

export async function recalculateKpi(tx: Prisma.TransactionClient, employeeKpiId: string, runType: string, actorId?: string) {
  const kpi = await tx.employeeKpi.findUniqueOrThrow({
    where: { id: employeeKpiId },
    include: { items: { include: { assessments: { orderBy: { createdAt: "desc" }, take: 1 } } } },
  });

  const results: CalculationResult[] = [];
  const snapshots = [];
  let filled = 0;
  for (const item of kpi.items) {
    const formula = formulas.has(item.formulaKeySnapshot as FormulaKey) ? item.formulaKeySnapshot as FormulaKey : null;
    const assessment = item.assessments[0];
    const result = formula
      ? calculateItem({
          formulaKey: formula,
          weight: item.weightSnapshot.toString(),
          actual: item.actualDecimal?.toString() ?? null,
          target: item.targetValueSnapshot?.toString() ?? null,
          targetJson: decimalRecord(item.targetJsonSnapshot),
          formulaParams: decimalRecord(item.formulaParamsSnapshot),
          assessment: assessment ? { scorePoints: assessment.scorePoints.toString(), totalPoints: assessment.totalPoints.toString() } : null,
        })
      : { ok: false, status: "unscorable" as const, achievement: null, weightedScore: null, note: `Formula '${item.formulaKeySnapshot}' tidak dikenali.` };

    await tx.employeeKpiItem.update({
      where: { id: item.id },
      data: {
        achievementPercentage: result.achievement,
        weightedScore: result.weightedScore,
        calculationStatus: result.status.toUpperCase() as "CALCULATED" | "PENDING" | "UNSCORABLE",
        calculationNote: result.note ?? null,
      },
    });
    if (item.actualDecimal != null || item.actualJson != null || ["VERIFIED", "ASSESSED"].includes(item.status)) filled += 1;
    results.push(result);
    snapshots.push({ itemId: item.id, code: item.definitionCodeSnapshot, actual: item.actualDecimal?.toString() ?? null, formula: item.formulaKeySnapshot, achievement: result.achievement, weightedScore: result.weightedScore, status: result.status, note: result.note ?? null });
  }

  const finalScore = calculateTotal(results, kpi.scoreCapSnapshot.toString());
  const band = finalScore ? ratingFor(finalScore, ratingBands(kpi.ratingBandsSnapshot)) : null;
  const fallback = finalScore == null ? null : Number(finalScore) >= 95 ? { code: "STAR", label: "Istimewa" } : Number(finalScore) >= 80 ? { code: "GOOD", label: "Baik" } : { code: "FAIR", label: "Cukup" };
  const progress = kpi.items.length ? (filled / kpi.items.length) * 100 : 0;

  await tx.employeeKpi.update({
    where: { id: kpi.id },
    data: { finalScore, ratingCode: band?.code ?? fallback?.code ?? null, ratingLabel: band?.label ?? fallback?.label ?? null, progressPercentage: progress },
  });
  await tx.kpiCalculationRun.create({
    data: {
      employeeKpiId: kpi.id,
      runType,
      inputSnapshot: { periodId: kpi.periodId, employeeId: kpi.employeeId, templateVersionId: kpi.templateVersionId, revisionNumber: kpi.revisionNumber, items: snapshots.map(({ itemId, actual, formula }) => ({ itemId, actual, formula })) },
      outputSnapshot: { totalScore: finalScore, ratingCode: band?.code ?? fallback?.code ?? null, allItemsCalculated: finalScore != null, items: snapshots },
      totalScore: finalScore,
      ratingCode: band?.code ?? fallback?.code ?? null,
      calculatedById: actorId,
      calculatedAt: new Date(),
    },
  });

  return { finalScore, progress };
}
