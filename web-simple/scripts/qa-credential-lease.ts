import "dotenv/config";

import { PrismaPg } from "@prisma/adapter-pg";
import { hashPassword } from "better-auth/crypto";
import { PrismaClient } from "../src/generated/prisma/client.ts";

const connectionString = process.env.DATABASE_URL;
const username = process.argv[2];
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");
if (!username) throw new Error("Username wajib diisi.");

const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });
const account = await prisma.account.findFirst({
  where: { providerId: "credential", user: { username } },
  select: { id: true, password: true },
});
if (!account?.password) throw new Error(`Credential ${username} tidak ditemukan.`);

try {
  await prisma.account.update({
    where: { id: account.id },
    data: { password: await hashPassword("KpiRoleSmoke-2026!temporary") },
  });
  console.info(`READY:${username}`);
  await new Promise<void>((resolve) => process.stdin.once("data", resolve));
} finally {
  await prisma.account.update({ where: { id: account.id }, data: { password: account.password } });
  await prisma.$disconnect();
}

console.info(`RESTORED:${username}`);
