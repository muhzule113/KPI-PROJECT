"use server";

import { createHash } from "node:crypto";
import { revalidatePath } from "next/cache";
import { z } from "zod";
import { Prisma } from "@/generated/prisma/client";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { validateRatingBands } from "@/modules/kpi/catalog-validation";
import { validateTemplateConfiguration } from "@/modules/kpi/period-readiness";

export type CatalogActionState = { error?: string; success?: string };
const optionalId = z.preprocess((value) => value === "" ? undefined : value, z.string().min(1).optional());
const message = (error: unknown) => error instanceof Error && !error.message.toLowerCase().includes("prisma")
  ? error.message
  : "Konfigurasi KPI tidak dapat disimpan. Muat ulang lalu periksa data yang diisi.";
const refresh = () => { revalidatePath("/app/pengaturan/kpi"); revalidatePath("/app/pengaturan"); };

const definitionSchema = z.object({
  id: optionalId,
  code: z.string().trim().toUpperCase().min(2).max(30).regex(/^[A-Z0-9-]+$/),
  name: z.string().trim().min(3).max(150),
  metricType: z.enum(["count", "percentage", "currency", "duration", "score"]),
  unit: z.string().trim().min(1).max(30),
  direction: z.enum(["higher", "lower", "zero"]),
  defaultFormula: z.enum(["higher_is_better", "lower_is_better", "zero_tolerance", "rubric"]),
  sourceType: z.enum(["system", "import", "cross_role", "supervisor", "manager", "employee"]),
  description: z.string().trim().max(1000).optional(),
  isActive: z.enum(["true", "false"]),
});

export async function saveDefinition(_: CatalogActionState, formData: FormData): Promise<CatalogActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "kpi.catalog.configure")) return { error: "Hanya Super Admin yang dapat mengubah indikator KPI." };
  const parsed = definitionSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa kode, nama, unit, formula, arah, dan sumber indikator." };
  try {
    await prisma.$transaction(async (tx) => {
      const before = parsed.data.id ? await tx.kpiDefinition.findUnique({ where: { id: parsed.data.id }, include: { _count: { select: { employeeItems: true } } } }) : null;
      if (parsed.data.id && !before) throw new Error("Indikator KPI tidak ditemukan.");
      if (before && before._count.employeeItems > 0 && before.code !== parsed.data.code) throw new Error("Kode indikator yang sudah masuk snapshot tidak dapat diubah.");
      const values = { code: parsed.data.code, name: parsed.data.name, metricType: parsed.data.metricType, unit: parsed.data.unit, direction: parsed.data.direction, defaultFormula: parsed.data.defaultFormula, sourceType: parsed.data.sourceType, description: parsed.data.description || null, isActive: parsed.data.isActive === "true" };
      const definition = before ? await tx.kpiDefinition.update({ where: { id: before.id }, data: values }) : await tx.kpiDefinition.create({ data: values });
      await tx.auditEvent.create({ data: { actorId: user.id, action: before ? "update_kpi_definition" : "create_kpi_definition", subjectType: "KpiDefinition", subjectId: definition.id, beforeJson: before ? { code: before.code, name: before.name, isActive: before.isActive } : undefined, afterJson: values } });
    });
  } catch (error) { return { error: message(error) }; }
  refresh(); return { success: parsed.data.id ? "Indikator diperbarui." : "Indikator dibuat." };
}

