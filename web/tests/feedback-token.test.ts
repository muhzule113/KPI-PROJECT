import assert from "node:assert/strict";
import test from "node:test";
import { createFeedbackToken, verifyFeedbackToken } from "../src/modules/tickets/feedback-token.ts";

const secret = "test-secret-with-at-least-thirty-two-characters";
const now = new Date("2026-09-09T00:00:00.000Z");

test("tautan feedback menolak modifikasi dan kedaluwarsa", () => {
  const token = createFeedbackToken("ticket:123", new Date("2026-09-16T00:00:00.000Z"), secret);
  assert.equal(verifyFeedbackToken(token, now, secret), "ticket:123");
  assert.equal(verifyFeedbackToken(token.replace("ticket:123", "ticket:999"), now, secret), null);
  assert.equal(verifyFeedbackToken(token, new Date("2026-09-17T00:00:00.000Z"), secret), null);
});
