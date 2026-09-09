import Decimal from "decimal.js";
import type { Prisma } from "@/generated/prisma/client";
import type { AccessProfile } from "@/modules/access/policy";
import { activateTemplate, startTemplateDraft } from "@/modules/admin/template-operations";
import {
  MASTER_KPI_TEMPLATES,
  type MasterKpiIndicator,
  type MasterKpiPositionCode,
} from "@/modules/kpi/master-kpi-templates";
import { validateTemplate } from "@/modules/kpi/period";

type ComparableIndicator = {
  code: string;
  name: string;
  description: string | null;
  kind: string;
  unit: string;
  aggregation: string;
  direction: string;
  target: number | string | { toString(): string };
  failureLimit: number | string | { toString(): string } | null;
  weight: number | string | { toString(): string };
  sortOrder: number;
};

export type MasterKpiSyncResult = {
  updated: Array<{ positionCode: MasterKpiPositionCode; fromVersionId: string; toVersionId: string; fromVersion: number; toVersion: number }>;
  skipped: Array<{ positionCode: MasterKpiPositionCode; versionId: string; version: number }>;
};

export async function syncMasterKpiTemplates(
  tx: Prisma.TransactionClient,
  actor: AccessProfile,
  now = new Date(),
): Promise<MasterKpiSyncResult> {
  if (!actor.active || actor.role !== "ADMIN") throw new Error("Hanya Super Admin yang dapat menyinkronkan master KPI.");

  const entries = Object.entries(MASTER_KPI_TEMPLATES) as Array<[MasterKpiPositionCode, readonly MasterKpiIndicator[]]>;
  for (const [, indicators] of entries) {
    const validation = validateTemplate(indicators);
    if (!validation.ok) throw new Error(validation.reason);
  }

  const templates = await tx.kpiTemplate.findMany({
    where: { position: { code: { in: entries.map(([code]) => code) } } },
    include: {
      position: true,
      versions: {
        where: { status: { in: ["ACTIVE", "DRAFT"] } },
        orderBy: { versionNumber: "asc" },
        include: { indicators: { orderBy: [{ sortOrder: "asc" }, { code: "asc" }] } },
      },
    },
  });

  const changes = [];
  const skipped: MasterKpiSyncResult["skipped"] = [];
  for (const [positionCode, indicators] of entries) {
    const template = templates.find((candidate) => candidate.position.code === positionCode);
    if (!template) throw new Error(`Template KPI ${positionCode} belum tersedia. Jalankan seed utama terlebih dahulu.`);
    const activeVersions = template.versions.filter((version) => version.status === "ACTIVE");
    if (activeVersions.length !== 1) throw new Error(`Template KPI ${positionCode} harus memiliki tepat satu versi aktif.`);
    const active = activeVersions[0];
    if (indicatorsMatch(active.indicators, indicators)) {
      skipped.push({ positionCode, versionId: active.id, version: active.versionNumber });
      continue;
    }

    const drafts = template.versions.filter((version) => version.status === "DRAFT");
    if (drafts.length > 1) throw new Error(`Template KPI ${positionCode} memiliki lebih dari satu draft.`);
    const draft = drafts[0] ?? null;
    if (draft && !indicatorsMatch(draft.indicators, active.indicators)) {
      throw new Error(`Draft KPI ${positionCode} memiliki perubahan pengguna; sinkronisasi dibatalkan.`);
    }
    changes.push({ positionCode, template, active, draft, indicators });
  }

  const updated: MasterKpiSyncResult["updated"] = [];
  for (const change of changes) {
    const draft = change.draft ?? await startTemplateDraft(tx, actor, change.template.id);
    await tx.kpiIndicator.deleteMany({ where: { templateVersionId: draft.id } });
    await tx.kpiIndicator.createMany({
      data: change.indicators.map((indicator) => ({ templateVersionId: draft.id, ...indicator })),
    });
    await activateTemplate(tx, actor, draft.id, now);
    await tx.auditEvent.create({
      data: {
        actorId: actor.userId,
        action: "sync_master_kpi_template",
        subjectType: "KpiTemplate",
        subjectId: change.template.id,
        beforeJson: { versionId: change.active.id, versionNumber: change.active.versionNumber },
        afterJson: { versionId: draft.id, versionNumber: draft.versionNumber, indicatorCount: change.indicators.length },
        reason: "Penyelarasan katalog KPI baku dari dokumen WhatsApp.",
      },
    });
    updated.push({
      positionCode: change.positionCode,
      fromVersionId: change.active.id,
      toVersionId: draft.id,
      fromVersion: change.active.versionNumber,
      toVersion: draft.versionNumber,
    });
  }
  return { updated, skipped };
}

function indicatorsMatch(left: readonly ComparableIndicator[], right: readonly ComparableIndicator[]) {
  return JSON.stringify(left.map(indicatorSignature)) === JSON.stringify(right.map(indicatorSignature));
}

function indicatorSignature(indicator: ComparableIndicator) {
  return {
    code: indicator.code,
    name: indicator.name,
    description: indicator.description ?? null,
    kind: indicator.kind,
    unit: indicator.unit,
    aggregation: indicator.aggregation,
    direction: indicator.direction,
    target: new Decimal(indicator.target.toString()).toString(),
    failureLimit: indicator.failureLimit === null ? null : new Decimal(indicator.failureLimit.toString()).toString(),
    weight: new Decimal(indicator.weight.toString()).toString(),
    sortOrder: indicator.sortOrder,
  };
}
