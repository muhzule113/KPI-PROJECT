import assert from "node:assert/strict";
import test from "node:test";
import {
  canEnterDailySheet,
  canFinalizeMonthly,
  canViewDailyDetail,
  canReviewDailySheet,
  canViewMonthly,
  type AccessProfile,
  type KpiSubject,
} from "../src/modules/access/policy.ts";

const supervisor: AccessProfile = { userId: "u-spv", role: "SUPERVISOR", employeeId: "e-spv", branchId: "b-1", active: true };
const manager: AccessProfile = { userId: "u-mgr", role: "MANAGER", employeeId: "e-mgr", branchId: "b-1", active: true };
const employee: AccessProfile = { userId: "u-staff", role: "EMPLOYEE", employeeId: "e-staff", branchId: "b-1", active: true };
const subject: KpiSubject = { employeeId: "e-staff", role: "EMPLOYEE", branchId: "b-1", supervisorId: "e-spv", managerId: "e-mgr", status: "IN_PROGRESS" };

test("Supervisor hanya mengisi staf yang ditugaskan dalam cabang yang sama", () => {
  assert.equal(canEnterDailySheet(supervisor, subject), true);
  assert.equal(canEnterDailySheet({ ...supervisor, employeeId: "e-other" }, subject), false);
  assert.equal(canEnterDailySheet({ ...supervisor, branchId: "b-2" }, subject), false);
  assert.equal(canEnterDailySheet(supervisor, { ...subject, employeeId: "e-spv" }), false);
});

test("Manager mengisi KPI Supervisor dan mereview staf yang ditugaskan", () => {
  assert.equal(canEnterDailySheet(manager, { ...subject, employeeId: "e-spv", role: "SUPERVISOR" }), true);
  assert.equal(canEnterDailySheet(manager, subject), false);
  assert.equal(canReviewDailySheet(manager, subject), true);
  assert.equal(canReviewDailySheet(supervisor, subject), false);
});

test("akun tidak aktif tidak mendapat akses", () => {
  assert.equal(canEnterDailySheet({ ...supervisor, active: false }, subject), false);
  assert.equal(canReviewDailySheet({ ...manager, active: false }, subject), false);
});

test("Manager hanya memfinalkan KPI sesuai assignment", () => {
  assert.equal(canFinalizeMonthly(manager, subject), true);
  assert.equal(canFinalizeMonthly({ ...manager, employeeId: "e-manager-lain" }, subject), false);
});

test("Pegawai hanya melihat hasil miliknya yang sudah final", () => {
  assert.equal(canViewMonthly(employee, subject), false);
  assert.equal(canViewMonthly(employee, { ...subject, status: "FINALIZED" }), true);
  assert.equal(canViewMonthly({ ...employee, employeeId: "e-lain" }, { ...subject, status: "FINALIZED" }), false);
});

test("rincian harian Pegawai terbuka setelah dikirim tanpa membuka draf", () => {
  assert.equal(canViewDailyDetail(employee, subject, "PENDING"), false);
  assert.equal(canViewDailyDetail(employee, subject, "DRAFT"), false);
  assert.equal(canViewDailyDetail(employee, subject, "SUBMITTED"), true);
  assert.equal(canViewDailyDetail(employee, subject, "REVISION_REQUIRED"), true);
  assert.equal(canViewDailyDetail(employee, subject, "APPROVED"), true);
  assert.equal(canViewDailyDetail({ ...employee, employeeId: "e-lain" }, subject, "APPROVED"), false);
  assert.equal(canViewDailyDetail({ ...employee, active: false }, subject, "APPROVED"), false);
});

test("rincian harian penilai mengikuti assignment dan cabang", () => {
  assert.equal(canViewDailyDetail(supervisor, subject, "DRAFT"), true);
  assert.equal(canViewDailyDetail(manager, subject, "SUBMITTED"), true);
  assert.equal(canViewDailyDetail({ ...supervisor, branchId: "b-2" }, subject, "APPROVED"), false);
  assert.equal(canViewDailyDetail({ ...manager, employeeId: "e-manager-lain" }, subject, "APPROVED"), false);
});

test("Super Admin dapat melihat semua hasil tetapi tidak mengisi penilaian", () => {
  const admin: AccessProfile = { userId: "u-admin", role: "ADMIN", employeeId: null, branchId: null, active: true };
  assert.equal(canViewMonthly(admin, subject), true);
  assert.equal(canEnterDailySheet(admin, subject), false);
});
