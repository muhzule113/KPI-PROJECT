import { Prisma, type AggregationType, type KpiDirection, type ValueKind } from "@/generated/prisma/client";
import type { AccessProfile } from "@/modules/access/policy";
import { validateCategoryBands, validateCategoryOptions, type CategoryBandInput, type CategoryOptionInput } from "@/modules/kpi/category-options";
import { validateRatingBands, validateTemplate, type RatingBandSnapshot } from "@/modules/kpi/period";

function assertAdmin(actor: AccessProfile) {
  if (!actor.active || actor.role !== "ADMIN") throw new Error("Hanya Super Admin yang dapat mengubah konfigurasi KPI.");
}

const indicatorJson = (indicator: {
  code: string;
  name: string;
  kind: ValueKind;
  unit: string;
  aggregation: AggregationType;
  direction: KpiDirection;
  target: Prisma.Decimal | number;
  failureLimit: Prisma.Decimal | number | null;
  weight: Prisma.Decimal | number;
  sortOrder: number;
  categoryOptions?: Array<{ label: string; threshold: Prisma.Decimal | number | null; sortOrder: number; isActive: boolean }>;
}) => ({
  code: indicator.code,
  name: indicator.name,
  kind: indicator.kind,
  unit: indicator.unit,
  aggregation: indicator.aggregation,
  direction: indicator.direction,
  target: String(indicator.target),
  failureLimit: indicator.failureLimit === null ? null : String(indicator.failureLimit),
  weight: String(indicator.weight),
  sortOrder: indicator.sortOrder,
  categoryOptions: indicator.categoryOptions?.map((option) => ({
    label: option.label,
    threshold: option.threshold === null ? null : String(option.threshold),
    sortOrder: option.sortOrder,
    isActive: option.isActive,
  })),
});

export async function saveTemplateName(tx: Prisma.TransactionClient, actor: AccessProfile, templateId: string, name: string) {
  assertAdmin(actor);
  const template = await tx.kpiTemplate.findUnique({ where: { id: templateId } });
  if (!template) throw new Error("Template tidak ditemukan.");
  const normalized = name.trim();
  if (!normalized || normalized.length > 120) throw new Error("Nama template wajib diisi dan maksimal 120 karakter.");
  const updated = await tx.kpiTemplate.update({ where: { id: template.id }, data: { name: normalized } });
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: "update_template_name", subjectType: "KpiTemplate", subjectId: template.id, beforeJson: { name: template.name }, afterJson: { name: updated.name } } });
  return updated;
}

export async function startTemplateDraft(tx: Prisma.TransactionClient, actor: AccessProfile, templateId: string) {
  assertAdmin(actor);
  const template = await tx.kpiTemplate.findUnique({ where: { id: templateId } });
  if (!template) throw new Error("Template tidak ditemukan.");
  if (await tx.kpiTemplateVersion.findFirst({ where: { templateId, status: "DRAFT" } })) throw new Error("Template masih memiliki draft yang belum diselesaikan.");
  const active = await tx.kpiTemplateVersion.findFirst({
    where: { templateId, status: "ACTIVE" },
    include: { indicators: { orderBy: { sortOrder: "asc" }, include: { categoryOptions: { orderBy: { sortOrder: "asc" } } } } },
  });
  if (!active) throw new Error("Versi aktif yang akan direvisi tidak ditemukan.");
  const latest = await tx.kpiTemplateVersion.aggregate({ where: { templateId }, _max: { versionNumber: true } });
  const draft = await tx.kpiTemplateVersion.create({
    data: {
      templateId,
      versionNumber: (latest._max.versionNumber ?? active.versionNumber) + 1,
      indicators: {
        create: active.indicators.map((indicator) => ({
          code: indicator.code,
          name: indicator.name,
          description: indicator.description,
          kind: indicator.kind,
          unit: indicator.unit,
          aggregation: indicator.aggregation,
          direction: indicator.direction,
          target: indicator.target,
          failureLimit: indicator.failureLimit,
          weight: indicator.weight,
          sortOrder: indicator.sortOrder,
          categoryOptions: {
            create: indicator.categoryOptions.map((option) => ({
              label: option.label,
              threshold: option.threshold,
              sortOrder: option.sortOrder,
              isActive: option.isActive,
            })),
          },
        })),
      },
    },
  });
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: "create_template_draft", subjectType: "KpiTemplateVersion", subjectId: draft.id, beforeJson: { sourceVersionId: active.id, versionNumber: active.versionNumber }, afterJson: { versionNumber: draft.versionNumber, indicatorCount: active.indicators.length } } });
  return draft;
}

