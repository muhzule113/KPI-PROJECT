import "dotenv/config";
import { PrismaPg } from "@prisma/adapter-pg";
import { hashPassword } from "better-auth/crypto";
import { PrismaClient } from "../src/generated/prisma/client";
import { DEFAULT_CASHIER_MAPPING } from "../src/modules/imports/mapping";

const databaseUrl = process.env.DATABASE_URL;
const adminName = process.env.SEED_ADMIN_NAME ?? "Administrator KPI";
const adminEmail = process.env.SEED_ADMIN_EMAIL?.trim().toLowerCase();
const adminPassword = process.env.SEED_ADMIN_PASSWORD;

if (!databaseUrl) throw new Error("DATABASE_URL wajib diisi.");
if (!adminEmail) throw new Error("SEED_ADMIN_EMAIL wajib diisi.");
if (!adminPassword || adminPassword.length < 10) throw new Error("SEED_ADMIN_PASSWORD wajib berisi minimal 10 karakter.");

const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString: databaseUrl }) });

const positions = [
  ["POS-OWN", "Owner / Manajer"],
  ["POS-EXEC", "Manajer Eksekutif"],
  ["POS-SPV", "Supervisor"],
  ["POS-CS", "Customer Service"],
  ["POS-TEK", "Teknisi"],
  ["POS-ADM", "Admin"],
  ["POS-KSR", "Kasir"],
  ["POS-GUD", "Gudang"],
] as const;

try {
  await prisma.branch.upsert({
    where: { code: "PUSAT" },
    update: {},
    create: { code: "PUSAT", name: "Cabang Pusat" },
  });
  for (const [code, name] of positions) {
    await prisma.position.upsert({ where: { code }, update: { name }, create: { code, name } });
  }
  const user = await prisma.user.upsert({
    where: { email: adminEmail },
    update: { name: adminName, role: "super_admin", isActive: true },
    create: { name: adminName, email: adminEmail, emailVerified: true, role: "super_admin" },
  });
  const password = await hashPassword(adminPassword);
  await prisma.account.deleteMany({
    where: { userId: user.id, providerId: "credential", accountId: { not: user.id } },
  });
  await prisma.account.upsert({
    where: { providerId_accountId: { providerId: "credential", accountId: user.id } },
    update: { userId: user.id, password },
    create: { userId: user.id, providerId: "credential", accountId: user.id, password },
  });
  const mappingTemplate = await prisma.importMappingTemplate.findFirst({ where: { name: "POS standar", sourceApplication: "POS_SYSTEM" } })
    ?? await prisma.importMappingTemplate.create({ data: { name: "POS standar", sourceApplication: "POS_SYSTEM", description: "Header baku laporan kasir." } });
  await prisma.importMappingVersion.upsert({
    where: { mappingTemplateId_versionNumber: { mappingTemplateId: mappingTemplate.id, versionNumber: 1 } },
    update: { mappingsJson: DEFAULT_CASHIER_MAPPING, isActive: true },
    create: { mappingTemplateId: mappingTemplate.id, versionNumber: 1, mappingsJson: DEFAULT_CASHIER_MAPPING, isActive: true },
  });
  console.info(`Seed selesai. Login admin: ${adminEmail}`);
} finally {
  await prisma.$disconnect();
}
