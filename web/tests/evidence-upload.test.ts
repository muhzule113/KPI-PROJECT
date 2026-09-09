import assert from "node:assert/strict";
import test from "node:test";
import { createEvidenceDownloadToken, validateEvidenceFile, verifyEvidenceDownloadToken } from "../src/modules/files/evidence-upload.ts";

test("evidence memverifikasi ekstensi, MIME, ukuran, dan signature", () => {
  assert.equal(validateEvidenceFile(Buffer.from("%PDF-1.7"), "bukti.pdf", "application/pdf").mimeType, "application/pdf");
  assert.throws(() => validateEvidenceFile(Buffer.from("bukan pdf"), "bukti.pdf", "application/pdf"), /tidak sesuai/i);
  assert.throws(() => validateEvidenceFile(Buffer.from("%PDF-1.7"), "bukti.pdf", "image/png"), /tipe file/i);
  const secret = "test-secret-with-at-least-thirty-two-characters";
  const now = new Date("2026-09-09T00:00:00Z");
  const token = createEvidenceDownloadToken("ticket-1", 0, new Date("2026-09-09T00:05:00Z"), secret);
  assert.equal(verifyEvidenceDownloadToken(token, "ticket-1", 0, now, secret), true);
  assert.equal(verifyEvidenceDownloadToken(token, "ticket-1", 1, now, secret), false);
  assert.equal(verifyEvidenceDownloadToken(token, "ticket-1", 0, new Date("2026-09-09T00:06:00Z"), secret), false);
});
