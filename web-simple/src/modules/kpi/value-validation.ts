export type DailyIndicatorValueRule = {
  name: string;
  kind: "NUMERIC" | "RATING" | "CHECKBOX" | "CATEGORY" | "SYSTEM" | "IMPORTED";
  unit: string;
};

export function validateDailyIndicatorValue(item: DailyIndicatorValueRule, value: number) {
  if (!Number.isFinite(value)) throw new Error(`Nilai ${item.name} tidak valid.`);
  if (item.kind === "RATING" && (!Number.isInteger(value) || value < 1 || value > 5)) {
    throw new Error(`Rating ${item.name} harus berupa bilangan bulat 1 sampai 5.`);
  }
  if (item.kind === "CHECKBOX" && value !== 0 && value !== 1) {
    throw new Error(`${item.name} hanya dapat dicentang atau tidak dicentang.`);
  }
  if (value < 0 || value > 1_000_000_000) throw new Error(`Nilai ${item.name} tidak valid.`);
  if (item.unit.trim() === "%" && (item.kind === "NUMERIC" || item.kind === "CATEGORY") && value > 100) {
    throw new Error(`Nilai ${item.name} harus berada pada rentang 0 sampai 100%.`);
  }
}
