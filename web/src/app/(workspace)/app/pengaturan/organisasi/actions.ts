"use server";

import { hashPassword } from "better-auth/crypto";
import { revalidatePath } from "next/cache";
import { z } from "zod";
import type { Prisma } from "@/generated/prisma/client";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";

export type OrganizationActionState = { error?: string; success?: string };

const id = z.preprocess((value) => value === "" ? undefined : value, z.string().min(1).optional());
const date = z.string().regex(/^\d{4}-\d{2}-\d{2}$/).transform((value, context) => {
  const parsed = new Date(`${value}T00:00:00.000Z`);
  if (Number.isNaN(parsed.valueOf()) || parsed.toISOString().slice(0, 10) !== value) {
    context.addIssue({ code: "custom", message: "Tanggal tidak valid." });
    return z.NEVER;
  }
  return parsed;
});
const message = (error: unknown) => error instanceof Error && !error.message.toLowerCase().includes("prisma")
  ? error.message
  : "Data tidak dapat disimpan. Periksa duplikasi kode, nomor karyawan, atau email.";

const branchSchema = z.object({ id, code: z.string().trim().toUpperCase().min(2).max(20).regex(/^[A-Z0-9-]+$/), name: z.string().trim().min(2).max(100), address: z.string().trim().max(500).optional(), phone: z.string().trim().max(30).optional(), isActive: z.enum(["true", "false"]) });
export async function saveBranch(_: OrganizationActionState, formData: FormData): Promise<OrganizationActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "organization.manage")) return { error: "Anda tidak berwenang mengelola cabang." };
  const parsed = branchSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa kode, nama, telepon, dan status cabang." };
  try {
    await prisma.$transaction(async (tx) => {
      const before = parsed.data.id ? await tx.branch.findUnique({ where: { id: parsed.data.id } }) : null;
      if (parsed.data.id && !before) throw new Error("Cabang tidak ditemukan.");
      const values = { code: parsed.data.code, name: parsed.data.name, address: parsed.data.address || null, phone: parsed.data.phone || null, isActive: parsed.data.isActive === "true" };
      const branch = before ? await tx.branch.update({ where: { id: before.id }, data: values }) : await tx.branch.create({ data: values });
      await tx.auditEvent.create({ data: { actorId: user.id, action: before ? "update_branch" : "create_branch", subjectType: "Branch", subjectId: branch.id, beforeJson: before ? { code: before.code, name: before.name, isActive: before.isActive } : undefined, afterJson: values } });
    });
  } catch (error) { return { error: message(error) }; }
  revalidatePath("/app/pengaturan/organisasi"); revalidatePath("/app/pengaturan");
  return { success: parsed.data.id ? "Cabang diperbarui." : "Cabang dibuat." };
}

const positionSchema = z.object({ id, code: z.string().trim().toUpperCase().min(2).max(30).regex(/^[A-Z0-9-]+$/), name: z.string().trim().min(2).max(100), department: z.string().trim().min(2).max(100), description: z.string().trim().max(500).optional(), isActive: z.enum(["true", "false"]) });
export async function savePosition(_: OrganizationActionState, formData: FormData): Promise<OrganizationActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "organization.manage")) return { error: "Anda tidak berwenang mengelola jabatan." };
  const parsed = positionSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa kode, nama, departemen, dan status jabatan." };
  try {
    await prisma.$transaction(async (tx) => {
      const before = parsed.data.id ? await tx.position.findUnique({ where: { id: parsed.data.id } }) : null;
      if (parsed.data.id && !before) throw new Error("Jabatan tidak ditemukan.");
      const values = { code: parsed.data.code, name: parsed.data.name, department: parsed.data.department, description: parsed.data.description || null, isActive: parsed.data.isActive === "true" };
      const position = before ? await tx.position.update({ where: { id: before.id }, data: values }) : await tx.position.create({ data: values });
      await tx.auditEvent.create({ data: { actorId: user.id, action: before ? "update_position" : "create_position", subjectType: "Position", subjectId: position.id, beforeJson: before ? { code: before.code, name: before.name, isActive: before.isActive } : undefined, afterJson: values } });
    });
  } catch (error) { return { error: message(error) }; }
  revalidatePath("/app/pengaturan/organisasi"); revalidatePath("/app/pengaturan");
  return { success: parsed.data.id ? "Jabatan diperbarui." : "Jabatan dibuat." };
}

