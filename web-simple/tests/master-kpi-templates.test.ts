import assert from "node:assert/strict";
import test from "node:test";
import { MASTER_KPI_TEMPLATES } from "../src/modules/kpi/master-kpi-templates.ts";
import { calculateMonthlyIndicator } from "../src/modules/kpi/calculation.ts";
import { validateTemplate } from "../src/modules/kpi/period.ts";

const expected = {
  TEKNISI: [
    ["TEK-01", 25, 80, null, "SUM", "HIGHER", "unit"],
    ["TEK-02", 25, 95, null, "AVERAGE", "HIGHER", "%"],
    ["TEK-03", 15, 3, 6, "AVERAGE", "LOWER", "%"],
    ["TEK-04", 15, 95, null, "AVERAGE", "HIGHER", "%"],
    ["TEK-05", 10, 95, null, "AVERAGE", "HIGHER", "%"],
    ["TEK-06", 5, 90, null, "AVERAGE", "HIGHER", "%"],
    ["TEK-07", 5, 100, null, "AVERAGE", "HIGHER", "%"],
  ],
  PELAYAN: [
    ["CS-01", 25, 90, null, "AVERAGE", "HIGHER", "%"],
    ["CS-02", 20, 95, null, "AVERAGE", "HIGHER", "%"],
    ["CS-03", 20, 98, null, "AVERAGE", "HIGHER", "%"],
    ["CS-04", 15, 95, null, "AVERAGE", "HIGHER", "%"],
    ["CS-05", 10, 3, 5, "SUM", "LOWER", "komplain"],
    ["CS-06", 10, 95, null, "AVERAGE", "HIGHER", "%"],
  ],
  ADMIN_OPS: [
    ["ADM-01", 30, 98, null, "AVERAGE", "HIGHER", "%"],
    ["ADM-02", 25, 100, null, "AVERAGE", "HIGHER", "%"],
    ["ADM-03", 15, 98, null, "AVERAGE", "HIGHER", "%"],
    ["ADM-04", 15, 98, null, "AVERAGE", "HIGHER", "%"],
    ["ADM-05", 10, 95, null, "AVERAGE", "HIGHER", "%"],
    ["ADM-06", 5, 95, null, "AVERAGE", "HIGHER", "%"],
  ],
  KASIR: [
    ["KSR-01", 30, 99, null, "AVERAGE", "HIGHER", "%"],
    ["KSR-02", 25, 0, null, "SUM", "ZERO_TOLERANCE", "Rp"],
    ["KSR-03", 20, 100, null, "AVERAGE", "HIGHER", "%"],
    ["KSR-04", 10, 95, null, "AVERAGE", "HIGHER", "%"],
    ["KSR-05", 10, 90, null, "AVERAGE", "HIGHER", "%"],
    ["KSR-06", 5, 95, null, "AVERAGE", "HIGHER", "%"],
  ],
  GUDANG: [
    ["GUD-01", 30, 98, null, "AVERAGE", "HIGHER", "%"],
    ["GUD-02", 20, 2, 5, "AVERAGE", "LOWER", "%"],
    ["GUD-03", 15, 95, null, "AVERAGE", "HIGHER", "%"],
    ["GUD-04", 15, 95, null, "AVERAGE", "HIGHER", "%"],
    ["GUD-05", 10, 100, null, "AVERAGE", "HIGHER", "%"],
    ["GUD-06", 5, 90, null, "AVERAGE", "HIGHER", "%"],
    ["GUD-07", 5, 95, null, "AVERAGE", "HIGHER", "%"],
  ],
  SPV: [
    ["SUP-01", 30, 90, null, "AVERAGE", "HIGHER", "%"],
    ["SUP-02", 20, 95, null, "AVERAGE", "HIGHER", "%"],
    ["SUP-03", 15, 95, null, "AVERAGE", "HIGHER", "%"],
    ["SUP-04", 10, 90, null, "AVERAGE", "HIGHER", "%"],
    ["SUP-05", 10, 100, null, "AVERAGE", "HIGHER", "%"],
    ["SUP-06", 10, 95, null, "AVERAGE", "HIGHER", "%"],
    ["SUP-07", 5, 100, null, "AVERAGE", "HIGHER", "%"],
  ],
} as const;

test("katalog baku memuat enam template dan 39 indikator sesuai urutan", () => {
  assert.deepEqual(Object.keys(MASTER_KPI_TEMPLATES), Object.keys(expected));
  assert.equal(Object.values(MASTER_KPI_TEMPLATES).flat().length, 39);
  assert.equal(new Set(Object.values(MASTER_KPI_TEMPLATES).flat().map((indicator) => indicator.code)).size, 39);

  for (const [positionCode, tuples] of Object.entries(expected)) {
    const indicators = MASTER_KPI_TEMPLATES[positionCode as keyof typeof MASTER_KPI_TEMPLATES];
    assert.deepEqual(indicators.map((indicator) => [
      indicator.code,
      indicator.weight,
      indicator.target,
      indicator.failureLimit,
      indicator.aggregation,
      indicator.direction,
      indicator.unit,
    ]), tuples);
    assert.deepEqual(indicators.map((indicator) => indicator.sortOrder), indicators.map((_, index) => index + 1));
    assert.equal(indicators.reduce((total, indicator) => total + Number(indicator.weight), 0), 100);
    assert.deepEqual(validateTemplate(indicators), { ok: true });
    assert.ok(indicators.every((indicator) => indicator.kind === "NUMERIC"));
  }
});

test("formula KPI lower dan kas nol mengikuti batas katalog", () => {
  assert.equal(score("TEKNISI", "TEK-03", [4.5]).weightedScore, "7.50");
  assert.equal(score("PELAYAN", "CS-05", [2, 2]).weightedScore, "5.00");
  assert.equal(score("GUDANG", "GUD-02", [3.5]).weightedScore, "10.00");
  assert.equal(score("KASIR", "KSR-02", [0]).achievement, "100.00");
  assert.equal(score("KASIR", "KSR-02", [1]).achievement, "0.00");
});

function score(positionCode: keyof typeof MASTER_KPI_TEMPLATES, indicatorCode: string, values: number[]) {
  const indicator = MASTER_KPI_TEMPLATES[positionCode].find((candidate) => candidate.code === indicatorCode);
  assert.ok(indicator);
  return calculateMonthlyIndicator({ ...indicator, values });
}
