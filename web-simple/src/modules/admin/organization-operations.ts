import { hashPassword } from "better-auth/crypto";
import { Prisma, type UserRole } from "@/generated/prisma/client";
import type { AccessProfile } from "@/modules/access/policy";

function assertAdmin(actor: AccessProfile) {
  if (!actor.active || actor.role !== "ADMIN") throw new Error("Hanya Super Admin yang dapat mengubah pengaturan.");
}

export async function saveBranch(tx: Prisma.TransactionClient, actor: AccessProfile, input: { id?: string; code: string; name: string; isActive: boolean }) {
  assertAdmin(actor);
  const data = { code: input.code.trim().toUpperCase(), name: input.name.trim(), isActive: input.isActive };
  if (!data.code || !data.name) throw new Error("Kode dan nama cabang wajib diisi.");
  const branch = input.id ? await tx.branch.update({ where: { id: input.id }, data }) : await tx.branch.create({ data });
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: input.id ? "update_branch" : "create_branch", subjectType: "Branch", subjectId: branch.id, afterJson: data } });
  return branch;
}

export async function savePosition(tx: Prisma.TransactionClient, actor: AccessProfile, input: { id?: string; code: string; name: string; isKpiSubject: boolean; isActive: boolean }) {
  assertAdmin(actor);
  const data = { code: input.code.trim().toUpperCase(), name: input.name.trim(), isKpiSubject: input.isKpiSubject, isActive: input.isActive };
  if (!data.code || !data.name) throw new Error("Kode dan nama jabatan wajib diisi.");
  const before = input.id ? await tx.position.findUnique({ where: { id: input.id } }) : null;
  if (input.id && !before) throw new Error("Jabatan tidak ditemukan.");
  if ((!data.isActive || !data.isKpiSubject) && input.id && await tx.employee.count({ where: { positionId: input.id, status: "ACTIVE" } })) {
    throw new Error("Jabatan yang masih dipakai pegawai aktif tidak dapat dinonaktifkan atau dikeluarkan dari KPI.");
  }
  const position = input.id ? await tx.position.update({ where: { id: input.id }, data }) : await tx.position.create({ data });
  if (position.isKpiSubject) {
    const template = await tx.kpiTemplate.upsert({
      where: { positionId: position.id },
      update: {},
      create: { positionId: position.id, name: `KPI ${position.name}` },
    });
    const editableOrActive = await tx.kpiTemplateVersion.findFirst({ where: { templateId: template.id, status: { in: ["DRAFT", "ACTIVE"] } } });
    if (!editableOrActive) {
      const latest = await tx.kpiTemplateVersion.aggregate({ where: { templateId: template.id }, _max: { versionNumber: true } });
      await tx.kpiTemplateVersion.create({ data: { templateId: template.id, versionNumber: (latest._max.versionNumber ?? 0) + 1 } });
    }
  }
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: input.id ? "update_position" : "create_position", subjectType: "Position", subjectId: position.id, beforeJson: before ? { code: before.code, name: before.name, isKpiSubject: before.isKpiSubject, isActive: before.isActive } : undefined, afterJson: data } });
  return position;
}

export async function createAccount(tx: Prisma.TransactionClient, actor: AccessProfile, input: {
  name: string;
  email: string;
  password: string;
  role: UserRole;
  employeeNumber?: string;
  branchId?: string;
  positionId?: string;
  supervisorId?: string;
  managerId?: string;
  joinedAt?: Date;
}) {
  assertAdmin(actor);
  const name = input.name.trim();
  const email = input.email.trim().toLowerCase();
  if (!name || !email) throw new Error("Nama dan email wajib diisi.");
  if (input.password.length < 10 || input.password.length > 128) throw new Error("Kata sandi harus 10 sampai 128 karakter.");

  let organization: Awaited<ReturnType<typeof organizationForRole>> | null = null;
  if (input.role !== "ADMIN") organization = await organizationForRole(tx, input);
  const user = await tx.user.create({ data: { name, email, emailVerified: true, role: input.role, isActive: true } });
  await tx.account.create({
    data: { userId: user.id, providerId: "credential", accountId: user.id, password: await hashPassword(input.password) },
  });
  if (organization) {
    await tx.employee.create({
      data: {
        userId: user.id,
        employeeNumber: input.employeeNumber!.trim().toUpperCase(),
        name,
        email,
        branchId: input.branchId!,
        positionId: input.positionId!,
        supervisorId: input.role === "EMPLOYEE" ? input.supervisorId : null,
        managerId: input.role === "MANAGER" ? null : input.managerId,
        joinedAt: input.joinedAt!,
      },
    });
  }
  await tx.auditEvent.create({
    data: { actorId: actor.userId, action: "create_account", subjectType: "User", subjectId: user.id, afterJson: { name, email, role: input.role, branchId: input.branchId ?? null, positionId: input.positionId ?? null } },
  });
  return user;
}

