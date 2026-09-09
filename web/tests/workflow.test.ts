import assert from "node:assert/strict";
import test from "node:test";
import {
  assertExpectedVersion,
  assertKpiTransition,
  assertPeriodTransition,
} from "../src/modules/kpi/workflow.ts";

test("workflow menerima transisi resmi dan menolak lompatan", () => {
  assert.doesNotThrow(() => assertPeriodTransition("DRAFT", "READY"));
  assert.throws(() => assertPeriodTransition("DRAFT", "PUBLISHED"));
  assert.doesNotThrow(() => assertKpiTransition("PENDING_APPROVAL", "APPROVED"));
  assert.throws(() => assertKpiTransition("APPROVED", "UNDER_REVIEW"));
});

test("optimistic lock menolak versi lama", () => {
  assert.doesNotThrow(() => assertExpectedVersion(4, 4));
  assert.throws(() => assertExpectedVersion(4, 3));
});
