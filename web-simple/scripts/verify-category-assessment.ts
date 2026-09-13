import "dotenv/config";

import assert from "node:assert/strict";
import { PrismaPg } from "@prisma/adapter-pg";
import { PrismaClient, type KpiDirection, type ValueKind } from "../src/generated/prisma/client.ts";
import type { AccessProfile } from "../src/modules/access/policy.ts";
import { syncMasterKpiTemplates } from "../src/modules/admin/master-kpi-sync.ts";
import { calculateMonthlyIndicator } from "../src/modules/kpi/calculation.ts";
import { categoryOptionsFromSnapshot } from "../src/modules/kpi/category-options.ts";
import { reviewDailySheet, saveDailySheet, type DailyValueInput } from "../src/modules/kpi/daily-operations.ts";
import { createPeriod, openPeriod } from "../src/modules/kpi/period-operations.ts";

const connectionString = process.env.DATABASE_URL;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");
const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });
const rollback = new Error("ROLLBACK_CATEGORY_VERIFICATION");

// Nilai aman untuk indikator manual non-kategori: diturunkan dari bentuk item agar tidak
// ditolak validasi (RATING 1-5, ZERO_TOLERANCE aman di 0, satuan "%" maksimal 100).
function safeIndicatorValue(item: {
  kindSnapshot: ValueKind;
  unitSnapshot: string;
  directionSnapshot: KpiDirection;
  targetSnapshot: { toNumber(): number };
}): number {
  if (item.kindSnapshot === "RATING") return 4;
  if (item.directionSnapshot === "ZERO_TOLERANCE") return 0;
  if (item.directionSnapshot === "LOWER") return Math.max(0, item.targetSnapshot.toNumber());
  if (item.unitSnapshot === "%") return 90;
  return Math.max(1, item.targetSnapshot.toNumber());
}