export async function discardTemplateDraft(tx: Prisma.TransactionClient, actor: AccessProfile, versionId: string) {
  assertAdmin(actor);
  const draft = await tx.kpiTemplateVersion.findUnique({ where: { id: versionId }, include: { _count: { select: { indicators: true } } } });
  if (!draft || draft.status !== "DRAFT") throw new Error("Draft template tidak ditemukan.");
  if (!await tx.kpiTemplateVersion.findFirst({ where: { templateId: draft.templateId, status: "ACTIVE" } })) throw new Error("Draft pertama tidak dapat dibatalkan sebelum memiliki versi aktif.");
  await tx.kpiTemplateVersion.delete({ where: { id: draft.id } });
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: "discard_template_draft", subjectType: "KpiTemplateVersion", subjectId: draft.id, beforeJson: { versionNumber: draft.versionNumber, indicatorCount: draft._count.indicators } } });
}

export async function saveIndicator(tx: Prisma.TransactionClient, actor: AccessProfile, input: {
  id?: string;
  versionId: string;
  code: string;
  name: string;
  description?: string;
  kind: ValueKind;
  unit: string;
  aggregation: AggregationType;
  direction: KpiDirection;
  target: number;
  failureLimit?: number;
  weight: number;
  sortOrder: number;
  categoryBands?: CategoryBandInput[];
  /** Legacy input accepted while old callers are migrated. */
  categoryOptions?: CategoryOptionInput[];
}) {
  assertAdmin(actor);
  const version = await tx.kpiTemplateVersion.findUnique({ where: { id: input.versionId } });
  if (!version || version.status !== "DRAFT") throw new Error("Hanya indikator pada draft template yang dapat diubah.");
  const before = input.id ? await tx.kpiIndicator.findFirst({ where: { id: input.id, templateVersionId: version.id }, include: { categoryOptions: { orderBy: { sortOrder: "asc" } } } }) : null;
  if (input.id && !before) throw new Error("Indikator draft tidak ditemukan.");
  const data = {
    templateVersionId: version.id,
    code: input.code.trim().toUpperCase(),
    name: input.name.trim(),
    description: input.description?.trim() || null,
    kind: input.kind,
    unit: input.unit.trim(),
    aggregation: input.kind === "RATING" || input.kind === "CHECKBOX" ? "AVERAGE" as const : input.aggregation,
    direction: input.kind === "RATING" || input.kind === "CHECKBOX" ? "HIGHER" as const : input.direction,
    target: input.target,
    failureLimit: input.direction === "LOWER" && (input.kind === "NUMERIC" || input.kind === "CATEGORY") ? input.failureLimit : null,
    weight: input.weight,
    sortOrder: input.sortOrder,
  };
  if (!data.code || !data.name || !data.unit) throw new Error("Kode, nama, dan satuan indikator wajib diisi.");
  if (!Number.isFinite(data.target) || !Number.isFinite(data.weight) || data.weight <= 0 || data.weight > 100) throw new Error("Target dan bobot indikator tidak valid.");
  if (data.kind === "RATING" && (data.target < 1 || data.target > 5)) throw new Error("Target rating harus 1 sampai 5.");
  if (data.kind === "CHECKBOX" && data.target !== 100) throw new Error("Target indikator centang wajib 100.");
  const categoryBands = input.categoryBands ?? (input.categoryOptions?.some((option) => option.threshold !== undefined)
    ? input.categoryOptions.map((option) => ({ label: option.label, threshold: option.threshold ?? null, sortOrder: option.sortOrder, isActive: option.isActive }))
    : undefined);
  if (data.kind === "CATEGORY") {
    const categoryCheck = categoryBands
      ? validateCategoryBands(categoryBands, data.direction, data.unit)
      : validateCategoryOptions(input.categoryOptions ?? []);
    if (!categoryCheck.ok) throw new Error(categoryCheck.reason);
    if (!categoryBands) throw new Error("Indikator Angka + predikat wajib memakai empat threshold.");
  }
  if (data.direction === "HIGHER" && data.target <= 0) throw new Error("Target formula higher harus lebih besar dari 0.");
  if (data.direction === "LOWER" && (!data.failureLimit || data.failureLimit <= data.target)) throw new Error("Failure limit harus lebih besar dari target.");
  const duplicate = await tx.kpiIndicator.findFirst({ where: { templateVersionId: version.id, code: data.code, id: input.id ? { not: input.id } : undefined } });
  if (duplicate) throw new Error("Kode indikator harus unik dalam satu versi template.");
  const options = data.kind === "CATEGORY" ? categoryBands ?? input.categoryOptions ?? [] : [];
  const indicator = before
    ? await tx.kpiIndicator.update({ where: { id: before.id }, data })
    : await tx.kpiIndicator.create({ data });
  await tx.kpiCategoryOption.deleteMany({ where: { indicatorId: indicator.id } });
  if (options.length) {
    await tx.kpiCategoryOption.createMany({
      data: options.map((option) => ({
        indicatorId: indicator.id,
        label: option.label.trim(),
        threshold: "threshold" in option && option.threshold !== null ? String(option.threshold) : null,
        sortOrder: option.sortOrder,
        isActive: option.isActive ?? true,
      })),
    });
  }
  const saved = await tx.kpiIndicator.findUniqueOrThrow({ where: { id: indicator.id }, include: { categoryOptions: { orderBy: { sortOrder: "asc" } } } });
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: before ? "update_indicator" : "create_indicator", subjectType: "KpiIndicator", subjectId: saved.id, beforeJson: before ? indicatorJson(before) : undefined, afterJson: indicatorJson(saved) } });
  return saved;
}

