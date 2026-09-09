import type { ReactNode } from "react";
import { WorkspaceShell } from "@/components/workspace-nav";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/modules/access/current-user";

export default async function WorkspaceLayout({ children }: { children: ReactNode }) {
  const user = await requireUser();
  const unread = await prisma.notification.count({ where: { userId: user.userId, readAt: null } });

  return <WorkspaceShell
    role={user.role}
    unread={unread}
    userName={user.name}
    branchName={user.employee?.branchName ?? "Lintas cabang"}
  >{children}</WorkspaceShell>;
}
