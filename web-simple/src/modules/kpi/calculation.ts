import Decimal from "decimal.js";

export type ValueKind = "NUMERIC" | "RATING" | "CHECKBOX" | "CATEGORY" | "SYSTEM" | "IMPORTED";
export type Aggregation = "SUM" | "AVERAGE" | "LATEST" | "COUNT";
export type Direction = "HIGHER" | "LOWER" | "ZERO_TOLERANCE";

export type MonthlyIndicatorInput = {
  kind: ValueKind;
  aggregation: Aggregation;
  direction: Direction;
  values: Array<number | string>;
  target: number | string;
  failureLimit: number | string | null;
  weight: number | string;
  /** Periode lama CATEGORY menyimpan skor opsi; periode baru memakai formula numerik biasa. */
  legacyCategoryScoring?: boolean;
  // Alasan tidak ada nilai yang dapat dihitung, agar catatan bulanan tidak menyesatkan.
  emptyReason?: "NO_VALUES" | "NOT_APPLICABLE" | "MISSING";
};

export type MonthlyIndicatorResult = {
  status: "PENDING" | "CALCULATED" | "UNSCORABLE";
  actual: string | null;
  achievement: string | null;
  weightedScore: string | null;
  note?: string;
};

const fixed = (value: Decimal) => value.toDecimalPlaces(2, Decimal.ROUND_HALF_UP).toFixed(2);

function invalid(note: string): MonthlyIndicatorResult {
  return { status: "UNSCORABLE", actual: null, achievement: null, weightedScore: null, note };
}

export function calculateMonthlyIndicator(input: MonthlyIndicatorInput): MonthlyIndicatorResult {
  if (input.values.length === 0) {
    const note = input.emptyReason === "MISSING"
      ? "Data sistem belum tersedia untuk indikator ini."
      : input.emptyReason === "NOT_APPLICABLE"
        ? "Indikator ditandai tidak berlaku pada seluruh hari yang dinilai."
        : "Belum ada nilai harian yang disetujui.";
    return { status: "PENDING", actual: null, achievement: null, weightedScore: null, note };
  }

  const values = input.values.map((value) => new Decimal(value));
  if (values.some((value) => !value.isFinite())) return invalid("Nilai harian tidak valid.");
  if (input.kind === "RATING" && values.some((value) => !value.isInteger() || value.lt(1) || value.gt(5))) {
    return invalid("Rating harian harus berupa bilangan bulat 1 sampai 5.");
  }
  if (input.kind === "CHECKBOX" && values.some((value) => !value.isInteger() || value.lt(0) || value.gt(1))) {
    return invalid("Nilai harian checkbox harus 0 atau 1.");
  }

  const weight = new Decimal(input.weight);
  if (!weight.isFinite() || weight.lt(0) || weight.gt(100)) return invalid("Bobot indikator harus berada pada rentang 0 sampai 100.");

  const normalized = input.kind === "CHECKBOX" ? values.map((value) => value.mul(100)) : values;
  const sum = normalized.reduce((total, value) => total.plus(value), new Decimal(0));
  const actual = input.aggregation === "AVERAGE"
    ? sum.div(normalized.length)
    : input.aggregation === "LATEST"
      ? normalized[normalized.length - 1]
      : input.aggregation === "COUNT"
        ? new Decimal(normalized.length)
        : sum;
  let achievement: Decimal;

  if (input.kind === "CATEGORY" && input.legacyCategoryScoring) {
    // Skor kategori sudah berupa persentase 0-100; target tidak dipakai untuk normalisasi.
    achievement = actual;
  } else if (input.direction === "ZERO_TOLERANCE") {
    achievement = actual.eq(0) ? new Decimal(100) : new Decimal(0);
  } else {
    const target = new Decimal(input.target);
    if (!target.isFinite() || target.lte(0)) return invalid("Target harus lebih besar dari 0.");
    if (input.direction === "HIGHER") {
      achievement = actual.mul(100).div(target);
    } else {
      if (input.failureLimit === null) return invalid("Failure limit wajib diisi untuk formula lower.");
      const failureLimit = new Decimal(input.failureLimit);
      if (!failureLimit.isFinite() || failureLimit.lte(target)) return invalid("Failure limit harus lebih besar dari target.");
      achievement = actual.lte(target)
        ? new Decimal(100)
        : actual.gte(failureLimit)
          ? new Decimal(0)
          : failureLimit.minus(actual).mul(100).div(failureLimit.minus(target));
    }
  }

  achievement = Decimal.min(100, Decimal.max(0, achievement));
  return {
    status: "CALCULATED",
    actual: fixed(actual),
    achievement: fixed(achievement),
    weightedScore: fixed(achievement.mul(weight).div(100)),
  };
}

export function calculateFinalScore(results: MonthlyIndicatorResult[]) {
  if (results.length === 0 || results.some((result) => result.status !== "CALCULATED" || result.weightedScore === null)) return null;
  const total = results.reduce((sum, result) => sum.plus(result.weightedScore!), new Decimal(0));
  return fixed(Decimal.min(100, Decimal.max(0, total)));
}