const bandSchema = z.object({ code: z.enum(["POOR", "FAIR", "GOOD", "VERY_GOOD", "STAR"]), label: z.string().trim().min(2).max(100), minScore: z.coerce.number().min(0).max(100), maxScore: z.coerce.number().min(0).max(100), manualScore: z.coerce.number().min(0).max(100), color: z.string().regex(/^#[0-9A-Fa-f]{6}$/) });
const schemeSchema = z.object({ id: optionalId, name: z.string().trim().min(3).max(120), description: z.string().trim().max(1000).optional() });

export async function saveRatingScheme(_: CatalogActionState, formData: FormData): Promise<CatalogActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "kpi.catalog.configure")) return { error: "Hanya Super Admin yang dapat mengubah skala predikat." };
  const parsed = schemeSchema.safeParse(Object.fromEntries(formData));
  const bands = Array.from({ length: 5 }, (_, index) => bandSchema.safeParse({ code: formData.get(`band.${index}.code`), label: formData.get(`band.${index}.label`), minScore: formData.get(`band.${index}.minScore`), maxScore: formData.get(`band.${index}.maxScore`), manualScore: formData.get(`band.${index}.manualScore`), color: formData.get(`band.${index}.color`) }));
  if (!parsed.success || bands.some((band) => !band.success)) return { error: "Skala harus berisi tepat lima predikat dengan rentang, nilai manual, dan warna valid." };
  const rows = bands.map((band) => band.data!);
  const issues = validateRatingBands(rows);
  if (issues.length) return { error: issues.join(" ") };
  try {
    await prisma.$transaction(async (tx) => {
      const before = parsed.data.id ? await tx.kpiRatingScheme.findUnique({ where: { id: parsed.data.id } }) : null;
      if (parsed.data.id && !before) throw new Error("Skala predikat tidak ditemukan.");
      if (before?.isActive) throw new Error("Skala aktif bersifat immutable. Buat versi draft baru.");
      const latest = await tx.kpiRatingScheme.aggregate({ where: { name: parsed.data.name }, _max: { version: true } });
      const scheme = before ? await tx.kpiRatingScheme.update({ where: { id: before.id }, data: { name: parsed.data.name, description: parsed.data.description || null } }) : await tx.kpiRatingScheme.create({ data: { name: parsed.data.name, description: parsed.data.description || null, version: (latest._max.version ?? 0) + 1, isActive: false } });
      await tx.kpiRatingBand.deleteMany({ where: { ratingSchemeId: scheme.id } });
      await tx.kpiRatingBand.createMany({ data: rows.map((band, index) => ({ ratingSchemeId: scheme.id, ...band, sortOrder: index + 1 })) });
      await tx.auditEvent.create({ data: { actorId: user.id, action: before ? "update_rating_scheme_draft" : "create_rating_scheme_draft", subjectType: "KpiRatingScheme", subjectId: scheme.id, beforeJson: before ? { name: before.name, version: before.version } : undefined, afterJson: { name: scheme.name, version: scheme.version, bands: rows } } });
    });
  } catch (error) { return { error: message(error) }; }
  refresh(); return { success: parsed.data.id ? "Draft skala disimpan." : "Draft skala dibuat." };
}

const schemeActionSchema = z.object({ schemeId: z.string().min(1) });
export async function cloneRatingScheme(_: CatalogActionState, formData: FormData): Promise<CatalogActionState> {
  const user = await requireUser(); const parsed = schemeActionSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "kpi.catalog.configure") || !parsed.success) return { error: "Permintaan salin skala tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const source = await tx.kpiRatingScheme.findUnique({ where: { id: parsed.data.schemeId }, include: { bands: { orderBy: { sortOrder: "asc" } } } });
      if (!source?.isActive) throw new Error("Hanya skala aktif yang dapat disalin.");
      if (await tx.kpiRatingScheme.findFirst({ where: { name: source.name, isActive: false } })) throw new Error("Masih ada draft skala dengan nama yang sama.");
      const latest = await tx.kpiRatingScheme.aggregate({ where: { name: source.name }, _max: { version: true } });
      const draft = await tx.kpiRatingScheme.create({ data: { name: source.name, version: (latest._max.version ?? source.version) + 1, scoreCap: source.scoreCap, description: source.description, isActive: false } });
      await tx.kpiRatingBand.createMany({ data: source.bands.map((band) => ({ ratingSchemeId: draft.id, code: band.code, label: band.label, minScore: band.minScore, maxScore: band.maxScore, manualScore: band.manualScore, color: band.color, badgeIcon: band.badgeIcon, sortOrder: band.sortOrder })) });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "clone_rating_scheme", subjectType: "KpiRatingScheme", subjectId: draft.id, beforeJson: { sourceId: source.id, version: source.version }, afterJson: { version: draft.version } } });
    });
  } catch (error) { return { error: message(error) }; }
  refresh(); return { success: "Draft skala baru dibuat." };
}

