import assert from "node:assert/strict";
import test from "node:test";
import { validateTemplateConfiguration } from "../src/modules/kpi/period-readiness.ts";

test("readiness menolak bobot dan failure limit template yang tidak valid", () => {
  const issues = validateTemplateConfiguration("Teknisi", [{
    name: "Retur",
    weight: 90,
    formula: "lower_is_better",
    target: 3,
    targetJson: { failure_limit: 3 },
    formulaParams: { cadence: "period" },
    definitionActive: true,
    rubricCriteria: 0,
  }]);
  assert.equal(issues.some((issue) => issue.includes("100%")), true);
  assert.equal(issues.some((issue) => issue.includes("Failure limit")), true);
});
