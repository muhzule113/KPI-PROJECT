import "dotenv/config";

import { randomUUID } from "node:crypto";
import { PrismaPg } from "@prisma/adapter-pg";
import { hashPassword } from "better-auth/crypto";
import { PrismaClient, type UserRole } from "../src/generated/prisma/client.ts";
import type { AccessProfile } from "../src/modules/access/policy.ts";
import {
  indicatorCreateInput,
  MASTER_KPI_POSITION_NAMES,
  MASTER_KPI_TEMPLATES,
  type MasterKpiIndicator,
} from "../src/modules/kpi/master-kpi-templates.ts";
import { createPeriod, openPeriod } from "../src/modules/kpi/period-operations.ts";

const connectionString = process.env.DATABASE_URL;
const demoPassword = process.env.SEED_DEMO_PASSWORD;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");
if (!demoPassword || demoPassword.length < 10) throw new Error("SEED_DEMO_PASSWORD minimal 10 karakter.");

const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });
const passwordHash = await hashPassword(demoPassword);

async function credentialUser(username: string, name: string, role: UserRole) {
  const user = await prisma.user.findUnique({ where: { username } }) ?? await prisma.user.create({ data: { username, email: `${randomUUID()}@users.kpi.invalid`, name, role, isActive: true, emailVerified: true } });
  const credential = await prisma.account.findUnique({ where: { providerId_accountId: { providerId: "credential", accountId: user.id } } });
  if (!credential) await prisma.account.create({ data: { userId: user.id, providerId: "credential", accountId: user.id, password: passwordHash } });
  return user;
}

async function main() {
  const branch = await prisma.branch.findUnique({ where: { code: "PUSAT" } }) ?? await prisma.branch.create({ data: { code: "PUSAT", name: "Toko Pusat" } });

  const positions = Object.fromEntries(await Promise.all([
    ["MGR", "Manager", false],
    ...Object.entries(MASTER_KPI_POSITION_NAMES).map(([code, name]) => [code, name, true]),
  ].map(async ([code, name, isKpiSubject]) => {
    const position = await prisma.position.findUnique({ where: { code: String(code) } }) ?? await prisma.position.create({ data: { code: String(code), name: String(name), isKpiSubject: Boolean(isKpiSubject) } });
    return [code, position] as const;
  })));

  const adminUser = await credentialUser("admin", "Super Admin", "ADMIN");
  const backupAdminUser = await credentialUser("admin.cadangan", "Super Admin Cadangan", "ADMIN");
  const managerUser = await credentialUser("manager", "Manager Toko", "MANAGER");
  const supervisorUser = await credentialUser("supervisor", "Supervisor Toko", "SUPERVISOR");
  const employeeUser = await credentialUser("pegawai", "Pelayan Contoh", "EMPLOYEE");
  const technicianUser = await credentialUser("teknisi", "Teknisi Contoh", "EMPLOYEE");

  const manager = await prisma.employee.findUnique({ where: { employeeNumber: "EMP-MGR-001" } }) ?? await prisma.employee.create({ data: { userId: managerUser.id, employeeNumber: "EMP-MGR-001", name: managerUser.name, branchId: branch.id, positionId: positions.MGR.id, joinedAt: new Date("2025-01-01T00:00:00.000Z") } });
  const supervisor = await prisma.employee.findUnique({ where: { employeeNumber: "EMP-SPV-001" } }) ?? await prisma.employee.create({ data: { userId: supervisorUser.id, employeeNumber: "EMP-SPV-001", name: supervisorUser.name, branchId: branch.id, positionId: positions.SPV.id, managerId: manager.id, joinedAt: new Date("2025-01-01T00:00:00.000Z") } });
  if (!await prisma.employee.findUnique({ where: { employeeNumber: "EMP-PLY-001" } })) await prisma.employee.create({ data: { userId: employeeUser.id, employeeNumber: "EMP-PLY-001", name: employeeUser.name, branchId: branch.id, positionId: positions.PELAYAN.id, supervisorId: supervisor.id, managerId: manager.id, joinedAt: new Date("2025-01-01T00:00:00.000Z") } });
  if (!await prisma.employee.findUnique({ where: { employeeNumber: "EMP-TEK-001" } })) await prisma.employee.create({ data: { userId: technicianUser.id, employeeNumber: "EMP-TEK-001", name: technicianUser.name, branchId: branch.id, positionId: positions.TEKNISI.id, supervisorId: supervisor.id, managerId: manager.id, joinedAt: new Date("2025-01-01T00:00:00.000Z") } });

  for (const [code, indicators] of Object.entries(MASTER_KPI_TEMPLATES)) {
    await syncTemplate(positions[code].id, `KPI ${MASTER_KPI_POSITION_NAMES[code as keyof typeof MASTER_KPI_POSITION_NAMES]}`, indicators);
  }

  await ensureDefaultRatingScheme();

  const admin: AccessProfile = { userId: adminUser.id, role: "ADMIN", employeeId: null, branchId: null, active: true };
  const period = await prisma.kpiPeriod.findUnique({ where: { year_month: { year: 2026, month: 9 } } });
  if (!period) {
    await prisma.$transaction(async (tx) => {
      const created = await createPeriod(tx, admin, { year: 2026, month: 9 });
      await openPeriod(tx, admin, created.id);
    });
  }

  console.info(`Seed selesai untuk ${adminUser.username}, ${backupAdminUser.username}, ${managerUser.username}, ${supervisorUser.username}, ${employeeUser.username}, dan ${technicianUser.username}.`);
}

async function syncTemplate(positionId: string, name: string, indicators: readonly MasterKpiIndicator[]) {
  const template = await prisma.kpiTemplate.findUnique({ where: { positionId } }) ?? await prisma.kpiTemplate.create({ data: { positionId, name } });
  if (await prisma.kpiTemplateVersion.count({ where: { templateId: template.id } })) return;
  await prisma.kpiTemplateVersion.create({
    data: {
      templateId: template.id,
      versionNumber: 1,
      status: "ACTIVE",
      activatedAt: new Date(),
      indicators: { create: indicators.map((indicator) => indicatorCreateInput(indicator)) },
    },
  });
}

async function ensureDefaultRatingScheme() {
  if (await prisma.kpiRatingScheme.count()) return;
  const latest = await prisma.kpiRatingScheme.aggregate({ _max: { version: true } });
  await prisma.kpiRatingScheme.create({
    data: {
      version: (latest._max.version ?? 0) + 1,
      status: "ACTIVE",
      activatedAt: new Date(),
      bands: {
        create: [
          { code: "POOR", label: "Perlu Perbaikan", minScore: 0, sortOrder: 1 },
          { code: "FAIR", label: "Cukup", minScore: 70, sortOrder: 2 },
          { code: "GOOD", label: "Baik", minScore: 80, sortOrder: 3 },
          { code: "VERY_GOOD", label: "Sangat Baik", minScore: 90, sortOrder: 4 },
          { code: "STAR", label: "Istimewa", minScore: 95, sortOrder: 5 },
        ],
      },
    },
  });
}

try {
  await main();
} finally {
  await prisma.$disconnect();
}