export async function activateRatingScheme(_: CatalogActionState, formData: FormData): Promise<CatalogActionState> {
  const user = await requireUser(); const parsed = schemeActionSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "kpi.catalog.configure") || !parsed.success) return { error: "Permintaan aktivasi skala tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const scheme = await tx.kpiRatingScheme.findUnique({ where: { id: parsed.data.schemeId }, include: { bands: true } });
      if (!scheme || scheme.isActive) throw new Error("Draft skala tidak ditemukan.");
      const issues = validateRatingBands(scheme.bands.map((band) => ({ code: band.code, minScore: band.minScore.toNumber(), maxScore: band.maxScore.toNumber(), manualScore: band.manualScore?.toNumber() ?? Number.NaN })), scheme.scoreCap.toNumber());
      if (issues.length) throw new Error(issues.join(" "));
      await tx.kpiRatingScheme.updateMany({ where: { id: { not: scheme.id }, OR: [{ name: scheme.name }, { isDefault: true }] }, data: { isActive: false, isDefault: false } });
      await tx.kpiRatingScheme.update({ where: { id: scheme.id }, data: { isActive: true, isDefault: true } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "activate_rating_scheme", subjectType: "KpiRatingScheme", subjectId: scheme.id, afterJson: { version: scheme.version, isActive: true } } });
    });
  } catch (error) { return { error: message(error) }; }
  refresh(); return { success: "Skala predikat diaktifkan untuk template baru." };
}

const templateSchema = z.object({ code: z.string().trim().toUpperCase().min(2).max(30).regex(/^[A-Z0-9-]+$/), name: z.string().trim().min(3).max(150), positionId: z.string().min(1), ratingSchemeId: z.string().min(1) });
export async function createTemplate(_: CatalogActionState, formData: FormData): Promise<CatalogActionState> {
  const user = await requireUser(); const parsed = templateSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "kpi.catalog.configure")) return { error: "Hanya Super Admin yang dapat membuat template." };
  if (!parsed.success) return { error: "Periksa kode, nama, jabatan, dan skala template." };
  try {
    await prisma.$transaction(async (tx) => {
      const [position, scheme] = await Promise.all([tx.position.findFirst({ where: { id: parsed.data.positionId, isActive: true } }), tx.kpiRatingScheme.findFirst({ where: { id: parsed.data.ratingSchemeId, isActive: true } })]);
      if (!position || !scheme) throw new Error("Jabatan atau skala aktif tidak ditemukan.");
      if (await tx.kpiTemplate.findFirst({ where: { positionId: position.id, isActive: true } })) throw new Error("Jabatan tersebut sudah memiliki template aktif.");
      const template = await tx.kpiTemplate.create({ data: { code: parsed.data.code, name: parsed.data.name, positionId: position.id } });
      const version = await tx.kpiTemplateVersion.create({ data: { kpiTemplateId: template.id, versionNumber: 1, ratingSchemeId: scheme.id } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "create_kpi_template", subjectType: "KpiTemplateVersion", subjectId: version.id, afterJson: { templateId: template.id, code: template.code, positionId: position.id, version: 1 } } });
    });
  } catch (error) { return { error: message(error) }; }
  refresh(); return { success: "Template dan draft versi pertama dibuat." };
}

