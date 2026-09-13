import "dotenv/config";

import assert from "node:assert/strict";
import { randomUUID } from "node:crypto";
import { PrismaPg } from "@prisma/adapter-pg";
import { hashPassword } from "better-auth/crypto";
import { PrismaClient, type Prisma } from "../src/generated/prisma/client.ts";
import type { AccessProfile } from "../src/modules/access/policy.ts";
import {
  AUGUST_2026_ROSTER,
  AUGUST_WORKED_DAYS,
  FIXTURE_TEMPLATES,
  fixtureCategoryOption,
  fixtureValuesForIndicator,
  type FixtureIndicator,
} from "../src/modules/kpi/august-2026-fixture.ts";
import { categoryOptionsFromSnapshot } from "../src/modules/kpi/category-options.ts";
import { indicatorCreateInput, KPI_CATEGORY_SCALE } from "../src/modules/kpi/master-kpi-templates.ts";
import { finalizeMonthlyKpi, recalculateMonthlyKpi } from "../src/modules/kpi/monthly-operations.ts";
import { createPeriod, openPeriod } from "../src/modules/kpi/period-operations.ts";

const connectionString = process.env.DATABASE_URL;
const demoPassword = process.env.SEED_DEMO_PASSWORD;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");
if (!demoPassword || demoPassword.length < 10) throw new Error("SEED_DEMO_PASSWORD minimal 10 karakter.");

const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });
const passwordHash = await hashPassword(demoPassword);
const completedAt = new Date("2026-09-09T08:00:00.000Z");

const POSITION_NAMES = {
  CREW: "Crew",
  KURIR: "Kurir",
} as const;

