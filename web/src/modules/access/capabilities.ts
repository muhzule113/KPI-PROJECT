export type Role =
  | "super_admin"
  | "kpi_admin"
  | "auditor"
  | "owner_manager"
  | "supervisor"
  | "employee";

export type AccessProfile = {
  id: string;
  name: string;
  email: string;
  image: string | null;
  role: Role;
  isActive: boolean;
  employee: null | {
    id: string;
    name: string;
    status: string;
    branchId: string;
    position: { code: string; name: string; isActive: boolean };
    branch: { name: string; isActive: boolean };
  };
};

const ADMIN_ROLES: Role[] = ["super_admin", "kpi_admin", "auditor"];

const ROLE_CAPABILITIES: Partial<Record<Role, readonly string[]>> = {
  super_admin: ["accounts.manage", "organization.manage", "kpi.catalog.configure", "audit.view"],
  kpi_admin: [
    "kpi.assignments.manage",
    "kpi.period.manage",
    "kpi.monitor",
    "kpi.sync",
    "audit.sync.view",
    "imports.configure",
    "reports.view",
    "reports.export",
  ],
  auditor: ["reports.view", "reports.export", "kpi.monitor", "audit.view"],
  supervisor: [
    "kpi.supervisor.daily",
    "kpi.supervisor.review",
    "attendance.team.manage",
    "coaching.manage",
    "complaints.validate",
    "feedback.view",
    "feedback.followup.manage",
    "tickets.feedback-link",
    "tickets.supervise",
    "tickets.view",
    "reports.view",
    "reports.export",
    "reports.submit",
    "kpi.correction.request",
  ],
  owner_manager: [
    "kpi.manager.daily",
    "kpi.manager.approval",
    "kpi.correction.manage",
    "kpi.correction.request",
    "attendance.manage",
    "complaints.manage",
    "feedback.view",
    "feedback.followup.manage",
    "tickets.feedback-link",
    "tickets.manage",
    "tickets.view",
    "reports.view",
    "reports.export",
  ],
};

const POSITION_CAPABILITIES: Record<string, readonly string[]> = {
  "POS-CS": [
    "tickets.view",
    "tickets.create",
    "tickets.consent",
    "tickets.deliver",
    "tickets.feedback-link",
    "feedback.view",
    "feedback.followup.manage",
    "complaints.create",
  ],
  "POS-TEK": [
    "tickets.view",
    "tickets.claim",
    "tickets.progress",
    "tickets.complete",
    "tickets.evidence",
    "spareparts.request",
    "spareparts.confirm",
  ],
  "POS-ADM": ["work-logs.manage", "reports.submit"],
  "POS-KSR": ["tickets.view", "tickets.cost", "tickets.payment", "cashier.import"],
  "POS-GUD": [
    "tickets.view",
    "spareparts.manage",
    "spareparts.fulfill",
    "stock-opname.manage",
  ],
};

const BASE_CAPABILITIES = ["dashboard.view", "notifications.view", "profile.view"];

export function accessError(user: AccessProfile): string | null {
  if (!user.isActive) return "Akun Anda tidak aktif. Hubungi Super Admin.";
  if (ADMIN_ROLES.includes(user.role)) return null;

  const employee = user.employee;
  if (!employee?.position || !employee.branch) {
    return "Profil operasional belum lengkap. Hubungi Super Admin.";
  }
  if (employee.status !== "ACTIVE" || !employee.position.isActive || !employee.branch.isActive) {
    return "Profil karyawan, jabatan, atau cabang tidak aktif. Hubungi Super Admin.";
  }

  const validPosition =
    (user.role === "owner_manager" && ["POS-OWN", "POS-EXEC"].includes(employee.position.code)) ||
    (user.role === "supervisor" && employee.position.code === "POS-SPV") ||
    (user.role === "employee" && Object.hasOwn(POSITION_CAPABILITIES, employee.position.code));

  return validPosition ? null : "Peran dan jabatan akun belum sesuai. Hubungi Super Admin.";
}

export function capabilitiesFor(user: AccessProfile): Set<string> {
  if (accessError(user)) return new Set();

  if (user.role === "super_admin") {
    return new Set([
      ...BASE_CAPABILITIES,
      ...Object.values(ROLE_CAPABILITIES).flatMap((items) => items ?? []),
      ...Object.values(POSITION_CAPABILITIES).flat(),
    ]);
  }

  const capabilities = new Set([
    ...BASE_CAPABILITIES,
    ...(ROLE_CAPABILITIES[user.role] ?? []),
    ...(user.employee ? POSITION_CAPABILITIES[user.employee.position.code] ?? [] : []),
  ]);
  if (user.employee && !["POS-OWN", "POS-EXEC"].includes(user.employee.position.code)) {
    capabilities.add("kpi.self.view");
    capabilities.add("kpi.correction.request");
  }
  return capabilities;
}

export function hasCapability(user: AccessProfile, capability: string) {
  return capabilitiesFor(user).has(capability);
}

export function canReviewKpi(
  user: AccessProfile,
  kpi: { employeeId: string; supervisorIdSnapshot: string | null; branchIdSnapshot: string; positionCodeSnapshot: string },
) {
  if (user.role === "super_admin") return kpi.positionCodeSnapshot !== "POS-SPV";
  return Boolean(
    hasCapability(user, "kpi.supervisor.review") &&
      user.employee &&
      kpi.positionCodeSnapshot !== "POS-SPV" &&
      kpi.employeeId !== user.employee.id &&
      kpi.supervisorIdSnapshot === user.employee.id &&
      kpi.branchIdSnapshot === user.employee.branchId,
  );
}

export function canManageKpi(
  user: AccessProfile,
  kpi: { employeeId: string; managerIdSnapshot: string | null; branchIdSnapshot: string },
) {
  if (user.role === "super_admin") return true;
  return Boolean(
    hasCapability(user, "kpi.manager.approval") &&
      user.employee &&
      kpi.employeeId !== user.employee.id &&
      kpi.managerIdSnapshot === user.employee.id &&
      kpi.branchIdSnapshot === user.employee.branchId,
  );
}

export function canRequestKpiCorrection(
  user: AccessProfile,
  kpi: { employeeId: string; supervisorIdSnapshot: string | null; managerIdSnapshot: string | null; branchIdSnapshot: string },
) {
  if (user.role === "super_admin") return true;
  if (!user.employee || !hasCapability(user, "kpi.correction.request") || kpi.branchIdSnapshot !== user.employee.branchId) return false;
  return kpi.employeeId === user.employee.id || (user.role === "supervisor" && kpi.supervisorIdSnapshot === user.employee.id) || (user.role === "owner_manager" && kpi.managerIdSnapshot === user.employee.id);
}

export function canApproveKpiCorrection(
  user: AccessProfile,
  correction: { requestedById: string; employeeKpi: { employeeId: string; managerIdSnapshot: string | null; branchIdSnapshot: string } },
) {
  return correction.requestedById !== user.id && canManageKpi(user, correction.employeeKpi);
}