const versionSchema = z.object({ versionId: z.string().min(1) });
export async function cloneTemplateVersion(_: CatalogActionState, formData: FormData): Promise<CatalogActionState> {
  const user = await requireUser(); const parsed = versionSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "kpi.catalog.configure") || !parsed.success) return { error: "Permintaan salin template tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const source = await tx.kpiTemplateVersion.findUnique({ where: { id: parsed.data.versionId }, include: { template: true, items: { include: { rubric: { include: { criteria: true } } } } } });
      if (!source || source.status !== "ACTIVE") throw new Error("Hanya versi aktif yang dapat disalin.");
      if (await tx.kpiTemplateVersion.findFirst({ where: { kpiTemplateId: source.kpiTemplateId, status: "DRAFT" } })) throw new Error("Template masih memiliki draft yang belum diselesaikan.");
      const latest = await tx.kpiTemplateVersion.aggregate({ where: { kpiTemplateId: source.kpiTemplateId }, _max: { versionNumber: true } });
      const draft = await tx.kpiTemplateVersion.create({ data: { kpiTemplateId: source.kpiTemplateId, versionNumber: (latest._max.versionNumber ?? source.versionNumber) + 1, totalWeight: source.totalWeight, ratingSchemeId: source.ratingSchemeId } });
      for (const item of source.items) {
        const copied = await tx.kpiTemplateItem.create({ data: { templateVersionId: draft.id, kpiDefinitionId: item.kpiDefinitionId, weight: item.weight, targetValue: item.targetValue, targetUnit: item.targetUnit, targetJson: item.targetJson ?? Prisma.JsonNull, formulaKey: item.formulaKey, formulaParams: item.formulaParams ?? Prisma.JsonNull, sourceType: item.sourceType, evidenceRequired: item.evidenceRequired, isMandatory: item.isMandatory, sortOrder: item.sortOrder } });
        if (item.rubric) await tx.kpiRubric.create({ data: { templateItemId: copied.id, name: item.rubric.name, description: item.rubric.description, criteria: { create: item.rubric.criteria.map((criterion) => ({ criterionText: criterion.criterionText, points: criterion.points, isMandatory: criterion.isMandatory, sortOrder: criterion.sortOrder })) } } });
      }
      await tx.auditEvent.create({ data: { actorId: user.id, action: "clone_kpi_template_version", subjectType: "KpiTemplateVersion", subjectId: draft.id, beforeJson: { sourceId: source.id, version: source.versionNumber }, afterJson: { version: draft.versionNumber } } });
    });
  } catch (error) { return { error: message(error) }; }
  refresh(); return { success: "Draft template baru dibuat." };
}

const templateSchemeSchema = z.object({ versionId: z.string().min(1), ratingSchemeId: z.string().min(1) });
export async function setTemplateRatingScheme(_: CatalogActionState, formData: FormData): Promise<CatalogActionState> {
  const user = await requireUser(); const parsed = templateSchemeSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "kpi.catalog.configure") || !parsed.success) return { error: "Permintaan perubahan skala tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const [version, scheme] = await Promise.all([
        tx.kpiTemplateVersion.findUnique({ where: { id: parsed.data.versionId } }),
        tx.kpiRatingScheme.findFirst({ where: { id: parsed.data.ratingSchemeId, isActive: true } }),
      ]);
      if (!version || version.status !== "DRAFT") throw new Error("Hanya draft template yang dapat diubah.");
      if (!scheme) throw new Error("Skala predikat aktif tidak ditemukan.");
      await tx.kpiTemplateVersion.update({ where: { id: version.id }, data: { ratingSchemeId: scheme.id } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "update_template_rating_scheme", subjectType: "KpiTemplateVersion", subjectId: version.id, beforeJson: { ratingSchemeId: version.ratingSchemeId }, afterJson: { ratingSchemeId: scheme.id } } });
    });
  } catch (error) { return { error: message(error) }; }
  refresh(); return { success: "Skala draft template diperbarui." };
}

const itemSchema = z.object({
  itemId: optionalId,
  versionId: z.string().min(1),
  definitionId: z.string().min(1),
  weight: z.coerce.number().gt(0).max(100),
  targetValue: z.preprocess((value) => value === "" ? undefined : value, z.coerce.number().min(0).optional()),
  targetUnit: z.string().trim().min(1).max(30),
  formulaKey: z.enum(["higher_is_better", "lower_is_better", "zero_tolerance", "rubric"]),
  sourceType: z.enum(["system", "import", "cross_role", "supervisor", "manager", "employee"]),
  cadence: z.enum(["daily", "weekly", "period"]),
  failureLimit: z.preprocess((value) => value === "" ? undefined : value, z.coerce.number().min(0).optional()),
  fullScoreLimit: z.preprocess((value) => value === "" ? undefined : value, z.coerce.number().min(0).optional()),
  evidenceRequired: z.enum(["true", "false"]),
  isMandatory: z.enum(["true", "false"]),
  sortOrder: z.coerce.number().int().positive().max(1000),
  rubricName: z.string().trim().max(150).optional(),
  rubricCriteria: z.string().trim().max(5000).optional(),
});

