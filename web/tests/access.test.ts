import assert from "node:assert/strict";
import test from "node:test";
import { accessError, canApproveKpiCorrection, canManageKpi, canReviewKpi, capabilitiesFor, type AccessProfile } from "../src/modules/access/capabilities.ts";
import { canAccessTicket, canSeeOwnScore } from "../src/modules/access/scope.ts";

const supervisor: AccessProfile = {
  id: "user-spv",
  name: "Supervisor",
  email: "spv@example.com",
  image: null,
  role: "supervisor",
  isActive: true,
  employee: { id: "spv", name: "Supervisor", status: "ACTIVE", branchId: "branch-a", position: { code: "POS-SPV", name: "Supervisor", isActive: true }, branch: { name: "Cabang A", isActive: true } },
};

test("capability dan scope keputusan menolak lintas cabang serta self-review", () => {
  assert.equal(accessError(supervisor), null);
  assert.equal(capabilitiesFor(supervisor).has("kpi.supervisor.review"), true);
  assert.equal(canReviewKpi(supervisor, { employeeId: "staff", supervisorIdSnapshot: "spv", branchIdSnapshot: "branch-a", positionCodeSnapshot: "POS-CS" }), true);
  assert.equal(canReviewKpi(supervisor, { employeeId: "spv", supervisorIdSnapshot: "spv", branchIdSnapshot: "branch-a", positionCodeSnapshot: "POS-SPV" }), false);
  assert.equal(canReviewKpi(supervisor, { employeeId: "staff", supervisorIdSnapshot: "spv", branchIdSnapshot: "branch-b", positionCodeSnapshot: "POS-CS" }), false);
  assert.equal(canManageKpi(supervisor, { employeeId: "staff", managerIdSnapshot: "spv", branchIdSnapshot: "branch-a" }), false);
});

test("nilai KPI pribadi tersembunyi sampai periode dipublikasikan", () => {
  assert.equal(canSeeOwnScore("WAITING_APPROVAL"), false);
  assert.equal(canSeeOwnScore("PUBLISHED"), true);
  assert.equal(canSeeOwnScore("LOCKED"), true);
});

test("teknisi hanya dapat membuka tiket kosong atau miliknya", () => {
  const technician: AccessProfile = { ...supervisor, role: "employee", employee: { ...supervisor.employee!, id: "tek-1", branchId: "b1", position: { code: "POS-TEK", name: "Teknisi", isActive: true } } };
  assert.equal(canAccessTicket(technician, { branchId: "b1", status: "INTAKE", technicianEmployeeId: null }), true);
  assert.equal(canAccessTicket(technician, { branchId: "b1", status: "DIAGNOSING", technicianEmployeeId: technician.employee!.id }), true);
  assert.equal(canAccessTicket(technician, { branchId: "b1", status: "DIAGNOSING", technicianEmployeeId: null }), false);
  assert.equal(canAccessTicket(technician, { branchId: "b1", status: "DIAGNOSING", technicianEmployeeId: "teknisi-lain" }), false);
});

test("supervisor hanya melihat tiket timnya atau antrean belum ditugaskan", () => {
  assert.equal(canAccessTicket(supervisor, { branchId: "branch-a", status: "DIAGNOSING", technicianEmployeeId: "tek", technician: { supervisorId: "spv" } }), true);
  assert.equal(canAccessTicket(supervisor, { branchId: "branch-a", status: "DIAGNOSING", technicianEmployeeId: "tek-lain", technician: { supervisorId: "spv-lain" } }), false);
  assert.equal(canAccessTicket(supervisor, { branchId: "branch-a", status: "INTAKE", technicianEmployeeId: null }), true);
});

test("koreksi KPI membutuhkan manager lain sebagai pihak kedua", () => {
  const manager: AccessProfile = { ...supervisor, id: "manager-user", role: "owner_manager", employee: { ...supervisor.employee!, id: "manager", position: { code: "POS-OWN", name: "Manager", isActive: true } } };
  const correction = { requestedById: "requester", employeeKpi: { employeeId: "staff", managerIdSnapshot: "manager", branchIdSnapshot: "branch-a" } };
  assert.equal(canApproveKpiCorrection(manager, correction), true);
  assert.equal(canApproveKpiCorrection({ ...manager, id: "requester" }, correction), false);
  assert.equal(canApproveKpiCorrection(manager, { ...correction, employeeKpi: { ...correction.employeeKpi, branchIdSnapshot: "branch-b" } }), false);
});