try {
  const outcome = await prisma.$transaction(async (tx) => {
    const existingPeriod = await tx.kpiPeriod.findUnique({ where: { year_month: { year: 2026, month: 8 } } });
    const marker = existingPeriod && await tx.auditEvent.findFirst({
      where: { action: "seed_august_2026_fixture", subjectType: "KpiPeriod", subjectId: existingPeriod.id },
    });
    if (marker) return { created: false, periodId: existingPeriod.id };
    if (existingPeriod && (existingPeriod.status !== "DRAFT" || await tx.monthlyKpi.count({ where: { periodId: existingPeriod.id } }) > 0)) {
      throw new Error("Periode Agustus 2026 sudah berisi data non-fixture. Seeder dihentikan agar data tidak tertimpa.");
    }

    const branch = await tx.branch.findUniqueOrThrow({ where: { code: "PUSAT" } });
    const adminUser = await tx.user.findUniqueOrThrow({ where: { username: "admin" } });
    const managerUser = await tx.user.findUniqueOrThrow({ where: { username: "manager" }, include: { employee: true } });
    const supervisorUser = await tx.user.findUniqueOrThrow({ where: { username: "supervisor" }, include: { employee: true } });
    assert.ok(managerUser.employee && supervisorUser.employee, "Jalankan npm run db:seed sebelum seeder Agustus.");

    const positions = new Map((await tx.position.findMany()).map((position) => [position.code, position]));
    for (const [code, name] of Object.entries(POSITION_NAMES)) {
      const position = await tx.position.upsert({
        where: { code },
        update: { name, isActive: true, isKpiSubject: true },
        create: { code, name, isActive: true, isKpiSubject: true },
      });
      positions.set(code, position);
      await ensureTemplate(tx, position.id, `KPI ${name}`, FIXTURE_TEMPLATES[code as keyof typeof FIXTURE_TEMPLATES]);
    }
    for (const required of ["TEKNISI", "PELAYAN", "ADMIN_OPS", "KASIR", "GUDANG", "SPV"]) {
      if (!positions.get(required)) throw new Error(`Jabatan ${required} belum tersedia. Jalankan npm run db:seed terlebih dahulu.`);
    }

    for (const employee of AUGUST_2026_ROSTER) {
      const position = positions.get(employee.positionCode);
      if (!position) throw new Error(`Jabatan ${employee.positionCode} belum tersedia.`);
      const user = await credentialUser(tx, employee.username, employee.name, passwordHash);
      await tx.employee.upsert({
        where: { userId: user.id },
        update: {
          employeeNumber: employee.employeeNumber,
          name: employee.name,
          branchId: branch.id,
          positionId: position.id,
          supervisorId: supervisorUser.employee.id,
          managerId: managerUser.employee.id,
          joinedAt: new Date("2026-08-01T00:00:00.000Z"),
          endedAt: null,
          status: "ACTIVE",
        },
        create: {
          userId: user.id,
          employeeNumber: employee.employeeNumber,
          name: employee.name,
          branchId: branch.id,
          positionId: position.id,
          supervisorId: supervisorUser.employee.id,
          managerId: managerUser.employee.id,
          joinedAt: new Date("2026-08-01T00:00:00.000Z"),
        },
      });
    }

    const admin: AccessProfile = { userId: adminUser.id, role: "ADMIN", employeeId: null, branchId: null, active: true };
    const manager: AccessProfile = {
      userId: managerUser.id,
      role: "MANAGER",
      employeeId: managerUser.employee.id,
      branchId: managerUser.employee.branchId,
      active: true,
    };
    const period = existingPeriod ?? await createPeriod(tx, admin, { year: 2026, month: 8 });
    await openPeriod(tx, admin, period.id, completedAt);

    const monthlyKpis = await tx.monthlyKpi.findMany({
      where: { periodId: period.id },
      orderBy: { employeeNameSnapshot: "asc" },
      include: { employee: { include: { user: true } }, items: { orderBy: { sortOrderSnapshot: "asc" } }, dailySheets: { orderBy: { entryDate: "asc" } } },
    });
    for (const [fallbackIndex, monthlyKpi] of monthlyKpis.entries()) {
      const rosterIndex = AUGUST_2026_ROSTER.findIndex((employee) => employee.username === monthlyKpi.employee.user.username);
      const profileIndex = rosterIndex >= 0 ? rosterIndex : AUGUST_2026_ROSTER.length + fallbackIndex;
      const workedSheets = monthlyKpi.dailySheets.slice(0, AUGUST_WORKED_DAYS);
      const offSheets = monthlyKpi.dailySheets.slice(AUGUST_WORKED_DAYS);
      assert.equal(monthlyKpi.dailySheets.length, 31, `${monthlyKpi.employeeNameSnapshot} harus mempunyai 31 lembar Agustus.`);

      // ponytail: bulk fixture writes skip per-day audit; use save/review operations when audit-volume fidelity is under test.
      await tx.dailyValue.deleteMany({ where: { dailySheetId: { in: monthlyKpi.dailySheets.map((sheet) => sheet.id) } } });
      await tx.dailySheet.updateMany({
        where: { id: { in: workedSheets.map((sheet) => sheet.id) } },
        data: {
          workStatus: "WORKED",
          effectiveWorkStatus: "WORKED",
          status: "APPROVED",
          note: "Data uji harian Agustus 2026.",
          enteredById: monthlyKpi.subjectRoleSnapshot === "SUPERVISOR" ? managerUser.id : supervisorUser.id,
          submittedAt: completedAt,
          managerReviewedById: managerUser.id,
          managerReviewedAt: completedAt,
          rowVersion: 2,
        },
      });
      await tx.dailySheet.updateMany({
        where: { id: { in: offSheets.map((sheet) => sheet.id) } },
        data: {
          workStatus: "OFF",
          effectiveWorkStatus: "OFF",
          status: "APPROVED",
          note: "Libur setelah 30 hari data uji.",
          enteredById: monthlyKpi.subjectRoleSnapshot === "SUPERVISOR" ? managerUser.id : supervisorUser.id,
          submittedAt: completedAt,
          managerReviewedById: managerUser.id,
          managerReviewedAt: completedAt,
          rowVersion: 2,
        },
      });

      const fixtureSnapshots = new Map(monthlyKpi.items.map((item) => {
        const snapshot = item.kindSnapshot === "CATEGORY" ? legacyCategorySnapshot(item.categoryOptionsSnapshot) : item.categoryOptionsSnapshot;
        return [item.id, snapshot] as const;
      }));
      for (const item of monthlyKpi.items.filter((candidate) => candidate.kindSnapshot === "CATEGORY")) {
        await tx.monthlyKpiItem.update({ where: { id: item.id }, data: { categoryOptionsSnapshot: fixtureSnapshots.get(item.id)! as Prisma.InputJsonValue } });
      }
      const valuesByItem = new Map(monthlyKpi.items.map((item) => {
        const indicator = snapshotIndicator({ ...item, categoryOptionsSnapshot: fixtureSnapshots.get(item.id) });
        return [item.id, {
          values: fixtureValuesForIndicator(profileIndex, indicator),
          categoryOptionId: indicator.kind === "CATEGORY" ? fixtureCategoryOption(profileIndex, indicator).id : null,
        }];
      }));
      await tx.dailyValue.createMany({
        data: workedSheets.flatMap((sheet, day) => monthlyKpi.items.map((item) => {
          const entry = valuesByItem.get(item.id)!;
          return {
            dailySheetId: sheet.id,
            monthlyKpiItemId: item.id,
            enteredValue: entry.values[day],
            effectiveValue: entry.values[day],
            categoryOptionId: entry.categoryOptionId,
          };
        })),
      });
      await recalculateMonthlyKpi(tx, monthlyKpi.id, completedAt);
      const current = await tx.monthlyKpi.findUniqueOrThrow({ where: { id: monthlyKpi.id }, select: { rowVersion: true } });
      await finalizeMonthlyKpi(tx, manager, { monthlyKpiId: monthlyKpi.id, rowVersion: current.rowVersion }, completedAt);
    }

    await tx.auditEvent.create({
      data: {
        actorId: adminUser.id,
        action: "seed_august_2026_fixture",
        subjectType: "KpiPeriod",
        subjectId: period.id,
        afterJson: { rosterUsers: AUGUST_2026_ROSTER.length, workedDaysPerEmployee: AUGUST_WORKED_DAYS },
        reason: "Fixture pengujian harian Agustus 2026.",
      },
    });
    return { created: true, periodId: period.id };
  }, { maxWait: 10_000, timeout: 300_000 });

  console.info(`${outcome.created ? "Seed selesai" : "Seed sudah tersedia"}: 35 user roster, 30 hari bekerja per user, periode Agustus 2026.`);
  console.info("Seluruh akun memakai password SEED_DEMO_PASSWORD dari environment.");
} finally {
  await prisma.$disconnect();
}