function parsedCriteria(value?: string) {
  if (!value) return [];
  return value.split(/\r?\n/).filter(Boolean).map((line, index) => {
    const [text, pointsRaw] = line.split("|").map((part) => part.trim());
    const points = Number(pointsRaw);
    if (!text || !Number.isFinite(points) || points <= 0) throw new Error(`Kriteria baris ${index + 1} harus memakai format teks|poin.`);
    return { criterionText: text, points, sortOrder: index + 1 };
  });
}

export async function saveTemplateItem(_: CatalogActionState, formData: FormData): Promise<CatalogActionState> {
  const user = await requireUser(); const parsed = itemSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "kpi.catalog.configure")) return { error: "Hanya Super Admin yang dapat mengubah template." };
  if (!parsed.success) return { error: "Periksa indikator, bobot, target, formula, cadence, dan parameter." };
  try {
    const criteria = parsedCriteria(parsed.data.rubricCriteria);
    if (parsed.data.formulaKey === "rubric" && !criteria.length) throw new Error("Formula rubric wajib memiliki minimal satu kriteria.");
    await prisma.$transaction(async (tx) => {
      const [version, definition] = await Promise.all([tx.kpiTemplateVersion.findUnique({ where: { id: parsed.data.versionId } }), tx.kpiDefinition.findFirst({ where: { id: parsed.data.definitionId, isActive: true } })]);
      if (!version || version.status !== "DRAFT" || !definition) throw new Error("Draft template atau indikator aktif tidak ditemukan.");
      const duplicate = await tx.kpiTemplateItem.findFirst({ where: { templateVersionId: version.id, kpiDefinitionId: definition.id, id: parsed.data.itemId ? { not: parsed.data.itemId } : undefined } });
      if (duplicate) throw new Error("Indikator sudah ada pada draft ini.");
      if (["higher_is_better", "lower_is_better"].includes(parsed.data.formulaKey) && (!parsed.data.targetValue || parsed.data.targetValue <= 0)) throw new Error("Formula otomatis membutuhkan target lebih besar dari nol.");
      if (parsed.data.formulaKey === "lower_is_better" && (parsed.data.failureLimit == null || parsed.data.failureLimit <= (parsed.data.targetValue ?? 0))) throw new Error("Failure limit harus lebih besar dari target.");
      if (parsed.data.formulaKey === "zero_tolerance" && (parsed.data.fullScoreLimit == null || parsed.data.failureLimit == null || parsed.data.failureLimit <= parsed.data.fullScoreLimit)) throw new Error("Zero tolerance membutuhkan full score limit dan failure limit yang lebih besar.");
      const targetJson: Prisma.InputJsonObject = { cadence: parsed.data.cadence, ...(parsed.data.failureLimit != null ? { failure_limit: parsed.data.failureLimit } : {}), ...(parsed.data.fullScoreLimit != null ? { full_score_limit: parsed.data.fullScoreLimit } : {}) };
      const values = { kpiDefinitionId: definition.id, weight: parsed.data.weight, targetValue: parsed.data.targetValue ?? null, targetUnit: parsed.data.targetUnit, targetJson, formulaKey: parsed.data.formulaKey, formulaParams: { cadence: parsed.data.cadence }, sourceType: parsed.data.sourceType, evidenceRequired: parsed.data.evidenceRequired === "true", isMandatory: parsed.data.isMandatory === "true", sortOrder: parsed.data.sortOrder };
      const before = parsed.data.itemId ? await tx.kpiTemplateItem.findFirst({ where: { id: parsed.data.itemId, templateVersionId: version.id } }) : null;
      if (parsed.data.itemId && !before) throw new Error("Item draft tidak ditemukan.");
      const item = before ? await tx.kpiTemplateItem.update({ where: { id: before.id }, data: values }) : await tx.kpiTemplateItem.create({ data: { templateVersionId: version.id, ...values } });
      await tx.kpiRubric.deleteMany({ where: { templateItemId: item.id } });
      if (criteria.length) await tx.kpiRubric.create({ data: { templateItemId: item.id, name: parsed.data.rubricName || `Rubrik ${definition.name}`, criteria: { create: criteria } } });
      const total = await tx.kpiTemplateItem.aggregate({ where: { templateVersionId: version.id }, _sum: { weight: true } });
      await tx.kpiTemplateVersion.update({ where: { id: version.id }, data: { totalWeight: total._sum.weight ?? 0 } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: before ? "update_kpi_template_item" : "create_kpi_template_item", subjectType: "KpiTemplateItem", subjectId: item.id, beforeJson: before ? { definitionId: before.kpiDefinitionId, weight: before.weight.toString(), target: before.targetValue?.toString() ?? null } : undefined, afterJson: { definitionId: definition.id, weight: parsed.data.weight, target: parsed.data.targetValue ?? null, formula: parsed.data.formulaKey, source: parsed.data.sourceType, cadence: parsed.data.cadence } } });
    });
  } catch (error) { return { error: message(error) }; }
  refresh(); return { success: parsed.data.itemId ? "Item template diperbarui." : "Item ditambahkan ke draft." };
}