export async function removeIndicator(tx: Prisma.TransactionClient, actor: AccessProfile, indicatorId: string) {
  assertAdmin(actor);
  const indicator = await tx.kpiIndicator.findUnique({ where: { id: indicatorId }, include: { templateVersion: true } });
  if (!indicator || indicator.templateVersion.status !== "DRAFT") throw new Error("Hanya indikator draft yang dapat dihapus.");
  await tx.kpiIndicator.delete({ where: { id: indicator.id } });
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: "remove_indicator", subjectType: "KpiIndicator", subjectId: indicator.id, beforeJson: indicatorJson(indicator) } });
}

export async function activateTemplate(tx: Prisma.TransactionClient, actor: AccessProfile, versionId: string, now = new Date()) {
  assertAdmin(actor);
  const version = await tx.kpiTemplateVersion.findUnique({ where: { id: versionId }, include: { indicators: { orderBy: { sortOrder: "asc" }, include: { categoryOptions: { orderBy: { sortOrder: "asc" } } } } } });
  if (!version || version.status !== "DRAFT") throw new Error("Draft template tidak ditemukan.");
  if (new Set(version.indicators.map((indicator) => indicator.sortOrder)).size !== version.indicators.length) throw new Error("Urutan indikator harus unik sebelum template diaktifkan.");
  const validation = validateTemplate(version.indicators.map((indicator) => ({
    code: indicator.code,
    kind: indicator.kind,
    aggregation: indicator.aggregation,
    direction: indicator.direction,
    target: indicator.target.toString(),
    failureLimit: indicator.failureLimit?.toString() ?? null,
    weight: indicator.weight.toString(),
    activeCategoryOptions: indicator.categoryOptions.filter((option) => option.isActive).length,
    unit: indicator.unit,
    categoryBands: indicator.kind === "CATEGORY" && indicator.categoryOptions.length === 5 && indicator.categoryOptions.slice(0, 4).every((option) => option.threshold !== null) && indicator.categoryOptions[4].threshold === null
      ? indicator.categoryOptions.map((option) => ({ label: option.label, threshold: option.threshold, sortOrder: option.sortOrder, isActive: option.isActive }))
      : undefined,
  })));
  if (!validation.ok) throw new Error(validation.reason);
  await tx.kpiTemplateVersion.updateMany({ where: { templateId: version.templateId, status: "ACTIVE" }, data: { status: "RETIRED" } });
  const claimed = await tx.kpiTemplateVersion.updateMany({ where: { id: version.id, status: "DRAFT" }, data: { status: "ACTIVE", activatedAt: now } });
  if (claimed.count !== 1) throw new Error("Draft template telah berubah. Muat ulang sebelum mengaktifkan.");
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: "activate_template_version", subjectType: "KpiTemplateVersion", subjectId: version.id, beforeJson: { status: "DRAFT", versionNumber: version.versionNumber }, afterJson: { status: "ACTIVE", versionNumber: version.versionNumber, indicatorCount: version.indicators.length } } });
  return { templateId: version.templateId, versionNumber: version.versionNumber };
}

