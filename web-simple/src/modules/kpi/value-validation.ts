export type DailyIndicatorValueRule = {
  name: string;
  kind: "NUMERIC" | "RATING";
  unit: string;
};

export function validateDailyIndicatorValue(item: DailyIndicatorValueRule, value: number) {
  if (!Number.isFinite(value) || value < 0 || value > 1_000_000_000) throw new Error(`Nilai ${item.name} tidak valid.`);
  if (item.kind === "RATING" && (!Number.isInteger(value) || value < 1 || value > 5)) {
    throw new Error(`Rating ${item.name} harus berupa bilangan bulat 1 sampai 5.`);
  }
  if (item.kind === "NUMERIC" && item.unit.trim() === "%" && value > 100) {
    throw new Error(`Nilai ${item.name} harus berada pada rentang 0 sampai 100%.`);
  }
}
