import type { AccessProfile } from "@/modules/access/capabilities";

export function kpiScopeFor(user: AccessProfile) {
  if (["super_admin", "kpi_admin", "auditor"].includes(user.role)) return {};
  if (user.role === "owner_manager") return { managerIdSnapshot: user.employee?.id ?? "__no_access__" };
  if (user.role === "supervisor") return { supervisorIdSnapshot: user.employee?.id ?? "__no_access__" };
  return { employeeId: user.employee?.id ?? "__no_access__" };
}

export function branchScopeFor(user: AccessProfile) {
  return ["super_admin", "kpi_admin", "auditor"].includes(user.role)
    ? {}
    : { branchId: user.employee?.branchId ?? "__no_access__" };
}

export function ticketScopeFor(user: AccessProfile) {
  if (user.role === "super_admin") return {};
  const branchId = user.employee?.branchId ?? "__no_access__";
  if (user.employee?.position.code === "POS-TEK") return { branchId, AND: [{ OR: [{ technicianEmployeeId: null, status: "INTAKE" as const }, { technicianEmployeeId: user.employee.id }] }] };
  if (user.role === "supervisor") return { branchId, AND: [{ OR: [{ intakeByEmployeeId: user.employee?.id }, { intakeBy: { supervisorId: user.employee?.id } }, { technician: { supervisorId: user.employee?.id } }, { technicianEmployeeId: null, status: "INTAKE" as const }] }] };
  return { branchId };
}

export function canAccessTicket(user: AccessProfile, ticket: { branchId: string; status?: string; intakeByEmployeeId?: string | null; technicianEmployeeId: string | null; intakeBy?: { supervisorId: string | null } | null; technician?: { supervisorId: string | null } | null }) {
  if (user.role === "super_admin") return true;
  if (!user.employee || ticket.branchId !== user.employee.branchId) return false;
  if (user.employee.position.code === "POS-TEK") return ticket.technicianEmployeeId === user.employee.id || ticket.technicianEmployeeId === null && ticket.status === "INTAKE";
  if (user.role === "supervisor") return ticket.intakeByEmployeeId === user.employee.id || ticket.intakeBy?.supervisorId === user.employee.id || ticket.technician?.supervisorId === user.employee.id || ticket.technicianEmployeeId === null && ticket.status === "INTAKE";
  return true;
}

export function canSeeScore(user: AccessProfile, periodStatus: string) {
  return user.role !== "employee" || ["PUBLISHED", "LOCKED"].includes(periodStatus);
}

export function canSeeOwnScore(periodStatus: string) {
  return ["PUBLISHED", "LOCKED"].includes(periodStatus);
}
