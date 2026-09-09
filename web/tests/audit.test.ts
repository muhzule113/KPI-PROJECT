import assert from "node:assert/strict";
import test from "node:test";
import { presentAuditJson } from "../src/modules/audit/presentation.ts";

test("presentasi audit meredaksi password, token, dan secret pada semua tingkat", () => {
  const output = presentAuditJson({ email: "admin@example.com", password: "rahasia", nested: { accessToken: "token-asli", client_secret: "secret-asli" } });
  assert.ok(output?.includes("admin@example.com"));
  assert.equal(output?.includes("rahasia"), false);
  assert.equal(output?.includes("token-asli"), false);
  assert.equal(output?.includes("secret-asli"), false);
  assert.equal(output?.match(/\[disembunyikan\]/g)?.length, 3);
});