const itemActionSchema = z.object({ itemId: z.string().min(1), versionId: z.string().min(1) });
export async function removeTemplateItem(_: CatalogActionState, formData: FormData): Promise<CatalogActionState> {
  const user = await requireUser(); const parsed = itemActionSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "kpi.catalog.configure") || !parsed.success) return { error: "Permintaan hapus item tidak valid." };
  try {
    await prisma.$transaction(async (tx) => {
      const version = await tx.kpiTemplateVersion.findUnique({ where: { id: parsed.data.versionId } });
      const item = await tx.kpiTemplateItem.findFirst({ where: { id: parsed.data.itemId, templateVersionId: parsed.data.versionId } });
      if (!version || version.status !== "DRAFT" || !item) throw new Error("Hanya item pada draft yang dapat dihapus.");
      await tx.kpiTemplateItem.delete({ where: { id: item.id } });
      const total = await tx.kpiTemplateItem.aggregate({ where: { templateVersionId: version.id }, _sum: { weight: true } });
      await tx.kpiTemplateVersion.update({ where: { id: version.id }, data: { totalWeight: total._sum.weight ?? 0 } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "remove_kpi_template_item", subjectType: "KpiTemplateItem", subjectId: item.id, beforeJson: { definitionId: item.kpiDefinitionId, weight: item.weight.toString() } } });
    });
  } catch (error) { return { error: message(error) }; }
  refresh(); return { success: "Item dihapus dari draft." };
}

const activateTemplateSchema = z.object({ versionId: z.string().min(1), effectiveFrom: z.string().regex(/^\d{4}-\d{2}-\d{2}$/) });
export async function activateTemplateVersion(_: CatalogActionState, formData: FormData): Promise<CatalogActionState> {
  const user = await requireUser(); const parsed = activateTemplateSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "kpi.catalog.configure") || !parsed.success) return { error: "Permintaan aktivasi template tidak valid." };
  const effectiveFrom = new Date(`${parsed.data.effectiveFrom}T00:00:00.000Z`);
  try {
    await prisma.$transaction(async (tx) => {
      const version = await tx.kpiTemplateVersion.findUnique({ where: { id: parsed.data.versionId }, include: { template: true, ratingScheme: true, items: { include: { definition: true, rubric: { include: { criteria: true } } } } } });
      if (!version || version.status !== "DRAFT") throw new Error("Draft template tidak ditemukan.");
      if (!version.ratingScheme?.isActive) throw new Error("Template wajib memakai skala predikat aktif.");
      const issues = validateTemplateConfiguration(version.template.name, version.items.map((item) => ({ name: item.definition.name, weight: item.weight.toNumber(), formula: item.formulaKey, target: item.targetValue?.toNumber() ?? null, targetJson: item.targetJson, formulaParams: item.formulaParams, definitionActive: item.definition.isActive, rubricCriteria: item.rubric?.criteria.length ?? 0 })));
      if (issues.length) throw new Error(issues.join(" "));
      const prior = await tx.kpiTemplateVersion.findFirst({ where: { kpiTemplateId: version.kpiTemplateId, status: "ACTIVE" }, orderBy: { versionNumber: "desc" } });
      if (prior && effectiveFrom <= (prior.effectiveFrom ?? prior.createdAt)) throw new Error("Tanggal efektif versi baru harus setelah versi aktif sebelumnya.");
      if (prior) await tx.kpiTemplateVersion.update({ where: { id: prior.id }, data: { status: "RETIRED", effectiveUntil: new Date(effectiveFrom.valueOf() - 86_400_000) } });
      const checksum = createHash("sha256").update(JSON.stringify(version.items.map((item) => ({ definitionId: item.kpiDefinitionId, weight: item.weight.toString(), target: item.targetValue?.toString(), formula: item.formulaKey, source: item.sourceType, targetJson: item.targetJson })))).digest("hex");
      await tx.kpiTemplateVersion.update({ where: { id: version.id }, data: { status: "ACTIVE", effectiveFrom, activatedAt: new Date(), activatedById: user.id, checksum, totalWeight: 100 } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "activate_kpi_template_version", subjectType: "KpiTemplateVersion", subjectId: version.id, beforeJson: { status: version.status }, afterJson: { status: "ACTIVE", effectiveFrom: parsed.data.effectiveFrom, checksum } } });
    });
  } catch (error) { return { error: message(error) }; }
  refresh(); return { success: "Versi template diaktifkan; versi sebelumnya dipensiunkan." };
}