const employeeSchema = z.object({
  id,
  employeeNumber: z.string().trim().toUpperCase().min(2).max(40),
  name: z.string().trim().min(2).max(120),
  email: z.string().trim().toLowerCase().email().max(190),
  phone: z.string().trim().max(30).optional(),
  positionId: z.string().min(1),
  branchId: z.string().min(1),
  supervisorId: id,
  joinedAt: date,
  placementEffectiveFrom: date,
  status: z.enum(["ACTIVE", "INACTIVE", "RESIGNED", "LEAVE"]),
});

async function assertReviewer(tx: Prisma.TransactionClient, employeeId: string | undefined, positionCode: string, branchId: string, reviewerId?: string) {
  if (["POS-OWN", "POS-EXEC"].includes(positionCode)) {
    if (reviewerId) throw new Error("Jabatan Manager/Owner tidak boleh memiliki atasan operasional.");
    return;
  }
  if (!reviewerId || reviewerId === employeeId) throw new Error("Pilih penilai yang berbeda dari karyawan.");
  const reviewer = await tx.employee.findUnique({ where: { id: reviewerId }, include: { user: true, position: true } });
  const expectedRole = positionCode === "POS-SPV" ? "owner_manager" : "supervisor";
  if (!reviewer || reviewer.status !== "ACTIVE" || reviewer.branchId !== branchId || !reviewer.user?.isActive || reviewer.user.role !== expectedRole) throw new Error(`Penilai harus aktif, satu cabang, dan memiliki role ${expectedRole}.`);
}

export async function saveEmployee(_: OrganizationActionState, formData: FormData): Promise<OrganizationActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "organization.manage")) return { error: "Anda tidak berwenang mengelola karyawan." };
  const parsed = employeeSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa identitas, penempatan, penilai, dan tanggal efektif." };
  const data = parsed.data;
  if (data.placementEffectiveFrom < data.joinedAt || data.placementEffectiveFrom > new Date()) return { error: "Tanggal efektif penempatan harus antara tanggal bergabung dan hari ini." };
  try {
    await prisma.$transaction(async (tx) => {
      const [position, branch, before] = await Promise.all([
        tx.position.findFirst({ where: { id: data.positionId, isActive: true } }),
        tx.branch.findFirst({ where: { id: data.branchId, isActive: true } }),
        data.id ? tx.employee.findUnique({ where: { id: data.id } }) : Promise.resolve(null),
      ]);
      if (!position || !branch) throw new Error("Cabang atau jabatan tidak ditemukan atau tidak aktif.");
      if (data.id && !before) throw new Error("Karyawan tidak ditemukan.");
      await assertReviewer(tx, data.id, position.code, branch.id, data.supervisorId);
      const values = { employeeNumber: data.employeeNumber, name: data.name, email: data.email, phone: data.phone || null, positionId: position.id, branchId: branch.id, supervisorId: data.supervisorId ?? null, joinedAt: data.joinedAt, status: data.status };
      const employee = before ? await tx.employee.update({ where: { id: before.id }, data: values }) : await tx.employee.create({ data: values });
      const placementChanged = !before || before.positionId !== position.id || before.branchId !== branch.id || before.supervisorId !== (data.supervisorId ?? null);
      if (placementChanged) {
        const current = await tx.employeePlacement.findFirst({ where: { employeeId: employee.id, effectiveUntil: null }, orderBy: { effectiveFrom: "desc" } });
        if (current && data.placementEffectiveFrom < current.effectiveFrom) throw new Error("Tanggal efektif penempatan tidak boleh sebelum penempatan aktif.");
        if (current?.effectiveFrom.getTime() === data.placementEffectiveFrom.getTime()) {
          await tx.employeePlacement.update({ where: { id: current.id }, data: { positionId: position.id, branchId: branch.id, supervisorId: data.supervisorId ?? null } });
        } else {
          if (current) await tx.employeePlacement.update({ where: { id: current.id }, data: { effectiveUntil: new Date(data.placementEffectiveFrom.valueOf() - 86_400_000) } });
          await tx.employeePlacement.create({ data: { employeeId: employee.id, positionId: position.id, branchId: branch.id, supervisorId: data.supervisorId ?? null, effectiveFrom: data.placementEffectiveFrom } });
        }
      }
      await tx.auditEvent.create({ data: { actorId: user.id, action: before ? "update_employee" : "create_employee", subjectType: "Employee", subjectId: employee.id, beforeJson: before ? { positionId: before.positionId, branchId: before.branchId, supervisorId: before.supervisorId, status: before.status } : undefined, afterJson: { positionId: employee.positionId, branchId: employee.branchId, supervisorId: employee.supervisorId, status: employee.status, placementEffectiveFrom: data.placementEffectiveFrom.toISOString().slice(0, 10) } } });
    });
  } catch (error) { return { error: message(error) }; }
  revalidatePath("/app/pengaturan/organisasi"); revalidatePath("/app/pengaturan");
  return { success: data.id ? "Karyawan dan histori penempatan diperbarui." : "Karyawan dibuat." };
}

