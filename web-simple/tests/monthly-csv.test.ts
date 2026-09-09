import assert from "node:assert/strict";
import test from "node:test";
import { monthlyCsv } from "../src/modules/reporting/monthly-csv.ts";

test("ekspor CSV mengutip data dan menetralkan formula spreadsheet", () => {
  const csv = monthlyCsv([{
    period: "September 2026",
    employeeNumber: "=1+1",
    employeeName: "Ani, \"Pelayan\"",
    branch: "Pusat",
    position: "Pelayan",
    status: "FINALIZED",
    finalScore: "95.25",
    rating: "Sangat Baik",
    noScoreReason: "",
    finalizedAt: "2026-10-01T00:00:00.000Z",
  }]);

  assert.match(csv, /"'=1\+1"/);
  assert.match(csv, /"Ani, ""Pelayan"""/);
  assert.equal(csv.split("\r\n").length, 2);
});
