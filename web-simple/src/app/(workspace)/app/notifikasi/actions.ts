"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/modules/access/current-user";

export async function markNotificationRead(formData: FormData) {
  const user = await requireUser();
  const id = z.string().min(1).parse(formData.get("notificationId"));
  await prisma.notification.updateMany({ where: { id, userId: user.userId }, data: { readAt: new Date() } });
  revalidatePath("/app/notifikasi");
}

export async function markAllNotificationsRead() {
  const user = await requireUser();
  await prisma.notification.updateMany({ where: { userId: user.userId, readAt: null }, data: { readAt: new Date() } });
  revalidatePath("/app/notifikasi");
}
