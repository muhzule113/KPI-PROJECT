import assert from "node:assert/strict";
import test from "node:test";
import { actionError } from "../src/lib/action-error.ts";

test("form hanya menampilkan error domain biasa dan menyembunyikan error infrastruktur", () => {
  assert.equal(actionError(new Error("Nilai wajib diisi."), "Gagal."), "Nilai wajib diisi.");
  const systemError = Object.assign(new Error("D:\\secret\\database"), { code: "EIO" });
  assert.equal(actionError(systemError, "Gagal."), "Gagal.");
  class DatabaseError extends Error {}
  assert.equal(actionError(new DatabaseError("query internal"), "Gagal."), "Gagal.");
});