const assignmentSchema = z.object({ employeeId: z.string().min(1), supervisorId: z.string().min(1), effectiveFrom: z.string().regex(/^\d{4}-\d{2}-\d{2}$/) });
export async function saveAssignment(_: CatalogActionState, formData: FormData): Promise<CatalogActionState> {
  const user = await requireUser(); const parsed = assignmentSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "kpi.assignments.manage")) return { error: "Anda tidak berwenang mengubah assignment penilai." };
  if (!parsed.success) return { error: "Pilih karyawan, penilai, dan tanggal efektif." };
  const effectiveFrom = new Date(`${parsed.data.effectiveFrom}T00:00:00.000Z`);
  if (effectiveFrom > new Date()) return { error: "Tanggal efektif assignment tidak boleh di masa depan." };
  try {
    await prisma.$transaction(async (tx) => {
      const [employee, reviewer] = await Promise.all([
        tx.employee.findUnique({ where: { id: parsed.data.employeeId }, include: { position: true } }),
        tx.employee.findUnique({ where: { id: parsed.data.supervisorId }, include: { user: true } }),
      ]);
      const expectedRole = employee?.position.code === "POS-SPV" ? "owner_manager" : "supervisor";
      if (!employee || !reviewer || employee.id === reviewer.id || employee.status !== "ACTIVE" || reviewer.status !== "ACTIVE" || employee.branchId !== reviewer.branchId || !reviewer.user?.isActive || reviewer.user.role !== expectedRole) throw new Error(`Penilai harus aktif, satu cabang, berbeda dari karyawan, dan memiliki role ${expectedRole}.`);
      const current = await tx.employeePlacement.findFirst({ where: { employeeId: employee.id, effectiveUntil: null }, orderBy: { effectiveFrom: "desc" } });
      if (!current || effectiveFrom < current.effectiveFrom) throw new Error("Tanggal efektif assignment tidak boleh sebelum awal penempatan aktif.");
      if (effectiveFrom.getTime() === current.effectiveFrom.getTime()) {
        await tx.employeePlacement.update({ where: { id: current.id }, data: { supervisorId: reviewer.id, notes: "Koreksi assignment penilai" } });
      } else {
        await tx.employeePlacement.update({ where: { id: current.id }, data: { effectiveUntil: new Date(effectiveFrom.valueOf() - 86_400_000) } });
        await tx.employeePlacement.create({ data: { employeeId: employee.id, positionId: employee.positionId, branchId: employee.branchId, supervisorId: reviewer.id, effectiveFrom, notes: "Perubahan assignment penilai" } });
      }
      await tx.employee.update({ where: { id: employee.id }, data: { supervisorId: reviewer.id } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "update_kpi_reviewer_assignment", subjectType: "Employee", subjectId: employee.id, beforeJson: { supervisorId: employee.supervisorId }, afterJson: { supervisorId: reviewer.id, effectiveFrom: parsed.data.effectiveFrom } } });
    });
  } catch (error) { return { error: message(error) }; }
  refresh(); return { success: "Assignment penilai dan histori penempatan diperbarui." };
}