async function credentialUser(tx: Prisma.TransactionClient, username: string, name: string, password: string) {
  const user = await tx.user.upsert({
    where: { username },
    update: { name, role: "EMPLOYEE", isActive: true, emailVerified: true },
    create: { username, email: `${randomUUID()}@users.kpi.invalid`, name, role: "EMPLOYEE", isActive: true, emailVerified: true },
  });
  await tx.account.upsert({
    where: { providerId_accountId: { providerId: "credential", accountId: user.id } },
    update: { password },
    create: { userId: user.id, providerId: "credential", accountId: user.id, password },
  });
  return user;
}

async function ensureTemplate(
  tx: Prisma.TransactionClient,
  positionId: string,
  name: string,
  indicators: readonly FixtureIndicator[],
) {
  const template = await tx.kpiTemplate.upsert({ where: { positionId }, update: {}, create: { positionId, name } });
  if (await tx.kpiTemplateVersion.count({ where: { templateId: template.id, status: "ACTIVE" } })) return;
  const latest = await tx.kpiTemplateVersion.aggregate({ where: { templateId: template.id }, _max: { versionNumber: true } });
  await tx.kpiTemplateVersion.create({
    data: {
      templateId: template.id,
      versionNumber: (latest._max.versionNumber ?? 0) + 1,
      status: "ACTIVE",
      activatedAt: new Date("2026-08-01T00:00:00.000Z"),
      indicators: { create: indicators.map((indicator) => indicatorCreateInput(indicator)) },
    },
  });
}

function legacyCategorySnapshot(value: unknown) {
  return categoryOptionsFromSnapshot(value).map((option, index) => ({
    id: option.id,
    label: option.label,
    score: String(KPI_CATEGORY_SCALE[index]?.score ?? 0),
    sortOrder: option.sortOrder,
  }));
}

function snapshotIndicator(item: {
  codeSnapshot: string;
  nameSnapshot: string;
  descriptionSnapshot: string | null;
  kindSnapshot: FixtureIndicator["kind"];
  unitSnapshot: string;
  aggregationSnapshot: FixtureIndicator["aggregation"];
  directionSnapshot: FixtureIndicator["direction"];
  targetSnapshot: { toString(): string };
  failureLimitSnapshot: { toString(): string } | null;
  weightSnapshot: { toString(): string };
  sortOrderSnapshot: number;
  categoryOptionsSnapshot: unknown;
}): FixtureIndicator {
  return {
    code: item.codeSnapshot,
    name: item.nameSnapshot,
    description: item.descriptionSnapshot ?? "Indikator fixture Agustus 2026.",
    kind: item.kindSnapshot,
    unit: item.unitSnapshot,
    aggregation: item.aggregationSnapshot,
    direction: item.directionSnapshot,
    target: item.targetSnapshot.toString(),
    failureLimit: item.failureLimitSnapshot?.toString() ?? null,
    weight: item.weightSnapshot.toString(),
    sortOrder: item.sortOrderSnapshot,
    categoryOptions: categoryOptionsFromSnapshot(item.categoryOptionsSnapshot ?? []).map((option) => ({
      ...option,
      score: "score" in option && option.score != null ? option.score : "threshold" in option && option.threshold != null ? option.threshold : 0,
      isActive: true,
    })),
  };
}
