import assert from "node:assert/strict";
import test from "node:test";
import { formatKpiTarget } from "../src/modules/kpi/target-label.ts";

test("label target memakai operator dan batas gagal sesuai formula", () => {
  assert.equal(formatKpiTarget({ direction: "HIGHER", target: 95, failureLimit: null, unit: "%" }), "≥ 95%");
  assert.equal(formatKpiTarget({ direction: "HIGHER", target: 80, failureLimit: null, unit: "unit" }), "≥ 80 unit");
  assert.equal(formatKpiTarget({ direction: "LOWER", target: 3, failureLimit: 6, unit: "%" }), "≤ 3% · gagal pada 6%");
  assert.equal(formatKpiTarget({ direction: "ZERO_TOLERANCE", target: 0, failureLimit: null, unit: "Rp" }), "harus Rp0");
});
