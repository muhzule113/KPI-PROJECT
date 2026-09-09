import "dotenv/config";

import assert from "node:assert/strict";
import { PrismaPg } from "@prisma/adapter-pg";
import { PrismaClient } from "../src/generated/prisma/client.ts";
import type { AccessProfile } from "../src/modules/access/policy.ts";
import { savePosition } from "../src/modules/admin/organization-operations.ts";
import {
  activateRatingDraft,
  activateTemplate,
  discardTemplateDraft,
  removeIndicator,
  saveIndicator,
  saveRatingDraft,
  startRatingDraft,
  startTemplateDraft,
} from "../src/modules/admin/template-operations.ts";
import { createPeriod, openPeriod } from "../src/modules/kpi/period-operations.ts";
import { ratingBandsFromSnapshot } from "../src/modules/kpi/period.ts";

const connectionString = process.env.DATABASE_URL;
if (!connectionString) throw new Error("DATABASE_URL wajib diisi.");
const prisma = new PrismaClient({ adapter: new PrismaPg({ connectionString }) });
const rollback = new Error("ROLLBACK_CONFIGURATION_VERIFICATION");

try {
  await prisma.$transaction(async (tx) => {
    const adminUser = await tx.user.findUniqueOrThrow({ where: { email: "admin@kpi-simple.local" } });
    const admin: AccessProfile = { userId: adminUser.id, role: "ADMIN", employeeId: null, branchId: null, active: true };

    const position = await savePosition(tx, admin, { code: "VERIFY-CONFIG", name: "Jabatan Verifikasi", isKpiSubject: true, isActive: true });
    const initial = await tx.position.findUniqueOrThrow({ where: { id: position.id }, include: { template: { include: { versions: true } } } });
    assert.equal(initial.template?.versions.length, 1);
    assert.equal(initial.template?.versions[0].status, "DRAFT");
    assert.equal(initial.template?.versions[0].versionNumber, 1);

    const firstDraft = initial.template!.versions[0];
    const firstIndicator = await saveIndicator(tx, admin, {
      versionId: firstDraft.id,
      code: "HASIL",
      name: "Hasil kerja",
      kind: "NUMERIC",
      unit: "unit",
      aggregation: "SUM",
      direction: "HIGHER",
      target: 10,
      weight: 100,
      sortOrder: 1,
    });
    await activateTemplate(tx, admin, firstDraft.id);
    await assert.rejects(saveIndicator(tx, admin, { versionId: firstDraft.id, id: firstIndicator.id, code: "HASIL", name: "Diubah", kind: "NUMERIC", unit: "unit", aggregation: "SUM", direction: "HIGHER", target: 10, weight: 100, sortOrder: 1 }), /draft/i);
    await assert.rejects(removeIndicator(tx, admin, firstIndicator.id), /draft/i);

    const discarded = await startTemplateDraft(tx, admin, initial.template!.id);
    await discardTemplateDraft(tx, admin, discarded.id);
    assert.equal(await tx.kpiTemplateVersion.count({ where: { templateId: initial.template!.id, status: "DRAFT" } }), 0);

    const revision = await startTemplateDraft(tx, admin, initial.template!.id);
    const revisionItem = await tx.kpiIndicator.findFirstOrThrow({ where: { templateVersionId: revision.id } });
    await saveIndicator(tx, admin, { versionId: revision.id, id: revisionItem.id, code: revisionItem.code, name: "Hasil kerja revisi", description: revisionItem.description ?? undefined, kind: revisionItem.kind, unit: revisionItem.unit, aggregation: revisionItem.aggregation, direction: revisionItem.direction, target: revisionItem.target.toNumber(), failureLimit: revisionItem.failureLimit?.toNumber(), weight: revisionItem.weight.toNumber(), sortOrder: revisionItem.sortOrder });
    await activateTemplate(tx, admin, revision.id);
    assert.equal((await tx.kpiTemplateVersion.findUniqueOrThrow({ where: { id: firstDraft.id } })).status, "RETIRED");
    assert.equal((await tx.kpiTemplateVersion.findUniqueOrThrow({ where: { id: revision.id } })).status, "ACTIVE");

    const pelayan = await tx.position.findUniqueOrThrow({ where: { code: "PELAYAN" }, include: { template: true } });
    await assert.rejects(savePosition(tx, admin, { id: pelayan.id, code: pelayan.code, name: pelayan.name, isKpiSubject: false, isActive: false }), /pegawai aktif/i);
    const oldKpi = await tx.monthlyKpi.findFirstOrThrow({ where: { positionCodeSnapshot: "PELAYAN" }, include: { items: { orderBy: { sortOrderSnapshot: "asc" } } } });
    const oldItemName = oldKpi.items[0].nameSnapshot;

    const existingPelayanDraft = await tx.kpiTemplateVersion.findFirst({ where: { templateId: pelayan.template!.id, status: "DRAFT" } });
    if (existingPelayanDraft) await discardTemplateDraft(tx, admin, existingPelayanDraft.id);
    const pelayanDraft = await startTemplateDraft(tx, admin, pelayan.template!.id);
    const pelayanItem = await tx.kpiIndicator.findFirstOrThrow({ where: { templateVersionId: pelayanDraft.id }, orderBy: { sortOrder: "asc" } });
    const revisedName = `${pelayanItem.name} revisi`;
    await saveIndicator(tx, admin, { versionId: pelayanDraft.id, id: pelayanItem.id, code: pelayanItem.code, name: revisedName, description: pelayanItem.description ?? undefined, kind: pelayanItem.kind, unit: pelayanItem.unit, aggregation: pelayanItem.aggregation, direction: pelayanItem.direction, target: pelayanItem.target.toNumber(), failureLimit: pelayanItem.failureLimit?.toNumber(), weight: pelayanItem.weight.toNumber(), sortOrder: pelayanItem.sortOrder });
    await activateTemplate(tx, admin, pelayanDraft.id);

    const activeRating = await tx.kpiRatingScheme.findFirstOrThrow({ where: { status: "ACTIVE" } });
    const ratingDraft = await startRatingDraft(tx, admin);
    const ratingBands = await tx.kpiRatingBand.findMany({ where: { ratingSchemeId: ratingDraft.id }, orderBy: { sortOrder: "asc" } });
    await saveRatingDraft(tx, admin, ratingDraft.id, ratingBands.map((band) => ({ code: band.code, label: band.code === "STAR" ? "Istimewa Verifikasi" : band.label, minScore: band.minScore.toString(), sortOrder: band.sortOrder })));
    await activateRatingDraft(tx, admin, ratingDraft.id);
    assert.equal((await tx.kpiRatingScheme.findUniqueOrThrow({ where: { id: activeRating.id } })).status, "RETIRED");

    const existingPeriods = new Set((await tx.kpiPeriod.findMany({ select: { year: true, month: true } })).map((period) => `${period.year}-${period.month}`));
    let candidate: { year: number; month: number } | undefined;
    for (let year = 2080; year <= 2100 && !candidate; year += 1) {
      for (let month = 1; month <= 12; month += 1) if (!existingPeriods.has(`${year}-${month}`)) { candidate = { year, month }; break; }
    }
    assert.ok(candidate, "Periode verifikasi kosong tidak tersedia.");
    const period = await createPeriod(tx, admin, candidate);
    await openPeriod(tx, admin, period.id);
    const newKpi = await tx.monthlyKpi.findFirstOrThrow({ where: { periodId: period.id, positionCodeSnapshot: "PELAYAN" }, include: { items: { orderBy: { sortOrderSnapshot: "asc" } } } });
    assert.equal((await tx.monthlyKpiItem.findFirstOrThrow({ where: { monthlyKpiId: oldKpi.id }, orderBy: { sortOrderSnapshot: "asc" } })).nameSnapshot, oldItemName);
    assert.equal(newKpi.items[0].nameSnapshot, revisedName);
    assert.equal(ratingBandsFromSnapshot(oldKpi.ratingBandsSnapshot).some((band) => band.label === "Istimewa Verifikasi"), false);
    assert.equal(ratingBandsFromSnapshot(newKpi.ratingBandsSnapshot).find((band) => band.code === "STAR")?.label, "Istimewa Verifikasi");

    throw rollback;
  });
} catch (error) {
  if (error !== rollback) throw error;
} finally {
  await prisma.$disconnect();
}

console.info("Verifikasi konfigurasi berversi lulus dan seluruh perubahan uji di-rollback.");
