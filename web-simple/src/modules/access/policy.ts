export type UserRole = "ADMIN" | "MANAGER" | "SUPERVISOR" | "EMPLOYEE";

export type AccessProfile = {
  userId: string;
  role: UserRole;
  employeeId: string | null;
  branchId: string | null;
  active: boolean;
};

export type KpiSubject = {
  employeeId: string;
  role: "SUPERVISOR" | "EMPLOYEE";
  branchId: string;
  supervisorId: string | null;
  managerId: string;
  status: "IN_PROGRESS" | "READY" | "FINALIZED" | "REOPENED";
};

const sameBranch = (actor: AccessProfile, subject: KpiSubject) => actor.branchId !== null && actor.branchId === subject.branchId;
const notSelf = (actor: AccessProfile, subject: KpiSubject) => actor.employeeId !== null && actor.employeeId !== subject.employeeId;

export function canEnterDailySheet(actor: AccessProfile, subject: KpiSubject) {
  if (!actor.active || !sameBranch(actor, subject) || !notSelf(actor, subject)) return false;
  if (subject.role === "SUPERVISOR") return actor.role === "MANAGER" && subject.managerId === actor.employeeId;
  return actor.role === "SUPERVISOR" && subject.supervisorId === actor.employeeId;
}

export function canReviewDailySheet(actor: AccessProfile, subject: KpiSubject) {
  return Boolean(
    actor.active &&
    actor.role === "MANAGER" &&
    subject.role === "EMPLOYEE" &&
    sameBranch(actor, subject) &&
    notSelf(actor, subject) &&
    subject.managerId === actor.employeeId,
  );
}

export function canFinalizeMonthly(actor: AccessProfile, subject: KpiSubject) {
  return Boolean(
    actor.active &&
    actor.role === "MANAGER" &&
    sameBranch(actor, subject) &&
    notSelf(actor, subject) &&
    subject.managerId === actor.employeeId,
  );
}

export function canViewMonthly(actor: AccessProfile, subject: KpiSubject) {
  if (!actor.active) return false;
  if (actor.role === "ADMIN") return true;
  if (!actor.employeeId || !sameBranch(actor, subject)) return false;
  if (actor.role === "EMPLOYEE") return actor.employeeId === subject.employeeId && subject.status === "FINALIZED";
  if (actor.role === "SUPERVISOR") {
    return (actor.employeeId === subject.employeeId && subject.status === "FINALIZED") ||
      (subject.role === "EMPLOYEE" && subject.supervisorId === actor.employeeId);
  }
  return subject.managerId === actor.employeeId;
}
