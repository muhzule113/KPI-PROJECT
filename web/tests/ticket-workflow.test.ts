import assert from "node:assert/strict";
import test from "node:test";
import { addWeekdays, assertTechnicalEvidenceCanBeAdded, assertTicketTransition, assertTicketTransitionPrerequisites, capabilityForTransition, totalSparepartWaitMinutes } from "../src/modules/tickets/workflow.ts";

test("alur servis membatasi lompatan status dan memetakan kewenangan", () => {
  assert.doesNotThrow(() => assertTicketTransition("INTAKE", "DIAGNOSING"));
  assert.throws(() => assertTicketTransition("INTAKE", "DELIVERED"));
  assert.doesNotThrow(() => assertTechnicalEvidenceCanBeAdded("IN_PROGRESS"));
  assert.throws(() => assertTechnicalEvidenceCanBeAdded("COMPLETED"));
  assert.equal(capabilityForTransition("IN_PROGRESS", "QC_READY"), "tickets.complete");
  assert.equal(capabilityForTransition("COMPLETED", "DELIVERED"), "tickets.deliver");
  const ready = { diagnosisNotes: "Ganti konektor", actionNotes: "Konektor diganti", customerConsentStatus: "approved", hasPendingSparepart: false, hasUnconfirmedSparepart: false };
  assert.doesNotThrow(() => assertTicketTransitionPrerequisites("QC_READY", ready));
  assert.throws(() => assertTicketTransitionPrerequisites("QC_READY", { ...ready, customerConsentStatus: "pending" }));
  assert.throws(() => assertTicketTransitionPrerequisites("DELIVERED", ready));
  assert.equal(addWeekdays(new Date("2026-09-11T10:00:00.000Z"), 1).toISOString(), "2026-09-14T10:00:00.000Z");
  assert.equal(totalSparepartWaitMinutes([
    { requestedAt: new Date("2026-09-01T10:00:00Z"), confirmedAt: new Date("2026-09-01T10:20:00Z") },
    { requestedAt: new Date("2026-09-01T10:10:00Z"), confirmedAt: new Date("2026-09-01T10:30:00Z") },
  ]), 30);
});
