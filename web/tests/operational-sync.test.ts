import assert from "node:assert/strict";
import test from "node:test";
import { calculateAttendanceRate, calculateWorkLogMetrics, datesThrough, sameSystemDetails } from "../src/modules/kpi/operational-metrics.ts";

test("fakta operasional dihitung tanpa memasukkan izin ke denominator", () => {
  assert.equal(calculateAttendanceRate([
    { attendanceDate: new Date("2026-09-01"), status: "present" },
    { attendanceDate: new Date("2026-09-02"), status: "late" },
    { attendanceDate: new Date("2026-09-03"), status: "absent" },
    { attendanceDate: new Date("2026-09-04"), status: "permission" },
  ]), 66.67);
  assert.deepEqual(calculateWorkLogMetrics([{
    recordsInput: 100,
    recordsCorrected: 2,
    documentsEligible: 50,
    documentsComplete: 49,
    reconciliationsTotal: 20,
    reconciliationsSuccess: 19,
  }]), { "ADM-01": 98, "ADM-03": 98, "ADM-04": 95 });
  assert.equal(sameSystemDetails({ source: "attendance", total: 2 }, { total: 2, source: "attendance" }), true);
  assert.equal(sameSystemDetails({ source: "attendance", total: 1 }, { source: "attendance", total: 2 }), false);
  assert.deepEqual(datesThrough(new Date("2026-09-01T00:00:00Z"), new Date("2026-09-03T00:00:00Z")).map((date) => date.toISOString().slice(0, 10)), ["2026-09-01", "2026-09-02", "2026-09-03"]);
});
