import type { Prisma } from "@/generated/prisma/client";
import type { AccessProfile } from "@/modules/access/policy";

export function monthlyKpiScope(actor: AccessProfile): Prisma.MonthlyKpiWhereInput {
  if (actor.role === "ADMIN") return {};
  if (!actor.employeeId) return { id: "__none__" };
  if (actor.role === "MANAGER") return { managerIdSnapshot: actor.employeeId };
  if (actor.role === "SUPERVISOR") return { OR: [{ employeeId: actor.employeeId, status: "FINALIZED" }, { supervisorIdSnapshot: actor.employeeId, subjectRoleSnapshot: "EMPLOYEE" }] };
  return { employeeId: actor.employeeId, status: "FINALIZED" };
}
