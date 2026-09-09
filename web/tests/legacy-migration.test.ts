import assert from "node:assert/strict";
import test from "node:test";
import { coerceForTarget, targetColumnName } from "../scripts/migrate-legacy.ts";

test("migrator memetakan snake_case, actor ID, boolean, dan JSON", () => {
  const names = new Set(["employeeKpiId", "recordedById"]);
  assert.equal(targetColumnName("employee_kpi_id", names), "employeeKpiId");
  assert.equal(targetColumnName("recorded_by", names), "recordedById");
  assert.equal(coerceForTarget("0", { name: "isActive", dataType: "boolean", udtName: "bool" }), false);
  assert.deepEqual(coerceForTarget('{"total":2}', { name: "actualJson", dataType: "jsonb", udtName: "jsonb" }), { total: 2 });
});
