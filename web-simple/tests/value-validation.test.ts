import assert from "node:assert/strict";
import test from "node:test";
import { validateDailyIndicatorValue } from "../src/modules/kpi/value-validation.ts";

test("nilai persentase wajib berada pada rentang 0 sampai 100", () => {
  const percentage = { name: "Kepatuhan SOP", kind: "NUMERIC" as const, unit: "%" };
  assert.doesNotThrow(() => validateDailyIndicatorValue(percentage, 0));
  assert.doesNotThrow(() => validateDailyIndicatorValue(percentage, 100));
  assert.throws(() => validateDailyIndicatorValue(percentage, -0.01), /tidak valid/);
  assert.throws(() => validateDailyIndicatorValue(percentage, 100.01), /0 sampai 100/);
});

test("jumlah, rupiah, dan rating mempertahankan batas masing-masing", () => {
  assert.doesNotThrow(() => validateDailyIndicatorValue({ name: "Jumlah servis", kind: "NUMERIC", unit: "unit" }, 120));
  assert.doesNotThrow(() => validateDailyIndicatorValue({ name: "Selisih kas", kind: "NUMERIC", unit: "Rp" }, 50_000));
  assert.throws(() => validateDailyIndicatorValue({ name: "Rating lama", kind: "RATING", unit: "rating" }, 4.5), /bilangan bulat 1 sampai 5/);
  assert.throws(() => validateDailyIndicatorValue({ name: "Jumlah servis", kind: "NUMERIC", unit: "unit" }, 1_000_000_001), /tidak valid/);
});
