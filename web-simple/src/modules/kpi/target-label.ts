type TargetValue = number | string | { toString(): string };

export function formatKpiTarget(indicator: {
  direction: "HIGHER" | "LOWER" | "ZERO_TOLERANCE";
  target: TargetValue;
  failureLimit: TargetValue | null;
  unit: string;
}) {
  const target = withUnit(indicator.target, indicator.unit);
  if (indicator.direction === "ZERO_TOLERANCE") return `harus ${target}`;
  if (indicator.direction === "LOWER") {
    const failure = indicator.failureLimit === null ? "belum diatur" : withUnit(indicator.failureLimit, indicator.unit);
    return `≤ ${target} · gagal pada ${failure}`;
  }
  return `≥ ${target}`;
}

function withUnit(value: TargetValue, unit: string) {
  if (unit === "%") return `${value}%`;
  if (unit === "Rp") return `Rp${value}`;
  return `${value} ${unit}`;
}

export type KpiValueKindLabel = "NUMERIC" | "RATING" | "CHECKBOX" | "CATEGORY" | "SYSTEM" | "IMPORTED";
export type KpiAggregationLabel = "SUM" | "AVERAGE" | "LATEST" | "COUNT";

// Label dipakai bersama oleh tabel konfigurasi admin dan ringkasan rekap bulanan.
export function kindLabel(kind: KpiValueKindLabel) {
  if (kind === "RATING") return "Rating 1–5";
  if (kind === "CHECKBOX") return "Centang";
  if (kind === "CATEGORY") return "Angka + predikat";
  if (kind === "SYSTEM") return "Nilai sistem";
  if (kind === "IMPORTED") return "Impor";
  return "Angka";
}

export function aggregationLabel(aggregation: KpiAggregationLabel) {
  if (aggregation === "AVERAGE") return "Rata-rata";
  if (aggregation === "LATEST") return "Nilai terakhir";
  if (aggregation === "COUNT") return "Jumlah hari";
  return "Jumlah";
}
