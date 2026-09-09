import Decimal from "decimal.js";

type DecimalInput = Decimal.Value;

export type FormulaKey =
  | "higher_is_better"
  | "lower_is_better"
  | "zero_tolerance"
  | "rubric";

export type CalculationInput = {
  formulaKey: FormulaKey;
  weight: DecimalInput;
  actual: DecimalInput | null;
  target?: DecimalInput | null;
  targetJson?: Record<string, DecimalInput> | null;
  formulaParams?: Record<string, DecimalInput> | null;
  assessment?: { scorePoints: DecimalInput; totalPoints: DecimalInput } | null;
};

export type CalculationResult = {
  ok: boolean;
  status: "calculated" | "unscorable" | "pending";
  achievement: string | null;
  weightedScore: string | null;
  note?: string;
  meta?: Record<string, string>;
};

const decimal = (value: DecimalInput) => new Decimal(value);
const six = (value: Decimal) => value.toDecimalPlaces(6, Decimal.ROUND_HALF_UP);
const serialized = (value: Decimal) => six(value).toFixed(6);

function calculated(achievement: Decimal, weight: Decimal, meta?: Record<string, DecimalInput>): CalculationResult {
  const roundedAchievement = six(achievement);
  const weighted = six(roundedAchievement.mul(weight).div(100));
  return {
    ok: true,
    status: "calculated",
    achievement: serialized(roundedAchievement),
    weightedScore: serialized(weighted),
    meta: meta
      ? Object.fromEntries(Object.entries(meta).map(([key, value]) => [key, decimal(value).toString()]))
      : undefined,
  };
}

const pending = (note: string): CalculationResult => ({
  ok: true,
  status: "pending",
  achievement: null,
  weightedScore: null,
  note,
});

const unscorable = (note: string): CalculationResult => ({
  ok: false,
  status: "unscorable",
  achievement: null,
  weightedScore: null,
  note,
});

export function calculateItem(input: CalculationInput): CalculationResult {
  const weight = decimal(input.weight);
  const params = input.formulaParams ?? {};
  const targetJson = input.targetJson ?? {};
  const cap = decimal(params.cap ?? 100);

  if (input.formulaKey === "rubric") {
    if (input.actual != null) {
      const achievement = Decimal.min(100, Decimal.max(0, decimal(input.actual)));
      return calculated(achievement, weight, { weight });
    }
    if (!input.assessment) return pending("Rubrik belum dinilai oleh penilai yang berwenang");
    const total = decimal(input.assessment.totalPoints);
    if (total.lte(0)) return unscorable("Total poin kriteria rubrik tidak valid (0)");
    const score = decimal(input.assessment.scorePoints);
    return calculated(Decimal.min(100, six(score.mul(100).div(total))), weight, {
      score_points: score,
      total_points: total,
      weight,
    });
  }

  if (input.actual == null) return pending("Nilai aktual belum tersedia");
  const actual = decimal(input.actual);

  if (input.formulaKey === "higher_is_better") {
    const target = decimal(input.target ?? 0);
    if (target.lte(0)) return unscorable("Target bernilai 0 atau tidak valid untuk formula higher_is_better");
    const raw = six(actual.mul(100).div(target));
    return calculated(Decimal.min(raw, cap), weight, {
      raw_achievement: raw,
      cap,
      target,
      actual,
      weight,
    });
  }

  if (input.formulaKey === "lower_is_better") {
    const target = decimal(input.target ?? 0);
    const failureValue = targetJson.failure_limit ?? params.failure_limit;
    if (failureValue == null || decimal(failureValue).lte(target)) {
      return unscorable("Failure limit belum dikonfigurasi atau tidak lebih besar dari target");
    }
    const failure = decimal(failureValue);
    const achievement = actual.lte(target)
      ? decimal(100)
      : actual.gte(failure)
        ? decimal(0)
        : six(failure.minus(actual).mul(100).div(failure.minus(target)));
    return calculated(Decimal.min(achievement, cap), weight, {
      target,
      failure_limit: failure,
      actual,
      cap,
      weight,
    });
  }

  const fullValue = targetJson.full_score_limit ?? params.full_score_limit;
  const failureValue = targetJson.failure_limit ?? params.failure_limit;
  if (fullValue == null || failureValue == null) {
    return unscorable("Parameter full score limit dan failure limit wajib dikonfigurasi");
  }
  const full = decimal(fullValue);
  const failure = decimal(failureValue);
  if (failure.lte(full)) return unscorable("Failure limit harus lebih besar dari full score limit");
  const absolute = actual.abs();
  const achievement = absolute.lte(full)
    ? decimal(100)
    : absolute.gte(failure)
      ? decimal(0)
      : six(failure.minus(absolute).mul(100).div(failure.minus(full)));
  return calculated(Decimal.min(achievement, cap), weight, {
    full_score_limit: full,
    failure_limit: failure,
    abs_actual: absolute,
    cap,
    weight,
  });
}

export function calculateTotal(results: CalculationResult[], scoreCap: DecimalInput = 100) {
  if (results.some((result) => result.status !== "calculated" || result.weightedScore == null)) return null;
  const total = results.reduce((sum, result) => sum.plus(result.weightedScore!), decimal(0));
  return Decimal.min(total, decimal(scoreCap)).toDecimalPlaces(2, Decimal.ROUND_HALF_UP).toFixed(2);
}

export function ratingFor(
  score: DecimalInput,
  bands: Array<{ code: string; label: string; minScore: DecimalInput; maxScore: DecimalInput }>,
) {
  const value = decimal(score);
  return bands.find((band) => value.gte(band.minScore) && value.lte(band.maxScore)) ?? null;
}
