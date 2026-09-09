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
