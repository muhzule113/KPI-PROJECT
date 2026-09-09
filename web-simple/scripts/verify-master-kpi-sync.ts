import "dotenv/config";

import assert from "node:assert/strict";
import { createHash } from "node:crypto";
import { PrismaPg } from "@prisma/adapter-pg";
import { PrismaClient, type Prisma } from "../src/generated/prisma/client.ts";
import type { AccessProfile } from "../src/modules/access/policy.ts";
import { startTemplateDraft } from "../src/modules/admin/template-operations.ts";
import { syncMasterKpiTemplates } from "../src/modules/admin/master-kpi-sync.ts";
import { MASTER_KPI_TEMPLATES } from "../src/modules/kpi/master-kpi-templates.ts";

const connectionString = process.env.DATABASE_URL;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");

const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });
const rollback = new Error("ROLLBACK_VERIFIKASI_MASTER_KPI");

try {
  const adminUser = await prisma.user.findUniqueOrThrow({ where: { username: "admin" } });
  const admin: AccessProfile = { userId: adminUser.id, role: "ADMIN", employeeId: null, branchId: null, active: true };

  await expectRollback(prisma.$transaction(async (tx) => {
    const historyBefore = await historyFingerprint(tx);
    const outsideBefore = await outsideCatalogFingerprint(tx);
    const activeBefore = await activeVersions(tx);
    const auditBefore = await tx.auditEvent.count({ where: { action: "sync_master_kpi_template" } });

    const first = await syncMasterKpiTemplates(tx, admin);
    assert.equal(first.updated.length + first.skipped.length, 6);
    assert.equal(new Set([...first.updated, ...first.skipped].map((item) => item.positionCode)).size, 6);

    for (const update of first.updated) {
      assert.equal(activeBefore.get(update.positionCode)?.id, update.fromVersionId);
      assert.equal((await tx.kpiTemplateVersion.findUniqueOrThrow({ where: { id: update.fromVersionId } })).status, "RETIRED");
      assert.equal((await tx.kpiTemplateVersion.findUniqueOrThrow({ where: { id: update.toVersionId } })).status, "ACTIVE");
    }
    assert.equal(await tx.auditEvent.count({ where: { action: "sync_master_kpi_template" } }), auditBefore + first.updated.length);

    const versionCount = await tx.kpiTemplateVersion.count();
    const auditCount = await tx.auditEvent.count({ where: { action: "sync_master_kpi_template" } });
    const second = await syncMasterKpiTemplates(tx, admin);
    assert.equal(second.updated.length, 0);
    assert.equal(second.skipped.length, 6);
    assert.equal(await tx.kpiTemplateVersion.count(), versionCount);
    assert.equal(await tx.auditEvent.count({ where: { action: "sync_master_kpi_template" } }), auditCount);
    assert.equal(await historyFingerprint(tx), historyBefore);
    assert.equal(await outsideCatalogFingerprint(tx), outsideBefore);
    throw rollback;
  }, { isolationLevel: "Serializable", maxWait: 10_000, timeout: 120_000 }));

  await expectRollback(prisma.$transaction(async (tx) => {
    const candidates = await tx.kpiTemplate.findMany({
      where: { position: { code: { in: Object.keys(MASTER_KPI_TEMPLATES) } } },
      include: { position: true, versions: { where: { status: { in: ["ACTIVE", "DRAFT"] } }, include: { indicators: { orderBy: [{ sortOrder: "asc" }, { code: "asc" }] } } } },
    });
    const template = candidates.find((candidate) => !candidate.versions.some((version) => version.status === "DRAFT"));
    assert.ok(template, "Minimal satu template target harus tersedia tanpa draft untuk uji konflik.");
    const active = template.versions.find((version) => version.status === "ACTIVE");
    assert.ok(active?.indicators[0]);
    const draft = await startTemplateDraft(tx, admin, template.id);
    const draftIndicator = await tx.kpiIndicator.findFirstOrThrow({ where: { templateVersionId: draft.id }, orderBy: [{ sortOrder: "asc" }, { code: "asc" }] });
    await tx.kpiIndicator.update({ where: { id: active.indicators[0].id }, data: { name: "Aktif berbeda untuk verifikasi" } });
    await tx.kpiIndicator.update({ where: { id: draftIndicator.id }, data: { name: "Perubahan pengguna pada draft" } });

    const configBefore = await targetCatalogFingerprint(tx);
    const auditBefore = await tx.auditEvent.count();
    await assert.rejects(syncMasterKpiTemplates(tx, admin), /draft .*perubahan pengguna/i);
    assert.equal(await targetCatalogFingerprint(tx), configBefore);
    assert.equal(await tx.auditEvent.count(), auditBefore);
    throw rollback;
  }, { isolationLevel: "Serializable", maxWait: 10_000, timeout: 120_000 }));

  console.info("Verifikasi sinkronisasi master KPI lulus: idempoten, histori aman, draft pengguna ditolak, dan template di luar katalog tidak berubah.");
} finally {
  await prisma.$disconnect();
}

async function expectRollback(promise: Promise<unknown>) {
  await assert.rejects(promise, (error) => error === rollback);
}

async function activeVersions(tx: Prisma.TransactionClient) {
  const templates = await tx.kpiTemplate.findMany({
    where: { position: { code: { in: Object.keys(MASTER_KPI_TEMPLATES) } } },
    include: { position: true, versions: { where: { status: "ACTIVE" } } },
  });
  return new Map(templates.map((template) => [template.position.code, template.versions[0]]));
}

async function historyFingerprint(tx: Prisma.TransactionClient) {
  return fingerprint(await tx.kpiPeriod.findMany({
    where: { OR: [{ year: 2026, month: 8 }, { year: 2026, month: 9 }] },
    orderBy: [{ year: "asc" }, { month: "asc" }],
    include: {
      monthlyKpis: {
        orderBy: { id: "asc" },
        include: {
          items: { orderBy: { id: "asc" } },
          dailySheets: { orderBy: { id: "asc" }, include: { values: { orderBy: { id: "asc" } } } },
        },
      },
    },
  }));
}

async function outsideCatalogFingerprint(tx: Prisma.TransactionClient) {
  return fingerprint(await tx.position.findMany({
    where: { code: { in: ["CREW", "KURIR", "MGR"] } },
    orderBy: { code: "asc" },
    include: { template: { include: { versions: { orderBy: { versionNumber: "asc" }, include: { indicators: { orderBy: [{ sortOrder: "asc" }, { code: "asc" }] } } } } } },
  }));
}

async function targetCatalogFingerprint(tx: Prisma.TransactionClient) {
  return fingerprint(await tx.kpiTemplate.findMany({
    where: { position: { code: { in: Object.keys(MASTER_KPI_TEMPLATES) } } },
    orderBy: { position: { code: "asc" } },
    include: { position: true, versions: { orderBy: { versionNumber: "asc" }, include: { indicators: { orderBy: [{ sortOrder: "asc" }, { code: "asc" }] } } } },
  }));
}

function fingerprint(value: unknown) {
  return createHash("sha256").update(JSON.stringify(value)).digest("hex");
}
