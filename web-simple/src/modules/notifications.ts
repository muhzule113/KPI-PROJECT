import type { Prisma } from "@/generated/prisma/client";

export async function notifyUsers(
  tx: Prisma.TransactionClient,
  userIds: Array<string | null | undefined>,
  input: { title: string; body: string; type: string; actionUrl?: string; dedupeKey: string },
) {
  const recipients = [...new Set(userIds.filter((userId): userId is string => Boolean(userId)))];
  if (!recipients.length) return;
  await tx.notification.createMany({
    data: recipients.map((userId) => ({
      userId,
      title: input.title,
      body: input.body,
      type: input.type,
      actionUrl: input.actionUrl,
      dedupeKey: `${input.dedupeKey}:${userId}`,
    })),
    skipDuplicates: true,
  });
}