async function organizationForRole(tx: Prisma.TransactionClient, input: {
  role: UserRole;
  employeeNumber?: string;
  branchId?: string;
  positionId?: string;
  supervisorId?: string;
  managerId?: string;
  joinedAt?: Date;
}) {
  if (!input.employeeNumber?.trim() || !input.branchId || !input.positionId || !input.joinedAt) throw new Error("Nomor pegawai, cabang, jabatan, dan tanggal masuk wajib diisi.");
  const [branch, position] = await Promise.all([
    tx.branch.findUnique({ where: { id: input.branchId } }),
    tx.position.findUnique({ where: { id: input.positionId } }),
  ]);
  if (!branch?.isActive || !position?.isActive) throw new Error("Cabang dan jabatan harus aktif.");
  if (input.role === "MANAGER") {
    if (position.isKpiSubject) throw new Error("Jabatan Manager harus ditandai bukan subjek KPI.");
    return { branch, position };
  }
  if (!position.isKpiSubject) throw new Error("Jabatan Supervisor dan Pegawai harus menjadi subjek KPI.");
  if (!input.managerId) throw new Error("Manager wajib dipilih.");
  const manager = await tx.employee.findUnique({ where: { id: input.managerId }, include: { user: true } });
  if (!manager || manager.status !== "ACTIVE" || manager.branchId !== branch.id || manager.user.role !== "MANAGER" || !manager.user.isActive) throw new Error("Manager harus aktif dan berada di cabang yang sama.");
  if (input.role === "EMPLOYEE") {
    if (!input.supervisorId) throw new Error("Supervisor wajib dipilih.");
    const supervisor = await tx.employee.findUnique({ where: { id: input.supervisorId }, include: { user: true } });
    if (!supervisor || supervisor.status !== "ACTIVE" || supervisor.branchId !== branch.id || supervisor.user.role !== "SUPERVISOR" || !supervisor.user.isActive || supervisor.managerId !== manager.id) {
      throw new Error("Supervisor harus aktif, berada di cabang yang sama, dan berada di bawah Manager terpilih.");
    }
  }
  return { branch, position };
}

export async function updateAccount(tx: Prisma.TransactionClient, actor: AccessProfile, input: {
  userId: string;
  name: string;
  email: string;
  isActive: boolean;
  password?: string;
}) {
  assertAdmin(actor);
  const existing = await tx.user.findUnique({ where: { id: input.userId }, include: { employee: true } });
  if (!existing) throw new Error("Akun tidak ditemukan.");
  if (existing.id === actor.userId && !input.isActive) throw new Error("Super Admin tidak dapat menonaktifkan akun yang sedang dipakai.");
  const name = input.name.trim();
  const email = input.email.trim().toLowerCase();
  if (!name || !email) throw new Error("Nama dan email wajib diisi.");
  if (input.password && (input.password.length < 10 || input.password.length > 128)) throw new Error("Kata sandi harus 10 sampai 128 karakter.");
  await tx.user.update({ where: { id: existing.id }, data: { name, email, isActive: input.isActive } });
  if (existing.employee) await tx.employee.update({ where: { id: existing.employee.id }, data: { name, email, status: input.isActive ? "ACTIVE" : "INACTIVE" } });
  if (!input.isActive || input.password) await tx.session.deleteMany({ where: { userId: existing.id } });
  if (input.password) await tx.account.update({ where: { providerId_accountId: { providerId: "credential", accountId: existing.id } }, data: { password: await hashPassword(input.password) } });
  await tx.auditEvent.create({
    data: {
      actorId: actor.userId,
      action: "update_account",
      subjectType: "User",
      subjectId: existing.id,
      beforeJson: { name: existing.name, email: existing.email, isActive: existing.isActive },
      afterJson: { name, email, isActive: input.isActive, passwordChanged: Boolean(input.password) },
    },
  });
}

export async function updateEmployeeProfile(tx: Prisma.TransactionClient, actor: AccessProfile, input: {
  userId: string;
  employeeNumber: string;
  branchId: string;
  positionId: string;
  supervisorId?: string;
  managerId?: string;
  joinedAt: Date;
  endedAt?: Date;
}) {
  assertAdmin(actor);
  const existing = await tx.employee.findUnique({ where: { userId: input.userId }, include: { user: true } });
  if (!existing || existing.user.role === "ADMIN") throw new Error("Profil pegawai tidak ditemukan.");
  if (input.endedAt && input.endedAt < input.joinedAt) throw new Error("Tanggal selesai kerja tidak boleh sebelum tanggal masuk.");
  await organizationForRole(tx, { ...input, role: existing.user.role });
  const data = {
    employeeNumber: input.employeeNumber.trim().toUpperCase(),
    branchId: input.branchId,
    positionId: input.positionId,
    supervisorId: existing.user.role === "EMPLOYEE" ? input.supervisorId : null,
    managerId: existing.user.role === "MANAGER" ? null : input.managerId,
    joinedAt: input.joinedAt,
    endedAt: input.endedAt ?? null,
    status: existing.user.isActive ? (input.endedAt ? "RESIGNED" as const : "ACTIVE" as const) : "INACTIVE" as const,
  };
  const employee = await tx.employee.update({ where: { id: existing.id }, data });
  await tx.auditEvent.create({
    data: {
      actorId: actor.userId,
      action: "update_employee_profile",
      subjectType: "Employee",
      subjectId: employee.id,
      beforeJson: { employeeNumber: existing.employeeNumber, branchId: existing.branchId, positionId: existing.positionId, supervisorId: existing.supervisorId, managerId: existing.managerId, joinedAt: existing.joinedAt.toISOString(), endedAt: existing.endedAt?.toISOString() ?? null },
      afterJson: { ...data, joinedAt: data.joinedAt.toISOString(), endedAt: data.endedAt?.toISOString() ?? null },
    },
  });
  return employee;
}
