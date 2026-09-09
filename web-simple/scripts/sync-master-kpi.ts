import "dotenv/config";

import { PrismaPg } from "@prisma/adapter-pg";
import { PrismaClient } from "../src/generated/prisma/client.ts";
import type { AccessProfile } from "../src/modules/access/policy.ts";
import { syncMasterKpiTemplates } from "../src/modules/admin/master-kpi-sync.ts";

const connectionString = process.env.DATABASE_URL;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");

const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });

try {
  const adminUser = await prisma.user.findUniqueOrThrow({ where: { username: "admin" } });
  const admin: AccessProfile = { userId: adminUser.id, role: adminUser.role, employeeId: null, branchId: null, active: adminUser.isActive };
  const result = await prisma.$transaction(
    (tx) => syncMasterKpiTemplates(tx, admin),
    { isolationLevel: "Serializable", maxWait: 10_000, timeout: 120_000 },
  );
  for (const item of result.updated) console.info(`${item.positionCode}: v${item.fromVersion} → v${item.toVersion}`);
  for (const item of result.skipped) console.info(`${item.positionCode}: v${item.version} sudah sesuai`);
  console.info(`Sinkronisasi selesai: ${result.updated.length} diperbarui, ${result.skipped.length} dilewati.`);
} finally {
  await prisma.$disconnect();
}