export async function startRatingDraft(tx: Prisma.TransactionClient, actor: AccessProfile) {
  assertAdmin(actor);
  if (await tx.kpiRatingScheme.findFirst({ where: { status: "DRAFT" } })) throw new Error("Skala predikat masih memiliki draft yang belum diselesaikan.");
  const active = await tx.kpiRatingScheme.findFirst({ where: { status: "ACTIVE" }, include: { bands: { orderBy: { sortOrder: "asc" } } } });
  if (!active) throw new Error("Skala predikat aktif tidak ditemukan.");
  const latest = await tx.kpiRatingScheme.aggregate({ _max: { version: true } });
  const draft = await tx.kpiRatingScheme.create({
    data: {
      version: (latest._max.version ?? active.version) + 1,
      bands: { create: active.bands.map((band) => ({ code: band.code, label: band.label, minScore: band.minScore, sortOrder: band.sortOrder })) },
    },
  });
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: "create_rating_draft", subjectType: "KpiRatingScheme", subjectId: draft.id, beforeJson: { sourceId: active.id, version: active.version }, afterJson: { version: draft.version } } });
  return draft;
}

export async function saveRatingDraft(tx: Prisma.TransactionClient, actor: AccessProfile, schemeId: string, bands: RatingBandSnapshot[]) {
  assertAdmin(actor);
  const scheme = await tx.kpiRatingScheme.findUnique({ where: { id: schemeId }, include: { bands: { orderBy: { sortOrder: "asc" } } } });
  if (!scheme || scheme.status !== "DRAFT") throw new Error("Hanya draft skala predikat yang dapat diubah.");
  const validation = validateRatingBands(bands);
  if (!validation.ok) throw new Error(validation.reason);
  const normalized = bands.map((band) => ({ code: band.code, label: band.label.trim(), minScore: Number(band.minScore), sortOrder: band.sortOrder }));
  await tx.kpiRatingBand.deleteMany({ where: { ratingSchemeId: scheme.id } });
  await tx.kpiRatingBand.createMany({ data: normalized.map((band) => ({ ratingSchemeId: scheme.id, ...band })) });
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: "update_rating_draft", subjectType: "KpiRatingScheme", subjectId: scheme.id, beforeJson: { version: scheme.version, bands: scheme.bands.map((band) => ({ code: band.code, label: band.label, minScore: band.minScore.toString(), sortOrder: band.sortOrder })) }, afterJson: { version: scheme.version, bands: normalized } } });
  return scheme;
}

export async function discardRatingDraft(tx: Prisma.TransactionClient, actor: AccessProfile, schemeId: string) {
  assertAdmin(actor);
  const scheme = await tx.kpiRatingScheme.findUnique({ where: { id: schemeId } });
  if (!scheme || scheme.status !== "DRAFT") throw new Error("Draft skala predikat tidak ditemukan.");
  await tx.kpiRatingScheme.delete({ where: { id: scheme.id } });
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: "discard_rating_draft", subjectType: "KpiRatingScheme", subjectId: scheme.id, beforeJson: { version: scheme.version } } });
}

export async function activateRatingDraft(tx: Prisma.TransactionClient, actor: AccessProfile, schemeId: string, now = new Date()) {
  assertAdmin(actor);
  const scheme = await tx.kpiRatingScheme.findUnique({ where: { id: schemeId }, include: { bands: { orderBy: { sortOrder: "asc" } } } });
  if (!scheme || scheme.status !== "DRAFT") throw new Error("Draft skala predikat tidak ditemukan.");
  const validation = validateRatingBands(scheme.bands.map((band) => ({ code: band.code, label: band.label, minScore: band.minScore.toString(), sortOrder: band.sortOrder })));
  if (!validation.ok) throw new Error(validation.reason);
  await tx.kpiRatingScheme.updateMany({ where: { status: "ACTIVE" }, data: { status: "RETIRED" } });
  const claimed = await tx.kpiRatingScheme.updateMany({ where: { id: scheme.id, status: "DRAFT" }, data: { status: "ACTIVE", activatedAt: now } });
  if (claimed.count !== 1) throw new Error("Draft skala telah berubah. Muat ulang sebelum mengaktifkan.");
  await tx.auditEvent.create({ data: { actorId: actor.userId, action: "activate_rating_version", subjectType: "KpiRatingScheme", subjectId: scheme.id, beforeJson: { status: "DRAFT", version: scheme.version }, afterJson: { status: "ACTIVE", version: scheme.version } } });
  return scheme;
}
