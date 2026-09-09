import assert from "node:assert/strict";
import test from "node:test";
import { stockDifference } from "../src/modules/inventory/stock-opname.ts";

test("opname menghitung selisih dan menolak snapshot stok lama", () => {
  assert.equal(stockDifference(10, 10, 8), -2);
  assert.equal(stockDifference(10, 10, 12), 2);
  assert.throws(() => stockDifference(10, 11, 8), /berubah sejak snapshot/);
});
