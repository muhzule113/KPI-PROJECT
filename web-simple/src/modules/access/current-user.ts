import "server-only";

import { headers } from "next/headers";
import { redirect } from "next/navigation";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import type { AccessProfile, UserRole } from "@/modules/access/policy";

export type CurrentUser = AccessProfile & {
  name: string;
  username: string;
  employee: null | {
    id: string;
    name: string;
    employeeNumber: string;
    branchId: string;
    branchName: string;
    positionName: string;
  };
};

export async function currentUser(): Promise<CurrentUser | null> {
  const session = await auth.api.getSession({ headers: await headers() });
  if (!session) return null;
  const user = await prisma.user.findUnique({
    where: { id: session.user.id },
    include: { employee: { include: { branch: true, position: true } } },
  });
  if (!user) return null;
  return {
    userId: user.id,
    name: user.name,
    username: user.username,
    role: user.role as UserRole,
    active: user.isActive,
    employeeId: user.employee?.id ?? null,
    branchId: user.employee?.branchId ?? null,
    employee: user.employee ? {
      id: user.employee.id,
      name: user.employee.name,
      employeeNumber: user.employee.employeeNumber,
      branchId: user.employee.branchId,
      branchName: user.employee.branch.name,
      positionName: user.employee.position.name,
    } : null,
  };
}

export async function requireUser() {
  const user = await currentUser();
  if (!user) redirect("/login");
  if (!user.active) redirect("/akses-ditolak?reason=Akun tidak aktif");
  if (user.role !== "ADMIN" && !user.employee) redirect("/akses-ditolak?reason=Profil pegawai belum lengkap");
  return user;
}

export async function requireRole(...roles: UserRole[]) {
  const user = await requireUser();
  if (!roles.includes(user.role)) redirect("/akses-ditolak?reason=Anda tidak memiliki akses");
  return user;
}