const accountSchema = z.object({
  id,
  name: z.string().trim().min(2).max(120),
  email: z.string().trim().toLowerCase().email().max(190),
  role: z.enum(["super_admin", "kpi_admin", "auditor", "owner_manager", "supervisor", "employee"]),
  employeeId: id,
  password: z.string().max(200).optional(),
  isActive: z.enum(["true", "false"]),
});

export async function saveAccount(_: OrganizationActionState, formData: FormData): Promise<OrganizationActionState> {
  const actor = await requireUser();
  if (!hasCapability(actor, "accounts.manage")) return { error: "Anda tidak berwenang mengelola akun." };
  const parsed = accountSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa nama, email, role, karyawan, dan kata sandi." };
  const data = parsed.data;
  const administrative = ["super_admin", "kpi_admin", "auditor"].includes(data.role);
  if (administrative && data.employeeId) return { error: "Role administratif tidak boleh ditautkan ke profil operasional." };
  if (!administrative && !data.employeeId) return { error: "Role operasional wajib ditautkan ke profil karyawan." };
  if (!data.id && (!data.password || data.password.length < 10)) return { error: "Akun baru wajib memiliki kata sandi minimal 10 karakter." };
  if (data.password && data.password.length < 10) return { error: "Kata sandi minimal 10 karakter." };
  try {
    const password = data.password ? await hashPassword(data.password) : null;
    await prisma.$transaction(async (tx) => {
      const before = data.id ? await tx.user.findUnique({ where: { id: data.id }, include: { employee: true } }) : null;
      if (data.id && !before) throw new Error("Akun tidak ditemukan.");
      const employee = data.employeeId ? await tx.employee.findUnique({ where: { id: data.employeeId }, include: { position: true } }) : null;
      if (data.employeeId && (!employee || (employee.userId && employee.userId !== data.id))) throw new Error("Karyawan tidak ditemukan atau sudah terhubung ke akun lain.");
      const expectedRole = employee ? ["POS-OWN", "POS-EXEC"].includes(employee.position.code) ? "owner_manager" : employee.position.code === "POS-SPV" ? "supervisor" : "employee" : null;
      if (expectedRole && expectedRole !== data.role) throw new Error(`Jabatan ${employee!.position.name} harus memakai role ${expectedRole}.`);
      const account = before ? await tx.user.update({ where: { id: before.id }, data: { name: data.name, email: data.email, role: data.role, isActive: data.isActive === "true" } }) : await tx.user.create({ data: { name: data.name, email: data.email, emailVerified: true, role: data.role, isActive: data.isActive === "true" } });
      await tx.employee.updateMany({ where: { userId: account.id, id: { not: data.employeeId ?? "__none__" } }, data: { userId: null } });
      if (employee) await tx.employee.update({ where: { id: employee.id }, data: { userId: account.id } });
      if (password) await tx.account.upsert({ where: { providerId_accountId: { providerId: "credential", accountId: account.id } }, update: { password, userId: account.id }, create: { providerId: "credential", accountId: account.id, userId: account.id, password } });
      if (!account.isActive || password) await tx.session.deleteMany({ where: { userId: account.id } });
      await tx.auditEvent.create({ data: { actorId: actor.id, action: before ? "update_user_account" : "create_user_account", subjectType: "User", subjectId: account.id, beforeJson: before ? { email: before.email, role: before.role, isActive: before.isActive, employeeId: before.employee?.id ?? null } : undefined, afterJson: { email: account.email, role: account.role, isActive: account.isActive, employeeId: employee?.id ?? null, passwordChanged: Boolean(password) } } });
    });
  } catch (error) { return { error: message(error) }; }
  revalidatePath("/app/pengaturan/organisasi"); revalidatePath("/app/pengaturan");
  return { success: data.id ? "Akun diperbarui dan sesi lama yang relevan dicabut." : "Akun dibuat." };
}
