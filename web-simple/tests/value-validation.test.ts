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

test("checkbox hanya menerima 0 atau 1", () => {
  const checkbox = { name: "Kebersihan area", kind: "CHECKBOX" as const, unit: "centang" };
  assert.doesNotThrow(() => validateDailyIndicatorValue(checkbox, 0));
  assert.doesNotThrow(() => validateDailyIndicatorValue(checkbox, 1));
  assert.throws(() => validateDailyIndicatorValue(checkbox, 2), /centang/i);
  assert.throws(() => validateDailyIndicatorValue(checkbox, 0.5), /centang/i);
  assert.throws(() => validateDailyIndicatorValue(checkbox, -1), /centang/i);
});

test("skor kategori wajib berada pada rentang 0 sampai 100", () => {
  const category = { name: "Kepatuhan SOP", kind: "CATEGORY" as const, unit: "poin" };
  assert.doesNotThrow(() => validateDailyIndicatorValue(category, 0));
  assert.doesNotThrow(() => validateDailyIndicatorValue(category, 100));
  assert.doesNotThrow(() => validateDailyIndicatorValue(category, 100.01));
  assert.throws(() => validateDailyIndicatorValue(category, -0.01), /tidak valid/);
});

test("kategori berunit persen tidak menambah batas baru", () => {
  const category = { name: "Kepatuhan SOP", kind: "CATEGORY" as const, unit: "%" };
  assert.doesNotThrow(() => validateDailyIndicatorValue(category, 100));
  assert.doesNotThrow(() => validateDailyIndicatorValue(category, 0));
  assert.throws(() => validateDailyIndicatorValue(category, 100.01), /0 sampai 100/);
});

test("rating tetap bilangan bulat 1 sampai 5", () => {
  const rating = { name: "Keramahan", kind: "RATING" as const, unit: "rating" };
  assert.doesNotThrow(() => validateDailyIndicatorValue(rating, 1));
  assert.doesNotThrow(() => validateDailyIndicatorValue(rating, 5));
  assert.throws(() => validateDailyIndicatorValue(rating, 4.5), /bilangan bulat 1 sampai 5/);
  assert.throws(() => validateDailyIndicatorValue(rating, 0), /bilangan bulat 1 sampai 5/);
  assert.throws(() => validateDailyIndicatorValue(rating, 6), /bilangan bulat 1 sampai 5/);
});

test("numerik non-persen menerima tepat batas atas 1e9", () => {
  const numeric = { name: "Jumlah unit", kind: "NUMERIC" as const, unit: "unit" };
  assert.doesNotThrow(() => validateDailyIndicatorValue(numeric, 1_000_000_000));
  assert.throws(() => validateDailyIndicatorValue(numeric, 1_000_000_000.001), /tidak valid/);
});
