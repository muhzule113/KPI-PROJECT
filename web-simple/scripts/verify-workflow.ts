import "dotenv/config";

import assert from "node:assert/strict";
import { PrismaPg } from "@prisma/adapter-pg";
import { PrismaClient } from "../src/generated/prisma/client.ts";
import type { AccessProfile } from "../src/modules/access/policy.ts";
import { reviewDailySheet, saveDailySheet } from "../src/modules/kpi/daily-operations.ts";
import { finalizeMonthlyKpi } from "../src/modules/kpi/monthly-operations.ts";
import { createPeriod, openPeriod } from "../src/modules/kpi/period-operations.ts";

const connectionString = process.env.DATABASE_URL;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");
const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });
const rollback = new Error("ROLLBACK_VERIFICATION");

try {
  await prisma.$transaction(async (tx) => {
    const supervisorUser = await tx.user.findUniqueOrThrow({ where: { email: "supervisor@kpi-simple.local" }, include: { employee: true } });
    const managerUser = await tx.user.findUniqueOrThrow({ where: { email: "manager@kpi-simple.local" }, include: { employee: true } });
    const adminUser = await tx.user.findUniqueOrThrow({ where: { email: "admin@kpi-simple.local" } });
    const supervisor: AccessProfile = { userId: supervisorUser.id, role: "SUPERVISOR", employeeId: supervisorUser.employee!.id, branchId: supervisorUser.employee!.branchId, active: true };
    const manager: AccessProfile = { userId: managerUser.id, role: "MANAGER", employeeId: managerUser.employee!.id, branchId: managerUser.employee!.branchId, active: true };
    const admin: AccessProfile = { userId: adminUser.id, role: "ADMIN", employeeId: null, branchId: null, active: true };
    const occupied = new Set((await tx.kpiPeriod.findMany({ where: { year: { gte: 2090 } }, select: { year: true, month: true } })).map((item) => `${item.year}-${item.month}`));
    let slot: { year: number; month: number } | undefined;
    for (let year = 2090; year <= 2100 && !slot; year += 1) {
      for (let month = 1; month <= 12; month += 1) {
        if (!occupied.has(`${year}-${month}`)) { slot = { year, month }; break; }
      }
    }
    if (!slot) throw new Error("Tidak ada slot periode verifikasi yang tersedia.");
    const now = new Date(Date.UTC(slot.year, slot.month - 1, 9, 8));
    const period = await createPeriod(tx, admin, slot);
    await openPeriod(tx, admin, period.id, now);

    const staffKpi = await tx.monthlyKpi.findFirstOrThrow({
      where: { periodId: period.id, positionCodeSnapshot: "PELAYAN" },
      include: { items: { orderBy: { sortOrderSnapshot: "asc" } }, dailySheets: { where: { entryDate: new Date(Date.UTC(slot.year, slot.month - 1, 9)) } } },
    });
    const staffValues = staffKpi.items.map((item) => ({ itemId: item.id, value: item.kindSnapshot === "RATING" ? (item.codeSnapshot === "KUALITAS" ? 4 : 5) : 5 }));
    const submitted = await saveDailySheet(tx, supervisor, { sheetId: staffKpi.dailySheets[0].id, rowVersion: staffKpi.dailySheets[0].rowVersion, workStatus: "WORKED", values: staffValues, submit: true, note: "Pelayanan harian selesai." }, now);
    assert.equal(submitted.status, "SUBMITTED");

    const approved = await reviewDailySheet(tx, manager, { sheetId: submitted.sheetId, rowVersion: submitted.rowVersion, decision: "APPROVE" }, now);
    assert.equal(approved.status, "APPROVED");
    const serviceItem = await tx.monthlyKpiItem.findFirstOrThrow({ where: { monthlyKpiId: staffKpi.id, codeSnapshot: "LAYANAN" } });
    assert.equal(serviceItem.actual?.toFixed(2), "5.00");

    const corrected = await reviewDailySheet(tx, manager, {
      sheetId: approved.sheetId,
      rowVersion: approved.rowVersion,
      decision: "CORRECT",
      workStatus: "WORKED",
      values: staffValues.map((value) => value.itemId === serviceItem.id ? { ...value, value: 6 } : value),
      reason: "Jumlah layanan disesuaikan dengan rekap tutup toko.",
    }, now);
    assert.equal(corrected.status, "APPROVED");
    const correctedValue = await tx.dailyValue.findUniqueOrThrow({ where: { dailySheetId_monthlyKpiItemId: { dailySheetId: corrected.sheetId, monthlyKpiItemId: serviceItem.id } } });
    assert.equal(correctedValue.enteredValue?.toNumber(), 5);
    assert.equal(correctedValue.managerValue?.toNumber(), 6);
    assert.equal(correctedValue.effectiveValue?.toNumber(), 6);

    const supervisorKpi = await tx.monthlyKpi.findFirstOrThrow({
      where: { periodId: period.id, positionCodeSnapshot: "SPV" },
      include: { items: { orderBy: { sortOrderSnapshot: "asc" } }, dailySheets: { where: { entryDate: new Date(Date.UTC(slot.year, slot.month - 1, 9)) } } },
    });
    const managerEntry = await saveDailySheet(tx, manager, {
      sheetId: supervisorKpi.dailySheets[0].id,
      rowVersion: supervisorKpi.dailySheets[0].rowVersion,
      workStatus: "WORKED",
      values: supervisorKpi.items.map((item) => ({ itemId: item.id, value: item.kindSnapshot === "RATING" ? 4 : 1 })),
      submit: true,
      note: "Kontrol dan coaching terlaksana.",
    }, now);
    assert.equal(managerEntry.status, "APPROVED");

    const freshKpi = await tx.monthlyKpi.findUniqueOrThrow({ where: { id: staffKpi.id } });
    await assert.rejects(
      finalizeMonthlyKpi(tx, manager, { monthlyKpiId: freshKpi.id, rowVersion: freshKpi.rowVersion }, new Date(Date.UTC(slot.year, slot.month, 1))),
      /belum disetujui/i,
    );
    throw rollback;
  });
} catch (error) {
  if (error !== rollback) throw error;
} finally {
  await prisma.$disconnect();
}

console.info("Verifikasi workflow database lulus dan seluruh perubahan uji di-rollback.");
