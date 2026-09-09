"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { CASHIER_MAPPING_FIELDS, DEFAULT_CASHIER_MAPPING, validateCashierMapping } from "@/modules/imports/mapping";

export type MappingActionState = { error?: string; success?: string };

const optionalId = z.preprocess((value) => value === "" ? undefined : value, z.string().min(1).optional());
const templateSchema = z.object({
  templateId: optionalId,
  name: z.string().trim().min(3).max(120),
  sourceApplication: z.string().trim().toUpperCase().min(2).max(60).regex(/^[A-Z0-9_-]+$/),
  description: z.string().trim().max(1000).optional(),
  isActive: z.enum(["true", "false"]),
});
const versionSchema = z.object({ versionId: z.string().min(1) });
const templateActionSchema = z.object({ templateId: z.string().min(1) });
const safeMessage = (error: unknown) => error instanceof Error && !error.message.toLowerCase().includes("prisma")
  ? error.message
  : "Mapping impor tidak dapat disimpan. Muat ulang lalu coba lagi.";
const refresh = () => {
  revalidatePath("/app/pengaturan/impor");
  revalidatePath("/app/pengaturan");
  revalidatePath("/app/impor");
};

export async function saveImportMappingTemplate(_: MappingActionState, formData: FormData): Promise<MappingActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "imports.configure")) return { error: "Anda tidak berwenang mengelola mapping impor." };
  const parsed = templateSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa nama, kode aplikasi sumber, deskripsi, dan status template." };

  try {
    await prisma.$transaction(async (tx) => {
      if (parsed.data.templateId) await tx.$queryRaw`SELECT id FROM import_mapping_templates WHERE id = ${parsed.data.templateId} FOR UPDATE`;
      const before = parsed.data.templateId
        ? await tx.importMappingTemplate.findUnique({ where: { id: parsed.data.templateId } })
        : null;
      if (parsed.data.templateId && !before) throw new Error("Template mapping tidak ditemukan.");
      if (before && before.sourceApplication !== parsed.data.sourceApplication) {
        const used = await tx.importBatch.count({ where: { mappingVersion: { mappingTemplateId: before.id } } });
        if (used) throw new Error("Aplikasi sumber tidak dapat diubah karena mapping sudah dipakai oleh batch impor.");
      }
      const values = {
        name: parsed.data.name,
        sourceApplication: parsed.data.sourceApplication,
        description: parsed.data.description || null,
        isActive: parsed.data.isActive === "true",
      };
      const template = before
        ? await tx.importMappingTemplate.update({ where: { id: before.id }, data: values })
        : await tx.importMappingTemplate.create({ data: values });
      if (!before) await tx.importMappingVersion.create({ data: { mappingTemplateId: template.id, versionNumber: 1, mappingsJson: DEFAULT_CASHIER_MAPPING, isActive: false } });
      await tx.auditEvent.create({ data: {
        actorId: user.id,
        action: before ? "update_import_mapping_template" : "create_import_mapping_template",
        subjectType: "ImportMappingTemplate",
        subjectId: template.id,
        beforeJson: before ? { name: before.name, sourceApplication: before.sourceApplication, description: before.description, isActive: before.isActive } : undefined,
        afterJson: values,
      } });
    });
  } catch (error) { return { error: safeMessage(error) }; }
  refresh();
  return { success: parsed.data.templateId ? "Template mapping diperbarui." : "Template dan draft v1 dibuat." };
}

export async function startImportMappingDraft(_: MappingActionState, formData: FormData): Promise<MappingActionState> {
  const user = await requireUser();
  const parsed = templateActionSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "imports.configure") || !parsed.success) return { error: "Permintaan pembuatan draft tidak valid." };

  try {
    const created = await prisma.$transaction(async (tx) => {
      await tx.$queryRaw`SELECT id FROM import_mapping_templates WHERE id = ${parsed.data.templateId} FOR UPDATE`;
      const template = await tx.importMappingTemplate.findUnique({
        where: { id: parsed.data.templateId },
        include: { versions: { orderBy: { versionNumber: "desc" }, include: { _count: { select: { batches: true } } } } },
      });
      if (!template) throw new Error("Template mapping tidak ditemukan.");
      const active = template.versions.find((version) => version.isActive);
      const reusable = template.versions.find((version) => !version.isActive && version._count.batches === 0 && version.versionNumber > (active?.versionNumber ?? 0));
      if (reusable) return false;
      const source = active ?? template.versions[0];
      const draft = await tx.importMappingVersion.create({ data: {
        mappingTemplateId: template.id,
        versionNumber: (template.versions[0]?.versionNumber ?? 0) + 1,
        mappingsJson: source?.mappingsJson ?? DEFAULT_CASHIER_MAPPING,
        isActive: false,
      } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "create_import_mapping_draft", subjectType: "ImportMappingVersion", subjectId: draft.id, afterJson: { templateId: template.id, versionNumber: draft.versionNumber, copiedFromId: source?.id ?? null } } });
      return true;
    });
    refresh();
    return { success: created ? "Draft mapping baru dibuat." : "Draft terbaru sudah tersedia." };
  } catch (error) { return { error: safeMessage(error) }; }
}

