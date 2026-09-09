import assert from "node:assert/strict";
import test from "node:test";
import {
  effectiveDailyValue,
  finalizeReadiness,
  reopenMonthlyResult,
  reviewDailySheet,
  submitDailySheet,
} from "../src/modules/kpi/workflow.ts";

const readinessReason = (result: ReturnType<typeof finalizeReadiness>) => result.ok ? "" : result.reason;

test("Supervisor mengirim lembar staf yang lengkap kepada Manager", () => {
  assert.equal(submitDailySheet({
    actorRole: "SUPERVISOR",
    subjectRole: "EMPLOYEE",
    currentStatus: "DRAFT",
    workStatus: "WORKED",
    valuesComplete: true,
  }), "SUBMITTED");
});

test("hari bekerja tidak dapat dikirim selama nilai masih kosong", () => {
  assert.throws(() => submitDailySheet({
    actorRole: "SUPERVISOR",
    subjectRole: "EMPLOYEE",
    currentStatus: "DRAFT",
    workStatus: "WORKED",
    valuesComplete: false,
  }), /seluruh indikator/i);
});

test("Manager mengisi KPI Supervisor tanpa penilaian diri sendiri", () => {
  assert.equal(submitDailySheet({
    actorRole: "MANAGER",
    subjectRole: "SUPERVISOR",
    currentStatus: "PENDING",
    workStatus: "WORKED",
    valuesComplete: true,
  }), "APPROVED");
  assert.throws(() => submitDailySheet({
    actorRole: "EMPLOYEE",
    subjectRole: "EMPLOYEE",
    currentStatus: "PENDING",
    workStatus: "WORKED",
    valuesComplete: true,
  }), /berwenang/i);
});

test("Manager dapat menyetujui, mengoreksi, atau mengembalikan lembar staf", () => {
  assert.equal(reviewDailySheet({ currentStatus: "SUBMITTED", decision: "APPROVE", changed: false }), "APPROVED");
  assert.equal(reviewDailySheet({ currentStatus: "SUBMITTED", decision: "CORRECT", changed: true, reason: "Angka disesuaikan dengan catatan toko." }), "APPROVED");
  assert.equal(reviewDailySheet({ currentStatus: "SUBMITTED", decision: "RETURN", changed: false, reason: "Lengkapi catatan kejadian." }), "REVISION_REQUIRED");
});

test("koreksi dan pengembalian wajib mempunyai alasan", () => {
  assert.throws(() => reviewDailySheet({ currentStatus: "SUBMITTED", decision: "CORRECT", changed: true }), /alasan/i);
  assert.throws(() => reviewDailySheet({ currentStatus: "SUBMITTED", decision: "RETURN", changed: false }), /alasan/i);
  assert.throws(() => reviewDailySheet({ currentStatus: "APPROVED", decision: "APPROVE", changed: false }), /menunggu review/i);
});

test("hasil yang dibuka ulang dapat dikoreksi Manager dengan alasan", () => {
  assert.equal(reviewDailySheet({
    currentStatus: "APPROVED",
    decision: "CORRECT",
    changed: true,
    reason: "Perbaikan setelah hasil dibuka Super Admin.",
    allowApproved: true,
  }), "APPROVED");
  assert.throws(() => reviewDailySheet({ currentStatus: "APPROVED", decision: "CORRECT", changed: true, allowApproved: true }), /alasan/i);
});

test("nilai efektif memakai koreksi Manager tanpa menghapus nilai awal", () => {
  assert.equal(effectiveDailyValue("12", null), "12");
  assert.equal(effectiveDailyValue("12", "10"), "10");
});

test("finalisasi hanya tersedia setelah akhir periode dan seluruh hari disetujui", () => {
  assert.deepEqual(finalizeReadiness({
    today: "2026-10-01",
    periodEndDate: "2026-09-30",
    sheetStatuses: ["APPROVED", "APPROVED"],
    workedDays: 1,
    calculationStatuses: ["CALCULATED", "CALCULATED"],
  }), { ok: true });
  assert.match(readinessReason(finalizeReadiness({
    today: "2026-09-30",
    periodEndDate: "2026-09-30",
    sheetStatuses: ["APPROVED"],
    workedDays: 1,
    calculationStatuses: ["CALCULATED"],
  })), /berakhir/i);
  assert.match(readinessReason(finalizeReadiness({
    today: "2026-10-01",
    periodEndDate: "2026-09-30",
    sheetStatuses: ["APPROVED", "SUBMITTED"],
    workedDays: 1,
    calculationStatuses: ["CALCULATED"],
  })), /belum disetujui/i);
});

test("bulan tanpa hari bekerja hanya dapat difinalkan dengan alasan", () => {
  const base = {
    today: "2026-10-01",
    periodEndDate: "2026-09-30",
    sheetStatuses: ["APPROVED"] as const,
    workedDays: 0,
    calculationStatuses: ["PENDING"] as const,
  };
  assert.match(readinessReason(finalizeReadiness(base)), /alasan/i);
  assert.deepEqual(finalizeReadiness({ ...base, noScoreReason: "Cuti penuh selama periode." }), { ok: true, withoutScore: true });
});

test("hanya Super Admin dapat membuka hasil final dengan alasan", () => {
  assert.equal(reopenMonthlyResult({ actorRole: "ADMIN", currentStatus: "FINALIZED", reason: "Koreksi salah input." }), "REOPENED");
  assert.throws(() => reopenMonthlyResult({ actorRole: "MANAGER", currentStatus: "FINALIZED", reason: "Koreksi." }), /Super Admin/i);
  assert.throws(() => reopenMonthlyResult({ actorRole: "ADMIN", currentStatus: "FINALIZED" }), /alasan/i);
});
