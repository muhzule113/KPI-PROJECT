import { AppShell } from "@/components/app-shell";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/modules/access/current-user";

export default async function WorkspaceLayout({ children }: { children: React.ReactNode }) {
  const user = await requireUser();
  const unreadNotifications = await prisma.systemNotification.count({ where: { userId: user.id, isRead: false } });
  return <AppShell user={user} unreadNotifications={unreadNotifications}>{children}</AppShell>;
}
