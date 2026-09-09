import "dotenv/config";

import assert from "node:assert/strict";
import { spawnSync } from "node:child_process";
import { PrismaPg } from "@prisma/adapter-pg";
import { PrismaClient } from "../src/generated/prisma/client.ts";

const connectionString = process.env.DATABASE_URL;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");
const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });

async function fingerprint() {
  return JSON.stringify(await Promise.all([
    prisma.branch.findMany({ orderBy: { id: "asc" } }),
    prisma.position.findMany({ orderBy: { id: "asc" } }),
    prisma.user.findMany({ orderBy: { id: "asc" }, select: { id: true, name: true, email: true, role: true, isActive: true, updatedAt: true } }),
    prisma.employee.findMany({ orderBy: { id: "asc" } }),
    prisma.kpiPeriod.findMany({ orderBy: [{ year: "asc" }, { month: "asc" }] }),
    prisma.kpiTemplate.findMany({ orderBy: { id: "asc" }, include: { versions: { orderBy: { versionNumber: "asc" }, include: { indicators: { orderBy: { id: "asc" } } } } } }),
    prisma.kpiRatingScheme.findMany({ orderBy: { version: "asc" }, include: { bands: { orderBy: { sortOrder: "asc" } } } }),
  ]));
}

try {
  const before = await fingerprint();
  const run = spawnSync(process.execPath, ["--import", "./scripts/node-userinfo-workaround.mjs", "--import", "tsx", "prisma/seed.ts"], { cwd: process.cwd(), encoding: "utf8" });
  if (run.status !== 0) throw new Error(run.stderr || run.stdout || "Seeder gagal dijalankan.");
  assert.equal(await fingerprint(), before, "Seeder mengubah konfigurasi yang sudah ada.");
} finally {
  await prisma.$disconnect();
}

console.info("Verifikasi seed idempoten lulus; konfigurasi yang sudah ada tidak berubah.");
