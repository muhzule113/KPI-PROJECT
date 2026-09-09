import assert from "node:assert/strict";
import test from "node:test";
import { makassarDate, makassarDayBoundsUtc } from "../src/modules/jobs/time.ts";

test("jadwal memakai tanggal Asia/Makassar dan batas UTC yang tepat", () => {
  const now = new Date("2026-09-08T17:30:00.000Z");
  assert.equal(makassarDate(now).toISOString(), "2026-09-09T00:00:00.000Z");
  assert.deepEqual(makassarDayBoundsUtc(now, 1), {
    start: new Date("2026-09-09T16:00:00.000Z"),
    end: new Date("2026-09-10T16:00:00.000Z"),
  });
});
