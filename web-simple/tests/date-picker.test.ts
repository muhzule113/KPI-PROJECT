import assert from "node:assert/strict";
import test from "node:test";
import { dateFromIso, dateToIso, dateWithinBounds } from "../src/lib/date-picker.ts";

test("tanggal formulir tetap YYYY-MM-DD di zona Makassar", () => {
  assert.equal(dateToIso(dateFromIso("2026-09-09")!), "2026-09-09");
});

test("batas kalender inklusif", () => {
  assert.equal(dateWithinBounds("2026-09-09", "2026-09-09", "2026-09-30"), true);
  assert.equal(dateWithinBounds("2026-10-01", "2026-09-09", "2026-09-30"), false);
});
