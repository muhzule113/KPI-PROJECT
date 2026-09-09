import "dotenv/config";

import assert from "node:assert/strict";
import { PrismaPg } from "@prisma/adapter-pg";
import { PrismaClient } from "../src/generated/prisma/client.ts";
import { AUGUST_2026_ROSTER, AUGUST_WORKED_DAYS } from "../src/modules/kpi/august-2026-fixture.ts";

const connectionString = process.env.DATABASE_URL;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");
const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });

try {
  const users = await prisma.user.findMany({
    where: { email: { in: AUGUST_2026_ROSTER.map((employee) => employee.email) } },
    include: { accounts: true, employee: { include: { position: true } } },
  });
  assert.equal(users.length, AUGUST_2026_ROSTER.length, "Seluruh user roster harus tersedia.");
  for (const expected of AUGUST_2026_ROSTER) {
    const user = users.find((candidate) => candidate.email === expected.email);
    assert.ok(user?.isActive && user.role === "EMPLOYEE", `${expected.name} harus menjadi user EMPLOYEE aktif.`);
    assert.equal(user.employee?.employeeNumber, expected.employeeNumber);
    assert.equal(user.employee?.position.code, expected.positionCode);
    assert.ok(user.accounts.some((account) => account.providerId === "credential" && account.password), `${expected.name} harus dapat login dengan credential.`);
  }

  const period = await prisma.kpiPeriod.findUniqueOrThrow({ where: { year_month: { year: 2026, month: 8 } } });
  assert.equal(period.status, "COMPLETED", "Periode Agustus harus selesai setelah seluruh hasil difinalkan.");
  const periodResultCount = await prisma.monthlyKpi.count({ where: { periodId: period.id } });
  const results = await prisma.monthlyKpi.findMany({
    where: { periodId: period.id, employee: { user: { email: { in: AUGUST_2026_ROSTER.map((employee) => employee.email) } } } },
    include: { employee: { include: { user: true } }, items: true, dailySheets: { include: { values: true } } },
  });
  assert.equal(results.length, AUGUST_2026_ROSTER.length, "Setiap user roster harus memiliki satu KPI bulanan.");

  for (const result of results) {
    assert.equal(result.dailySheets.length, 31, `${result.employeeNameSnapshot} harus memiliki lembar untuk seluruh Agustus.`);
    assert.equal(result.dailySheets.filter((sheet) => sheet.effectiveWorkStatus === "WORKED").length, AUGUST_WORKED_DAYS);
    assert.equal(result.dailySheets.filter((sheet) => sheet.status === "APPROVED").length, 31);
    assert.equal(result.dailySheets.find((sheet) => sheet.entryDate.getUTCDate() === 31)?.effectiveWorkStatus, "OFF");
    assert.equal(result.dailySheets.reduce((total, sheet) => total + sheet.values.length, 0), AUGUST_WORKED_DAYS * result.items.length);
    assert.ok(result.items.every((item) => item.calculationStatus === "CALCULATED"));
    assert.equal(result.status, "FINALIZED");
    assert.notEqual(result.finalScore, null);
    assert.ok(result.ratingLabel);
  }
  assert.ok(new Set(results.map((result) => result.finalScore?.toFixed(2))).size >= 5, "Nilai akhir harus bervariasi untuk menguji seluruh band performa.");

  console.table([...results]
    .sort((left, right) => AUGUST_2026_ROSTER.findIndex((employee) => employee.email === left.employee.user.email) - AUGUST_2026_ROSTER.findIndex((employee) => employee.email === right.employee.user.email))
    .map((result) => ({
      nomor: result.employeeNumberSnapshot,
      nama: result.employeeNameSnapshot,
      jabatan: result.positionNameSnapshot,
      nilai: result.finalScore?.toFixed(2),
      predikat: result.ratingLabel,
    })));
  console.info(`Fixture Agustus 2026 terverifikasi: ${users.length} user roster, ${results.length * AUGUST_WORKED_DAYS} hari bekerja roster, ${results.length} hasil roster final, ${periodResultCount} hasil total periode.`);
} finally {
  await prisma.$disconnect();
}
