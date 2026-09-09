import assert from "node:assert/strict";
import test from "node:test";
import { isValidUsername, normalizeUsername, USERNAME_INPUT_PATTERN } from "../src/lib/username.ts";

test("username dinormalisasi tanpa mengubah pemisah yang diizinkan", () => {
  assert.equal(normalizeUsername("  Admin.Cadangan-01_UT  "), "admin.cadangan-01_ut");
});

test("username menerima 3–50 karakter aman dan menolak bentuk lain", () => {
  assert.doesNotThrow(() => new RegExp(USERNAME_INPUT_PATTERN, "v"));
  assert.equal(isValidUsername("ica"), true);
  assert.equal(isValidUsername("admin.cadangan-01_ut"), true);
  assert.equal(isValidUsername(`a${"b".repeat(48)}z`), true);
  assert.equal(isValidUsername("ab"), false);
  assert.equal(isValidUsername(`a${"b".repeat(49)}z`), false);
  assert.equal(isValidUsername("-admin"), false);
  assert.equal(isValidUsername("admin_"), false);
  assert.equal(isValidUsername("andi syukur"), false);
  assert.equal(isValidUsername("andi@example.com"), false);
});
