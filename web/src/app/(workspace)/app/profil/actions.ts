"use server";

import { headers } from "next/headers";
import { revalidatePath } from "next/cache";
import { z } from "zod";
import { auth } from "@/lib/auth";
import { hashApplicationPassword, verifyApplicationPassword } from "@/lib/password";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/modules/access/current-user";

export type SecurityActionState = { error?: string; success?: string };

const passwordSchema = z.object({
  currentPassword: z.string().min(1).max(128),
  newPassword: z.string().min(10).max(128),
  confirmation: z.string().min(1).max(128),
}).superRefine((value, context) => {
  if (value.newPassword !== value.confirmation) context.addIssue({ code: "custom", path: ["confirmation"], message: "Konfirmasi tidak sama." });
  if (value.newPassword === value.currentPassword) context.addIssue({ code: "custom", path: ["newPassword"], message: "Kata sandi baru harus berbeda." });
});

async function activeSession() {
  const session = await auth.api.getSession({ headers: await headers() });
  if (!session) throw new Error("Sesi tidak lagi aktif. Silakan masuk kembali.");
  return session;
}

export async function changeOwnPassword(_: SecurityActionState, formData: FormData): Promise<SecurityActionState> {
  const user = await requireUser();
  const parsed = passwordSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: parsed.error.issues[0]?.message ?? "Periksa kata sandi yang diisi." };

  try {
    const session = await activeSession();
    if (session.user.id !== user.id) throw new Error("Sesi tidak valid.");
    const account = await prisma.account.findFirst({ where: { userId: user.id, providerId: "credential" }, select: { id: true } });
    if (!account) throw new Error("Akun tidak memiliki login kata sandi.");
    const password = await hashApplicationPassword(parsed.data.newPassword);

    await prisma.$transaction(async (tx) => {
      await tx.$queryRaw`SELECT id FROM accounts WHERE id = ${account.id} FOR UPDATE`;
      const locked = await tx.account.findUnique({ where: { id: account.id }, select: { password: true } });
      if (!locked?.password || !await verifyApplicationPassword({ hash: locked.password, password: parsed.data.currentPassword })) throw new Error("Kata sandi saat ini tidak cocok.");
      await tx.account.update({ where: { id: account.id }, data: { password } });
      await tx.session.deleteMany({ where: { userId: user.id, id: { not: session.session.id } } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "change_own_password", subjectType: "User", subjectId: user.id, afterJson: { otherSessionsRevoked: true } } });
    });
  } catch (error) {
    return { error: error instanceof Error ? error.message : "Kata sandi tidak dapat diubah." };
  }

  revalidatePath("/app/profil");
  return { success: "Kata sandi diperbarui dan sesi lain telah dicabut." };
}

const sessionSchema = z.object({ sessionId: z.string().min(1) });

export async function revokeOwnSession(formData: FormData) {
  const user = await requireUser();
  const parsed = sessionSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return;
  const session = await activeSession();
  if (session.session.id === parsed.data.sessionId) return;

  await prisma.$transaction(async (tx) => {
    const removed = await tx.session.deleteMany({ where: { id: parsed.data.sessionId, userId: user.id } });
    if (removed.count) await tx.auditEvent.create({ data: { actorId: user.id, action: "revoke_own_session", subjectType: "Session", subjectId: parsed.data.sessionId } });
  });
  revalidatePath("/app/profil");
}

export async function revokeOtherSessions() {
  const user = await requireUser();
  const session = await activeSession();
  await prisma.$transaction(async (tx) => {
    const removed = await tx.session.deleteMany({ where: { userId: user.id, id: { not: session.session.id } } });
    if (removed.count) await tx.auditEvent.create({ data: { actorId: user.id, action: "revoke_other_sessions", subjectType: "User", subjectId: user.id, afterJson: { count: removed.count } } });
  });
  revalidatePath("/app/profil");
}
