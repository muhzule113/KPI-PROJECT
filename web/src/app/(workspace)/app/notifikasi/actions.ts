"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/modules/access/current-user";

const schema = z.object({ notificationId: z.string().min(1) });

function refreshNotifications() {
  revalidatePath("/app", "layout");
  revalidatePath("/app/notifikasi");
}

export async function markNotificationRead(formData: FormData) {
  const user = await requireUser();
  const parsed = schema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return;
  await prisma.systemNotification.updateMany({
    where: { id: parsed.data.notificationId, userId: user.id, isRead: false },
    data: { isRead: true, readAt: new Date() },
  });
  refreshNotifications();
}

export async function markAllNotificationsRead() {
  const user = await requireUser();
  await prisma.systemNotification.updateMany({
    where: { userId: user.id, isRead: false },
    data: { isRead: true, readAt: new Date() },
  });
  refreshNotifications();
}
