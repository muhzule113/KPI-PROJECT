import "server-only";

import { headers } from "next/headers";
import { redirect } from "next/navigation";
import { auth } from "@/lib/auth";
import { prisma } from "@/lib/prisma";
import { accessError, type AccessProfile } from "@/modules/access/capabilities";

export async function currentUser(): Promise<AccessProfile | null> {
  const session = await auth.api.getSession({ headers: await headers() });
  if (!session) return null;

  return prisma.user.findUnique({
    where: { id: session.user.id },
    select: {
      id: true,
      name: true,
      email: true,
      image: true,
      role: true,
      isActive: true,
      employee: {
        select: {
          id: true,
          name: true,
          status: true,
          branchId: true,
          position: { select: { code: true, name: true, isActive: true } },
          branch: { select: { name: true, isActive: true } },
        },
      },
    },
  }) as Promise<AccessProfile | null>;
}

export async function requireUser() {
  const user = await currentUser();
  if (!user) redirect("/login");
  const error = accessError(user);
  if (error) redirect(`/akses-ditolak?reason=${encodeURIComponent(error)}`);
  return user;
}
