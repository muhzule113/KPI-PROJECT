import "dotenv/config";

import { randomUUID } from "node:crypto";
import { PrismaPg } from "@prisma/adapter-pg";
import { hashPassword } from "better-auth/crypto";
import { PrismaClient } from "../src/generated/prisma/client.ts";

const username = "qa.smoke.kpi";
const password = "KpiSmoke-2026!temporary";
const connectionString = process.env.DATABASE_URL;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");

const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });
try {
  if (process.argv[2] === "create") {
    if (await prisma.user.findUnique({ where: { username } })) throw new Error("Akun QA sementara sudah ada.");
    const user = await prisma.user.create({ data: { username, email: `${randomUUID()}@smoke.kpi.invalid`, name: "QA Smoke KPI", role: "ADMIN", isActive: true, emailVerified: true } });
    await prisma.account.create({ data: { userId: user.id, providerId: "credential", accountId: user.id, password: await hashPassword(password) } });
    console.info(username);
  } else if (process.argv[2] === "delete") {
    const user = await prisma.user.findUnique({ where: { username } });
    if (user && user.name === "QA Smoke KPI" && user.email.endsWith("@smoke.kpi.invalid")) await prisma.user.delete({ where: { id: user.id } });
    console.info("Akun QA sementara dibersihkan.");
  } else {
    throw new Error("Gunakan create atau delete.");
  }
} finally {
  await prisma.$disconnect();
}
