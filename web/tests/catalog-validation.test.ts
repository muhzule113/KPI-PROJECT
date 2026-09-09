import assert from "node:assert/strict";
import test from "node:test";
import { validateRatingBands } from "../src/modules/kpi/catalog-validation.ts";

test("skala predikat wajib lima pilihan tanpa gap atau tumpang tindih", () => {
  const valid = [
    { code: "POOR", minScore: 0, maxScore: 59.99, manualScore: 50 },
    { code: "FAIR", minScore: 60, maxScore: 69.99, manualScore: 65 },
    { code: "GOOD", minScore: 70, maxScore: 79.99, manualScore: 75 },
    { code: "VERY_GOOD", minScore: 80, maxScore: 89.99, manualScore: 85 },
    { code: "STAR", minScore: 90, maxScore: 100, manualScore: 95 },
  ];
  assert.deepEqual(validateRatingBands(valid), []);
  assert.match(validateRatingBands(valid.map((band, index) => index === 1 ? { ...band, minScore: 61 } : band)).join(" "), /gap/i);
});