try {
  await prisma.$transaction(async (tx) => {
    const supervisorUser = await tx.user.findUniqueOrThrow({ where: { username: "supervisor" }, include: { employee: true } });
    const managerUser = await tx.user.findUniqueOrThrow({ where: { username: "manager" }, include: { employee: true } });
    const adminUser = await tx.user.findUniqueOrThrow({ where: { username: "admin" } });
    const supervisor: AccessProfile = { userId: supervisorUser.id, role: "SUPERVISOR", employeeId: supervisorUser.employee!.id, branchId: supervisorUser.employee!.branchId, active: true };
    const manager: AccessProfile = { userId: managerUser.id, role: "MANAGER", employeeId: managerUser.employee!.id, branchId: managerUser.employee!.branchId, active: true };
    const admin: AccessProfile = { userId: adminUser.id, role: "ADMIN", employeeId: null, branchId: null, active: true };

    // Katalog baku (indikator CATEGORY + opsinya) disinkronkan DI DALAM transaksi yang
    // di-rollback, mengikuti langkah rencana "indikator CATEGORY + opsi → aktifkan versi".
    const sync = await syncMasterKpiTemplates(tx, admin);
    assert.ok(sync.updated.length + sync.skipped.length > 0, "Sinkronisasi katalog KPI harus menghasilkan versi.");

    // Katalog bersama: kesembilan indikator kualitatif dikonversi ke CATEGORY dan mewarisi skala bersama.
    const categoryIndicators = await tx.kpiIndicator.findMany({
      where: { kind: "CATEGORY" },
      select: { code: true, categoryOptions: { where: { isActive: true }, select: { label: true, threshold: true } } },
    });
    const categoryCodes = new Set(categoryIndicators.map((indicator) => indicator.code));
    for (const code of ["TEK-05", "TEK-06", "ADM-06", "KSR-05", "KSR-06", "GUD-06", "GUD-07", "SUP-03", "SUP-06"]) {
      assert.ok(categoryCodes.has(code), `Katalog harus memuat indikator kategori ${code}.`);
    }
    assert.ok(categoryIndicators.every((indicator) => indicator.categoryOptions.length >= 1), "Setiap indikator kategori wajib memiliki opsi aktif.");
    // Default baru memakai empat batas: 60, 70, 80, 90.
    const scaleOptions = new Set(categoryIndicators.flatMap((indicator) => indicator.categoryOptions).filter((option) => option.threshold !== null).map((option) => `${option.label}=${option.threshold!.toFixed(2)}`));
    for (const expected of ["Sangat Baik=60.00", "Baik=70.00", "Cukup=80.00", "Kurang=90.00"]) {
      assert.ok(scaleOptions.has(expected), `Skala kategori harus memuat ${expected}.`);
    }

    const occupied = new Set((await tx.kpiPeriod.findMany({ where: { year: { gte: 2090 } }, select: { year: true, month: true } })).map((item) => `${item.year}-${item.month}`));
    let slot: { year: number; month: number } | undefined;
    for (let year = 2090; year <= 2100 && !slot; year += 1) {
      for (let month = 1; month <= 12; month += 1) {
        if (!occupied.has(`${year}-${month}`)) { slot = { year, month }; break; }
      }
    }
    if (!slot) throw new Error("Tidak ada slot periode verifikasi yang tersedia.");
    const now = new Date(Date.UTC(slot.year, slot.month - 1, 9, 8));
    const day9 = new Date(Date.UTC(slot.year, slot.month - 1, 9));
    const day10 = new Date(Date.UTC(slot.year, slot.month - 1, 10));
    const nowDay10 = new Date(Date.UTC(slot.year, slot.month - 1, 10, 8));

    const period = await createPeriod(tx, admin, slot);
    await openPeriod(tx, admin, period.id, now);

    // LANGKAH 3 — KPI pegawai KASIR (fallback TEKNISI) yang memuat item CATEGORY.
    const kpi = await tx.monthlyKpi.findFirst({
      where: { periodId: period.id, positionCodeSnapshot: "KASIR", items: { some: { kindSnapshot: "CATEGORY" } } },
      include: { items: { orderBy: { sortOrderSnapshot: "asc" } }, dailySheets: { where: { entryDate: day9 } } },
    }) ?? await tx.monthlyKpi.findFirstOrThrow({
      where: { periodId: period.id, positionCodeSnapshot: "TEKNISI", items: { some: { kindSnapshot: "CATEGORY" } } },
      include: { items: { orderBy: { sortOrderSnapshot: "asc" } }, dailySheets: { where: { entryDate: day9 } } },
    });

    const categoryCode = kpi.positionCodeSnapshot === "KASIR" ? "KSR-05" : "TEK-05";
    const categoryItem = kpi.items.find((item) => item.codeSnapshot === categoryCode);
    assert.ok(categoryItem, `Indikator ${categoryCode} harus tersedia.`);
    assert.equal(categoryItem.kindSnapshot, "CATEGORY", `Indikator ${categoryCode} harus bertipe CATEGORY.`);

    const options = categoryOptionsFromSnapshot(categoryItem.categoryOptionsSnapshot);
    const baik = options.find((option) => option.label === "Baik");
    assert.ok(baik, "Opsi kategori 'Baik' harus tersedia pada snapshot.");
    assert.equal("threshold" in baik ? baik.threshold : null, "70", "Snapshot menyimpan batas Baik = 70.");

    assert.equal(kpi.dailySheets.length, 1, "Lembar harian tanggal 9 harus tersedia.");
    const sheet = kpi.dailySheets[0];

    const manualItems = kpi.items.filter((item) => item.kindSnapshot !== "CATEGORY");
    const itemX = manualItems[0];
    const itemY = manualItems[1];
    assert.ok(itemX, "Indikator manual pertama harus tersedia.");
    assert.ok(itemY, "Indikator manual kedua harus tersedia.");

    const buildValues = (overrides: ReadonlyMap<string, DailyValueInput> = new Map<string, DailyValueInput>()): DailyValueInput[] =>
      kpi.items.map((item) => overrides.get(item.id) ?? (
        item.kindSnapshot === "CATEGORY"
          ? { itemId: item.id, status: "AVAILABLE", value: 89 }
          : { itemId: item.id, status: "AVAILABLE", value: safeIndicatorValue(item) }
      ));

    // LANGKAH 4 — menyimpan draf: CATEGORY mengirim angka mentah.
    const draft = await saveDailySheet(tx, supervisor, {
      sheetId: sheet.id,
      rowVersion: sheet.rowVersion,
      workStatus: "WORKED",
      note: "Penilaian harian lengkap.",
      values: buildValues(),
      submit: false,
    }, now);
    assert.equal(draft.status, "DRAFT");
    const storedDraft = await tx.dailyValue.findUniqueOrThrow({ where: { dailySheetId_monthlyKpiItemId: { dailySheetId: sheet.id, monthlyKpiItemId: categoryItem.id } } });
    assert.equal(storedDraft.categoryOptionId, null, "Kategori baru tidak menyimpan referensi opsi legacy.");
    assert.equal(storedDraft.enteredValue?.toNumber(), 89, "Nilai mentah kategori disimpan apa adanya.");
    assert.equal(storedDraft.status, "AVAILABLE");

    // LANGKAH 6 — angka kategori tetap melewati validasi satuan indikator.
    await assert.rejects(
      saveDailySheet(tx, supervisor, {
        sheetId: sheet.id,
        rowVersion: draft.rowVersion,
        workStatus: "WORKED",
        values: buildValues(new Map<string, DailyValueInput>([[categoryItem.id, { itemId: categoryItem.id, status: "AVAILABLE", value: -1 }]])),
        submit: false,
      }, now),
      /tidak valid/i,
    );

    // LANGKAH 5 — klien mengirim angka bersama id legacy; server tetap memakai angka.
    // Dijalankan sebelum submit agar lembar masih berada pada status yang dapat diubah.
    const fake = await saveDailySheet(tx, supervisor, {
      sheetId: sheet.id,
      rowVersion: draft.rowVersion,
      workStatus: "WORKED",
      values: buildValues(new Map<string, DailyValueInput>([[categoryItem.id, { itemId: categoryItem.id, status: "AVAILABLE", categoryOptionId: baik.id, value: 100 }]])),
      submit: false,
    }, now);
    assert.equal(fake.status, "DRAFT");
    const storedFake = await tx.dailyValue.findUniqueOrThrow({ where: { dailySheetId_monthlyKpiItemId: { dailySheetId: sheet.id, monthlyKpiItemId: categoryItem.id } } });
    assert.equal(storedFake.enteredValue?.toNumber(), 100, "Nilai angka kategori disimpan apa adanya.");
    assert.equal(storedFake.categoryOptionId, null);

    // LANGKAH 7 — submit tertahan selama masih ada item manual berstatus PENDING.
    await assert.rejects(
      saveDailySheet(tx, supervisor, {
        sheetId: sheet.id,
        rowVersion: fake.rowVersion,
        workStatus: "WORKED",
        values: buildValues(new Map<string, DailyValueInput>([[itemX.id, { itemId: itemX.id, status: "PENDING" }]])),
        submit: true,
      }, now),
      /Seluruh indikator wajib diisi untuk hari bekerja/i,
    );

    // LANGKAH 4 (lanjutan) — submit sah. Item Y sengaja NOT_APPLICABLE agar LANGKAH 8 terbukti.
    const submitted = await saveDailySheet(tx, supervisor, {
      sheetId: sheet.id,
      rowVersion: fake.rowVersion,
      workStatus: "WORKED",
      note: "Penilaian harian lengkap.",
      values: buildValues(new Map<string, DailyValueInput>([[itemY.id, { itemId: itemY.id, status: "NOT_APPLICABLE" }]])),
      submit: true,
    }, now);
    assert.equal(submitted.status, "SUBMITTED");
    const storedSubmitted = await tx.dailyValue.findUniqueOrThrow({ where: { dailySheetId_monthlyKpiItemId: { dailySheetId: sheet.id, monthlyKpiItemId: categoryItem.id } } });
    assert.equal(storedSubmitted.enteredValue?.toNumber(), 89);
    assert.equal(storedSubmitted.categoryOptionId, null);

    // LANGKAH 9 — Manager menyetujui; agregasi bulanan memakai rumus numerik biasa.
    const approved = await reviewDailySheet(tx, manager, { sheetId: submitted.sheetId, rowVersion: submitted.rowVersion, decision: "APPROVE" }, now);
    assert.equal(approved.status, "APPROVED");

    const categoryResult = await tx.monthlyKpiItem.findUniqueOrThrow({ where: { id: categoryItem.id } });
    assert.equal(categoryResult.calculationStatus, "CALCULATED");
    assert.equal(categoryResult.actual?.toFixed(2), "89.00");
    const expectedAchievement = Math.min(100, (89 * 100) / categoryResult.targetSnapshot.toNumber()).toFixed(2);
    assert.equal(categoryResult.achievementPercentage?.toFixed(2), expectedAchievement);
    const expectedWeighted = ((Number(expectedAchievement) * categoryResult.weightSnapshot.toNumber()) / 100).toFixed(2);
    assert.equal(categoryResult.weightedScore?.toFixed(2), expectedWeighted);
    const storedApproved = await tx.dailyValue.findUniqueOrThrow({ where: { dailySheetId_monthlyKpiItemId: { dailySheetId: sheet.id, monthlyKpiItemId: categoryItem.id } } });
    assert.equal(storedApproved.enteredValue?.toNumber(), 89);
    assert.equal(storedApproved.effectiveValue?.toNumber(), 89);
    assert.equal(storedApproved.categoryOptionId, null);

    // LANGKAH 8 — item Y hanya punya nilai NOT_APPLICABLE: bukan 0, bukan CALCULATED.
    const onlyNotApplicable = await tx.monthlyKpiItem.findUniqueOrThrow({ where: { id: itemY.id } });
    assert.equal(onlyNotApplicable.actual, null, "NOT_APPLICABLE tidak boleh menjadi 0.");
    assert.equal(onlyNotApplicable.calculationStatus, "PENDING");

    // LANGKAH 8 — item X sebelumnya AVAILABLE; simpan NOT_APPLICABLE pada hari lain lalu setujui.
    const beforeNotApplicable = await tx.monthlyKpiItem.findUniqueOrThrow({ where: { id: itemX.id } });
    assert.equal(beforeNotApplicable.calculationStatus, "CALCULATED");
    const xValue = safeIndicatorValue(itemX);
    // AVERAGE/SUM/LATEST satu nilai menghasilkan actual = nilai itu; COUNT = 1.
    const expectedXActual = itemX.aggregationSnapshot === "COUNT" ? "1.00" : xValue.toFixed(2);
    assert.equal(beforeNotApplicable.actual?.toFixed(2), expectedXActual);

    const sheet10 = await tx.dailySheet.findUniqueOrThrow({ where: { monthlyKpiId_entryDate: { monthlyKpiId: kpi.id, entryDate: day10 } } });
    const submitted10 = await saveDailySheet(tx, supervisor, {
      sheetId: sheet10.id,
      rowVersion: sheet10.rowVersion,
      workStatus: "WORKED",
      note: "Pemeriksaan lanjutan.",
      values: buildValues(new Map<string, DailyValueInput>([
        [itemX.id, { itemId: itemX.id, status: "NOT_APPLICABLE" }],
        [itemY.id, { itemId: itemY.id, status: "NOT_APPLICABLE" }],
      ])),
      submit: true,
    }, nowDay10);
    assert.equal(submitted10.status, "SUBMITTED");
    const approved10 = await reviewDailySheet(tx, manager, { sheetId: submitted10.sheetId, rowVersion: submitted10.rowVersion, decision: "APPROVE" }, nowDay10);
    assert.equal(approved10.status, "APPROVED");

    const afterNotApplicable = await tx.monthlyKpiItem.findUniqueOrThrow({ where: { id: itemX.id } });
    assert.equal(afterNotApplicable.actual?.toFixed(2), expectedXActual, "Hari NOT_APPLICABLE tidak boleh menyumbang 0.");
    assert.equal(afterNotApplicable.calculationStatus, "CALCULATED");
    const stillPending = await tx.monthlyKpiItem.findUniqueOrThrow({ where: { id: itemY.id } });
    assert.equal(stillPending.actual, null);
    assert.equal(stillPending.calculationStatus, "PENDING");

    // LANGKAH 10 — data lama tetap terbaca dan terhitung.
    // mean = (4+5+3)/3 = 4 → achievement = 4/4×100 = 100 → weightedScore = 100×25/100 = 25.00.
    const legacyRating = calculateMonthlyIndicator({ kind: "RATING", aggregation: "AVERAGE", direction: "HIGHER", values: [4, 5, 3], target: 4, failureLimit: null, weight: 25 });
    assert.equal(legacyRating.status, "CALCULATED");
    assert.equal(legacyRating.actual, "4.00");
    assert.equal(legacyRating.achievement, "100.00");
    assert.equal(legacyRating.weightedScore, "25.00");

    const legacyNumericValue = await tx.dailyValue.findFirstOrThrow({ where: { monthlyKpiItemId: itemX.id, dailySheetId: sheet.id } });
    assert.equal(legacyNumericValue.categoryOptionId, null, "Baris lama bergaya numerik tetap terbaca.");
    assert.equal(legacyNumericValue.enteredValue?.toNumber(), xValue);

    throw rollback;
  });
} catch (error) {
  if (error !== rollback) throw error;
} finally {
  await prisma.$disconnect();
}

console.info("Verifikasi penilaian berbasis kategori lulus dan seluruh perubahan uji di-rollback.");
