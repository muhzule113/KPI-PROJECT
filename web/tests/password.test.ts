import assert from "node:assert/strict";
import test from "node:test";
import bcrypt from "bcryptjs";
import { hashApplicationPassword, verifyApplicationPassword } from "../src/lib/password.ts";

test("login menerima hash Laravel lama dan hash Better Auth baru", async () => {
  const password = "Rahasia-yang-kuat-123";
  const laravelHash = bcrypt.hashSync(password, 10).replace(/^\$2b\$/, "$2y$");
  const currentHash = await hashApplicationPassword(password);

  assert.equal(await verifyApplicationPassword({ hash: laravelHash, password }), true);
  assert.equal(await verifyApplicationPassword({ hash: currentHash, password }), true);
  assert.equal(await verifyApplicationPassword({ hash: laravelHash, password: "salah" }), false);
});