export async function saveImportMappingDraft(_: MappingActionState, formData: FormData): Promise<MappingActionState> {
  const user = await requireUser();
  const parsed = versionSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "imports.configure") || !parsed.success) return { error: "Permintaan penyimpanan draft tidak valid." };
  let mapping: ReturnType<typeof validateCashierMapping>;
  try {
    mapping = validateCashierMapping(Object.fromEntries(CASHIER_MAPPING_FIELDS.map((field) => [field.key, formData.get(field.key)])));
  } catch (error) { return { error: safeMessage(error) }; }

  try {
    await prisma.$transaction(async (tx) => {
      await tx.$queryRaw`SELECT id FROM import_mapping_versions WHERE id = ${parsed.data.versionId} FOR UPDATE`;
      const version = await tx.importMappingVersion.findUnique({ where: { id: parsed.data.versionId }, include: { _count: { select: { batches: true } } } });
      if (!version) throw new Error("Versi mapping tidak ditemukan.");
      const latest = await tx.importMappingVersion.aggregate({ where: { mappingTemplateId: version.mappingTemplateId }, _max: { versionNumber: true } });
      if (version.isActive || version._count.batches > 0 || latest._max.versionNumber !== version.versionNumber) throw new Error("Hanya draft terbaru yang belum dipakai yang dapat diubah.");
      await tx.importMappingVersion.update({ where: { id: version.id }, data: { mappingsJson: mapping } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "save_import_mapping_draft", subjectType: "ImportMappingVersion", subjectId: version.id, beforeJson: { mappings: version.mappingsJson }, afterJson: { mappings: mapping } } });
    });
  } catch (error) { return { error: safeMessage(error) }; }
  refresh();
  return { success: "Draft mapping disimpan." };
}

export async function activateImportMappingVersion(_: MappingActionState, formData: FormData): Promise<MappingActionState> {
  const user = await requireUser();
  const parsed = versionSchema.safeParse(Object.fromEntries(formData));
  if (!hasCapability(user, "imports.configure") || !parsed.success) return { error: "Permintaan aktivasi mapping tidak valid." };

  try {
    await prisma.$transaction(async (tx) => {
      const initial = await tx.importMappingVersion.findUnique({ where: { id: parsed.data.versionId }, select: { mappingTemplateId: true } });
      if (!initial) throw new Error("Versi mapping tidak ditemukan.");
      await tx.$queryRaw`SELECT id FROM import_mapping_templates WHERE id = ${initial.mappingTemplateId} FOR UPDATE`;
      const version = await tx.importMappingVersion.findUnique({ where: { id: parsed.data.versionId }, include: { template: true, _count: { select: { batches: true } } } });
      if (!version) throw new Error("Versi mapping tidak ditemukan.");
      const latest = await tx.importMappingVersion.aggregate({ where: { mappingTemplateId: version.mappingTemplateId }, _max: { versionNumber: true } });
      if (!version.template.isActive) throw new Error("Aktifkan template sebelum mengaktifkan versinya.");
      if (version.isActive || version._count.batches > 0 || latest._max.versionNumber !== version.versionNumber) throw new Error("Hanya draft terbaru yang belum dipakai yang dapat diaktifkan.");
      validateCashierMapping(version.mappingsJson as Record<string, unknown>);
      const previous = await tx.importMappingVersion.findFirst({ where: { mappingTemplateId: version.mappingTemplateId, isActive: true }, select: { id: true, versionNumber: true } });
      await tx.importMappingVersion.updateMany({ where: { mappingTemplateId: version.mappingTemplateId, id: { not: version.id } }, data: { isActive: false } });
      await tx.importMappingVersion.update({ where: { id: version.id }, data: { isActive: true } });
      await tx.auditEvent.create({ data: { actorId: user.id, action: "activate_import_mapping", subjectType: "ImportMappingVersion", subjectId: version.id, beforeJson: previous ? { activeId: previous.id, versionNumber: previous.versionNumber } : undefined, afterJson: { activeId: version.id, versionNumber: version.versionNumber } } });
    });
  } catch (error) { return { error: safeMessage(error) }; }
  refresh();
  return { success: "Versi mapping diaktifkan." };
}
