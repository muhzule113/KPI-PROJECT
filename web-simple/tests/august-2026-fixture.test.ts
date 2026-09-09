import assert from "node:assert/strict";
import test from "node:test";
import {
  AUGUST_2026_ROSTER,
  AUGUST_WORKED_DAYS,
  FIXTURE_TEMPLATES,
  fixtureValuesForIndicator,
} from "../src/modules/kpi/august-2026-fixture.ts";
import { calculateFinalScore, calculateMonthlyIndicator } from "../src/modules/kpi/calculation.ts";

test("roster Agustus memuat 35 user unik sesuai jabatan", () => {
  assert.equal(AUGUST_2026_ROSTER.length, 35);
  assert.equal(new Set(AUGUST_2026_ROSTER.map((employee) => employee.username)).size, 35);
  assert.equal(new Set(AUGUST_2026_ROSTER.map((employee) => employee.employeeNumber)).size, 35);
  assert.deepEqual(
    Object.fromEntries(Object.entries(Object.groupBy(AUGUST_2026_ROSTER, (employee) => employee.positionCode)).map(([code, employees]) => [code, employees?.length])),
    { CREW: 4, KASIR: 9, PELAYAN: 5, TEKNISI: 15, KURIR: 1, ADMIN_OPS: 1 },
  );
});

test("fixture membuat tepat 30 nilai harian yang valid untuk setiap indikator baru", () => {
  for (const indicators of Object.values(FIXTURE_TEMPLATES)) {
    for (const indicator of indicators) {
      const values = fixtureValuesForIndicator(0, indicator);
      assert.equal(values.length, AUGUST_WORKED_DAYS);
      assert.ok(values.every((value) => Number.isFinite(value) && value >= 0));
      if (indicator.kind === "RATING") assert.ok(values.every((value) => Number.isInteger(value) && value >= 1 && value <= 5));
    }
  }
});

test("contoh Crew dan Kasir menghasilkan skor bulanan literal", () => {
  assert.equal(scoreFor("CREW", 0), "77.80");
  assert.equal(scoreFor("KASIR", 1), "67.50");
});

function scoreFor(positionCode: keyof typeof FIXTURE_TEMPLATES, profileIndex: number) {
  return calculateFinalScore(FIXTURE_TEMPLATES[positionCode].map((indicator) => calculateMonthlyIndicator({
    ...indicator,
    values: fixtureValuesForIndicator(profileIndex, indicator),
  })));
}
