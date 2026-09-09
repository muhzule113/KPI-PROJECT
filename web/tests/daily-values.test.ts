import assert from "node:assert/strict";
import test from "node:test";
import { aggregateValues, datesForDailyItem, fulfilledCriterionIds, manualRatingOptions, ratingCodeFromJson } from "../src/modules/kpi/daily-values.ts";

test("tugas harian menghormati cadence, akhir periode, dan snapshot predikat", () => {
  const startDate = new Date("2026-09-01T00:00:00.000Z");
  const endDate = new Date("2026-09-08T00:00:00.000Z");
  assert.deepEqual(
    datesForDailyItem({ startDate, endDate, code: "ADM-01", sourceType: "system", targetJson: null, formulaParams: null }).map((date) => date.toISOString().slice(0, 10)),
    ["2026-09-08"],
  );
  assert.deepEqual(
    datesForDailyItem({ startDate, endDate, code: "SUB-01", sourceType: "supervisor", targetJson: { cadence: "weekly" }, formulaParams: null }).map((date) => date.toISOString().slice(0, 10)),
    ["2026-09-06", "2026-09-08"],
  );
  assert.deepEqual(manualRatingOptions({ manual_rating_options: [{ code: "BAIK", label: "Baik", score: "90" }] }), [{ code: "BAIK", label: "Baik", score: 90 }]);
  assert.equal(ratingCodeFromJson({ rating_code: "BAIK" }), "BAIK");
  assert.deepEqual(fulfilledCriterionIds([{ criterion_id: 1, is_fulfilled: true }, { criterion_id: 2, is_fulfilled: false }]), ["1"]);
  assert.equal(aggregateValues([80, 100], true), 90);
});
