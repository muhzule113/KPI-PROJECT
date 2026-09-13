import assert from "node:assert/strict";
import test from "node:test";
import { getPageCount, parsePage } from "../src/lib/pagination.ts";

test("pagination menormalkan nomor halaman dan menghitung total halaman", () => {
  assert.equal(parsePage("2"), 2);
  assert.equal(parsePage("0"), 1);
  assert.equal(parsePage("abc"), 1);
  assert.equal(getPageCount(0), 1);
  assert.equal(getPageCount(26), 2);
});
