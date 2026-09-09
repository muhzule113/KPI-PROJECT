import "dotenv/config";

import { PrismaPg } from "@prisma/adapter-pg";
import { hashPassword } from "better-auth/crypto";
import { PrismaClient, type UserRole } from "../src/generated/prisma/client.ts";
import type { AccessProfile } from "../src/modules/access/policy.ts";
import { createPeriod, openPeriod } from "../src/modules/kpi/period-operations.ts";

const connectionString = process.env.DATABASE_URL;
const demoPassword = process.env.SEED_DEMO_PASSWORD;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");
if (!demoPassword || demoPassword.length < 10) throw new Error("SEED_DEMO_PASSWORD minimal 10 karakter.");

const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });
const passwordHash = await hashPassword(demoPassword);

async function credentialUser(email: string, name: string, role: UserRole) {
  const user = await prisma.user.findUnique({ where: { email } }) ?? await prisma.user.create({ data: { email, name, role, isActive: true, emailVerified: true } });
  const credential = await prisma.account.findUnique({ where: { providerId_accountId: { providerId: "credential", accountId: user.id } } });
  if (!credential) await prisma.account.create({ data: { userId: user.id, providerId: "credential", accountId: user.id, password: passwordHash } });
  return user;
}

async function main() {
  const branch = await prisma.branch.findUnique({ where: { code: "PUSAT" } }) ?? await prisma.branch.create({ data: { code: "PUSAT", name: "Toko Pusat" } });

  const positions = Object.fromEntries(await Promise.all([
    ["MGR", "Manager", false],
    ["SPV", "Supervisor", true],
    ["PELAYAN", "Pelayan", true],
    ["TEKNISI", "Teknisi", true],
  ].map(async ([code, name, isKpiSubject]) => {
    const position = await prisma.position.findUnique({ where: { code: String(code) } }) ?? await prisma.position.create({ data: { code: String(code), name: String(name), isKpiSubject: Boolean(isKpiSubject) } });
    return [code, position] as const;
  })));

  const adminUser = await credentialUser("admin@kpi-simple.local", "Super Admin", "ADMIN");
  const managerUser = await credentialUser("manager@kpi-simple.local", "Manager Toko", "MANAGER");
  const supervisorUser = await credentialUser("supervisor@kpi-simple.local", "Supervisor Toko", "SUPERVISOR");
  const employeeUser = await credentialUser("pegawai@kpi-simple.local", "Pelayan Contoh", "EMPLOYEE");
  const technicianUser = await credentialUser("teknisi@kpi-simple.local", "Teknisi Contoh", "EMPLOYEE");

  const manager = await prisma.employee.findUnique({ where: { employeeNumber: "EMP-MGR-001" } }) ?? await prisma.employee.create({ data: { userId: managerUser.id, employeeNumber: "EMP-MGR-001", name: managerUser.name, email: managerUser.email, branchId: branch.id, positionId: positions.MGR.id, joinedAt: new Date("2025-01-01T00:00:00.000Z") } });
  const supervisor = await prisma.employee.findUnique({ where: { employeeNumber: "EMP-SPV-001" } }) ?? await prisma.employee.create({ data: { userId: supervisorUser.id, employeeNumber: "EMP-SPV-001", name: supervisorUser.name, email: supervisorUser.email, branchId: branch.id, positionId: positions.SPV.id, managerId: manager.id, joinedAt: new Date("2025-01-01T00:00:00.000Z") } });
  if (!await prisma.employee.findUnique({ where: { employeeNumber: "EMP-PLY-001" } })) await prisma.employee.create({ data: { userId: employeeUser.id, employeeNumber: "EMP-PLY-001", name: employeeUser.name, email: employeeUser.email, branchId: branch.id, positionId: positions.PELAYAN.id, supervisorId: supervisor.id, managerId: manager.id, joinedAt: new Date("2025-01-01T00:00:00.000Z") } });
  if (!await prisma.employee.findUnique({ where: { employeeNumber: "EMP-TEK-001" } })) await prisma.employee.create({ data: { userId: technicianUser.id, employeeNumber: "EMP-TEK-001", name: technicianUser.name, email: technicianUser.email, branchId: branch.id, positionId: positions.TEKNISI.id, supervisorId: supervisor.id, managerId: manager.id, joinedAt: new Date("2025-01-01T00:00:00.000Z") } });

  await syncTemplate(positions.PELAYAN.id, "KPI Pelayan", [
    { code: "LAYANAN", name: "Layanan selesai", description: "Jumlah layanan pelanggan yang selesai pada hari kerja.", kind: "NUMERIC", unit: "layanan", aggregation: "SUM", direction: "HIGHER", target: 120, failureLimit: null, weight: 40, sortOrder: 1 },
    { code: "KUALITAS", name: "Kualitas pelayanan", description: "Rating kualitas komunikasi dan ketepatan pelayanan.", kind: "RATING", unit: "rating", aggregation: "AVERAGE", direction: "HIGHER", target: 4, failureLimit: null, weight: 35, sortOrder: 2 },
    { code: "DISIPLIN", name: "Kedisiplinan kerja", description: "Rating kepatuhan terhadap jadwal dan prosedur kerja.", kind: "RATING", unit: "rating", aggregation: "AVERAGE", direction: "HIGHER", target: 4, failureLimit: null, weight: 25, sortOrder: 3 },
  ]);
  await syncTemplate(positions.TEKNISI.id, "KPI Teknisi", [
    { code: "SERVIS", name: "Servis selesai", description: "Jumlah pekerjaan servis yang selesai pada hari kerja.", kind: "NUMERIC", unit: "servis", aggregation: "SUM", direction: "HIGHER", target: 80, failureLimit: null, weight: 45, sortOrder: 1 },
    { code: "KUALITAS", name: "Kualitas hasil servis", description: "Rating kerapian dan ketepatan hasil pekerjaan.", kind: "RATING", unit: "rating", aggregation: "AVERAGE", direction: "HIGHER", target: 4, failureLimit: null, weight: 35, sortOrder: 2 },
    { code: "DISIPLIN", name: "Kedisiplinan kerja", description: "Rating kepatuhan terhadap jadwal dan prosedur kerja.", kind: "RATING", unit: "rating", aggregation: "AVERAGE", direction: "HIGHER", target: 4, failureLimit: null, weight: 20, sortOrder: 3 },
  ]);
  await syncTemplate(positions.SPV.id, "KPI Supervisor", [
    { code: "KONTROL", name: "Kontrol pekerjaan tim", description: "Rating konsistensi pengawasan pekerjaan tim.", kind: "RATING", unit: "rating", aggregation: "AVERAGE", direction: "HIGHER", target: 4, failureLimit: null, weight: 40, sortOrder: 1 },
    { code: "COACHING", name: "Coaching tim", description: "Jumlah sesi coaching yang dilakukan selama periode.", kind: "NUMERIC", unit: "sesi", aggregation: "SUM", direction: "HIGHER", target: 8, failureLimit: null, weight: 30, sortOrder: 2 },
    { code: "KEPEMIMPINAN", name: "Kepemimpinan", description: "Rating arahan, komunikasi, dan tindak lanjut Supervisor.", kind: "RATING", unit: "rating", aggregation: "AVERAGE", direction: "HIGHER", target: 4, failureLimit: null, weight: 30, sortOrder: 3 },
  ]);

  await ensureDefaultRatingScheme();

  const admin: AccessProfile = { userId: adminUser.id, role: "ADMIN", employeeId: null, branchId: null, active: true };
  const period = await prisma.kpiPeriod.findUnique({ where: { year_month: { year: 2026, month: 9 } } });
  if (!period) {
    await prisma.$transaction(async (tx) => {
      const created = await createPeriod(tx, admin, { year: 2026, month: 9 });
      await openPeriod(tx, admin, created.id);
    });
  }

  console.info(`Seed selesai untuk ${adminUser.email}, ${managerUser.email}, ${supervisorUser.email}, ${employeeUser.email}, dan ${technicianUser.email}.`);
}

async function syncTemplate(positionId: string, name: string, indicators: Array<{
  code: string;
  name: string;
  description: string;
  kind: "NUMERIC" | "RATING";
  unit: string;
  aggregation: "SUM" | "AVERAGE";
  direction: "HIGHER" | "LOWER" | "ZERO_TOLERANCE";
  target: number;
  failureLimit: number | null;
  weight: number;
  sortOrder: number;
}>) {
  const template = await prisma.kpiTemplate.findUnique({ where: { positionId } }) ?? await prisma.kpiTemplate.create({ data: { positionId, name } });
  if (await prisma.kpiTemplateVersion.count({ where: { templateId: template.id } })) return;
  await prisma.kpiTemplateVersion.create({
    data: {
      templateId: template.id,
      versionNumber: 1,
      status: "ACTIVE",
      activatedAt: new Date(),
      indicators: { create: indicators },
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
