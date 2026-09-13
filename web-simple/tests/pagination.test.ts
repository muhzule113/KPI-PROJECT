import assert from "node:assert/strict";
import test from "node:test";
import { getPageCount, PAGINATION_PAGE_SIZE, parsePage } from "../src/lib/pagination.ts";

test("pagination menormalkan nomor halaman dan menghitung total halaman", () => {
  assert.equal(parsePage("2"), 2);
  assert.equal(parsePage("0"), 1);
  assert.equal(parsePage("abc"), 1);
  assert.equal(PAGINATION_PAGE_SIZE, 10);
  assert.equal(getPageCount(0), 1);
  assert.equal(getPageCount(11), 2);
});
