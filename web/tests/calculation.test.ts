import assert from "node:assert/strict";
import test from "node:test";
import { calculateItem, calculateTotal } from "../src/modules/kpi/calculation.ts";

test("higher is better menghitung, membatasi, dan menolak target nol", () => {
  assert.equal(
    calculateItem({ formulaKey: "higher_is_better", weight: 25, actual: 40, target: 80 }).weightedScore,
    "12.500000",
  );
  assert.equal(
    calculateItem({ formulaKey: "higher_is_better", weight: 25, actual: 100, target: 80 }).achievement,
    "100.000000",
  );
  assert.equal(
    calculateItem({ formulaKey: "higher_is_better", weight: 25, actual: 10, target: 0 }).status,
    "unscorable",
  );
});

test("lower is better memakai failure limit dan pembulatan HALF_UP enam desimal", () => {
  const result = calculateItem({
    formulaKey: "lower_is_better",
    weight: "15.000000",
    actual: "4.999999",
    target: "3.000000",
    targetJson: { failure_limit: "6.000000" },
  });
  assert.equal(result.achievement, "33.333367");
  assert.equal(result.weightedScore, "5.000005");
});

test("zero tolerance memakai nilai absolut dan tidak menebak parameter", () => {
  assert.equal(
    calculateItem({
      formulaKey: "zero_tolerance",
      weight: 25,
      actual: -125000,
      targetJson: { full_score_limit: 50000, failure_limit: 200000 },
    }).achievement,
    "50.000000",
  );
  assert.equal(
    calculateItem({ formulaKey: "zero_tolerance", weight: 25, actual: 1 }).status,
    "unscorable",
  );
});

test("total tetap kosong bila satu item belum dapat dihitung", () => {
  const calculated = calculateItem({ formulaKey: "rubric", weight: 10, actual: 85 });
  const pending = calculateItem({ formulaKey: "rubric", weight: 10, actual: null });
  assert.equal(calculateTotal([calculated, pending]), null);
  assert.equal(calculateTotal([calculated]), "8.50");
});
