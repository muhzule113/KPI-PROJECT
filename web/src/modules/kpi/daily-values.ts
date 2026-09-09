export type JsonObject = Record<string, unknown>;

export const ATTENDANCE_ITEM_CODES = new Set(["ADM-05", "KSR-06", "GUD-07", "CS-06"]);
export const SYSTEM_SOURCE_TYPES = new Set(["system", "import", "cross_role"]);

function object(value: unknown): JsonObject {
  return value !== null && !Array.isArray(value) && typeof value === "object" ? value as JsonObject : {};
}

export function cadence(targetJson: unknown, formulaParams: unknown) {
  const value = object(targetJson).cadence ?? object(formulaParams).cadence;
  return value === "weekly" || value === "period" ? value : "daily";
}

export function datesForDailyItem(input: {
  startDate: Date;
  endDate: Date;
  code: string;
  sourceType: string;
  targetJson: unknown;
  formulaParams: unknown;
}) {
  const start = new Date(Date.UTC(input.startDate.getUTCFullYear(), input.startDate.getUTCMonth(), input.startDate.getUTCDate()));
  const end = new Date(Date.UTC(input.endDate.getUTCFullYear(), input.endDate.getUTCMonth(), input.endDate.getUTCDate()));
  const mode = cadence(input.targetJson, input.formulaParams);
  const system = SYSTEM_SOURCE_TYPES.has(input.sourceType.toLowerCase());
  const attendance = ATTENDANCE_ITEM_CODES.has(input.code);
  const dates: Date[] = [];

  for (let date = start; date <= end; date = new Date(date.valueOf() + 86_400_000)) {
    const isEnd = date.valueOf() === end.valueOf();
    if (attendance && [0, 6].includes(date.getUTCDay())) continue;
    if (mode === "period" && !isEnd) continue;
    if (mode === "weekly" && date.getUTCDay() !== 0 && !isEnd) continue;
    if (system && !attendance && !isEnd) continue;
    dates.push(date);
  }
  return dates;
}

export function manualRatingOptions(value: unknown) {
  const options = object(value).manual_rating_options;
  if (!Array.isArray(options)) return [];
  return options.flatMap((option) => {
    const row = object(option);
    const score = Number(row.score);
    return typeof row.code === "string" && typeof row.label === "string" && Number.isFinite(score)
      ? [{ code: row.code, label: row.label, score }]
      : [];
  });
}

export function rubricCriteria(value: unknown) {
  const criteria = object(value).criteria;
  if (!Array.isArray(criteria)) return [];
  return criteria.flatMap((criterion) => {
    const row = object(criterion);
    const points = Number(row.points);
    return (typeof row.id === "string" || typeof row.id === "number") && typeof row.criterion_text === "string" && Number.isFinite(points) && points >= 0
      ? [{ id: String(row.id), text: row.criterion_text, points }]
      : [];
  });
}

export function ratingCodeFromJson(value: unknown) {
  const code = object(value).rating_code;
  return typeof code === "string" ? code : undefined;
}

export function fulfilledCriterionIds(value: unknown) {
  if (!Array.isArray(value)) return [];
  return value.flatMap((answer) => {
    const row = object(answer);
    return row.is_fulfilled === true && (typeof row.criterion_id === "string" || typeof row.criterion_id === "number")
      ? [String(row.criterion_id)]
      : [];
  });
}

export function aggregateValues(values: number[], average: boolean) {
  if (!values.length) return null;
  const total = values.reduce((sum, value) => sum + value, 0);
  return Math.round((average ? total / values.length : total) * 100) / 100;
}
