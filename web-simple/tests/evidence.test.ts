import assert from "node:assert/strict";
import test from "node:test";
import { canChangeEvidence, validateEvidenceFile } from "../src/modules/files/evidence.ts";

const png = Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a, 0x00]);

test("evidence menerima PNG dengan ekstensi, MIME, dan signature yang sesuai", () => {
  assert.deepEqual(validateEvidenceFile(png, "bukti.png", "image/png"), { extension: ".png", mimeType: "image/png" });
});

test("evidence menolak tipe, isi, ukuran, dan nama yang tidak sesuai", () => {
  assert.throws(() => validateEvidenceFile(png, "bukti.png", "application/pdf"), /MIME/i);
  assert.throws(() => validateEvidenceFile(Buffer.from("bukan pdf"), "bukti.pdf", "application/pdf"), /isi file/i);
  assert.throws(() => validateEvidenceFile(Buffer.alloc(0), "bukti.png", "image/png"), /ukuran/i);
  assert.throws(() => validateEvidenceFile(png, "bukti.exe", "application/octet-stream"), /JPG/i);
});

test("evidence terkunci setelah lembar dikirim atau hasil difinalkan", () => {
  assert.equal(canChangeEvidence("DRAFT", "IN_PROGRESS"), true);
  assert.equal(canChangeEvidence("SUBMITTED", "IN_PROGRESS"), false);
  assert.equal(canChangeEvidence("APPROVED", "REOPENED"), false);
  assert.equal(canChangeEvidence("DRAFT", "FINALIZED"), false);
});
