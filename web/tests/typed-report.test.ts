import assert from "node:assert/strict";
import test from "node:test";
import { parseTypedReportFilename } from "../src/modules/reports/typed-report.ts";

test("format laporan bertipe hanya menerima kombinasi yang ditentukan PRD", () => {
  assert.deepEqual(parseTypedReportFilename("period_summary.xlsx"), { type: "period_summary", format: "xlsx" });
  assert.deepEqual(parseTypedReportFilename("individual.pdf"), { type: "individual", format: "pdf" });
  assert.equal(parseTypedReportFilename("individual.xlsx"), null);
  assert.equal(parseTypedReportFilename("unknown.pdf"), null);
});
