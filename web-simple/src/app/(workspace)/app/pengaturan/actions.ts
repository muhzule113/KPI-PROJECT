"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { actionError } from "@/lib/action-error";
import { prisma } from "@/lib/prisma";
import { isValidUsername, normalizeUsername } from "@/lib/username";
import { requireRole } from "@/modules/access/current-user";
import { createAccount, saveBranch, savePosition, updateAccount, updateEmployeeProfile } from "@/modules/admin/organization-operations";
import {
  activateRatingDraft,
  activateTemplate,
  discardRatingDraft,
  discardTemplateDraft,
  removeIndicator,
  saveIndicator,
  saveRatingDraft,
  saveTemplateName,
  startRatingDraft,
  startTemplateDraft,
} from "@/modules/admin/template-operations";
import { createPeriod, openPeriod, savePeriodTemplateSelections } from "@/modules/kpi/period-operations";

export type SettingsState = { error?: string; success?: string };
const text = z.string().trim().min(1).max(120);
const username = z.string().transform(normalizeUsername).refine(isValidUsername);
const errorMessage = (error: unknown) => actionError(error, "Pengaturan tidak dapat disimpan. Periksa data unik lalu coba lagi.");
const refreshSettings = () => revalidatePath("/app/pengaturan", "layout");

export async function saveBranchAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const parsed = z.object({ id: z.string().optional(), code: text, name: text }).safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Kode dan nama cabang wajib diisi." };
  try { await prisma.$transaction((tx) => saveBranch(tx, user, { ...parsed.data, isActive: formData.get("isActive") === "on" })); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Cabang tersimpan." };
}

export async function savePositionAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const parsed = z.object({ id: z.string().optional(), code: text, name: text }).safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Kode dan nama jabatan wajib diisi." };
  try { await prisma.$transaction((tx) => savePosition(tx, user, { ...parsed.data, isActive: formData.get("isActive") === "on", isKpiSubject: formData.get("isKpiSubject") === "on" })); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Jabatan tersimpan." };
}

export async function createAccountAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const parsed = z.object({
    name: text,
    username,
    password: z.string().min(10).max(128),
    role: z.enum(["ADMIN", "MANAGER", "SUPERVISOR", "EMPLOYEE"]),
    employeeNumber: z.string().trim().max(50).optional(),
    branchId: z.string().optional(),
    positionId: z.string().optional(),
    supervisorId: z.string().optional(),
    managerId: z.string().optional(),
    joinedAt: z.string().optional(),
  }).safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa nama, username, kata sandi, role, dan profil pegawai." };
  try {
    await prisma.$transaction((tx) => createAccount(tx, user, {
      ...parsed.data,
      joinedAt: parsed.data.joinedAt ? new Date(`${parsed.data.joinedAt}T00:00:00.000Z`) : undefined,
    }));
  } catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Akun berhasil dibuat." };
}

export async function updateAccountAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const parsed = z.object({ userId: z.string().min(1), name: text, username, password: z.string().max(128).optional() }).safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Data akun tidak valid." };
  try { await prisma.$transaction((tx) => updateAccount(tx, user, { ...parsed.data, password: parsed.data.password || undefined, isActive: formData.get("isActive") === "on" })); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Akun diperbarui." };
}

export async function updateEmployeeProfileAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const parsed = z.object({
    userId: z.string().min(1),
    employeeNumber: z.string().trim().min(1).max(50),
    branchId: z.string().min(1),
    positionId: z.string().min(1),
    supervisorId: z.string().optional(),
    managerId: z.string().optional(),
    joinedAt: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    endedAt: z.string().optional(),
  }).safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Profil organisasi pegawai tidak valid." };
  try {
    await prisma.$transaction((tx) => updateEmployeeProfile(tx, user, {
      ...parsed.data,
      supervisorId: parsed.data.supervisorId || undefined,
      managerId: parsed.data.managerId || undefined,
      joinedAt: new Date(`${parsed.data.joinedAt}T00:00:00.000Z`),
      endedAt: parsed.data.endedAt ? new Date(`${parsed.data.endedAt}T00:00:00.000Z`) : undefined,
    }));
  } catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Penempatan dan assignment pegawai diperbarui untuk periode berikutnya." };
}

export async function updateAccountProfileAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const account = z.object({
    userId: z.string().min(1),
    name: text,
    username,
    password: z.string().max(128).optional(),
  }).safeParse(Object.fromEntries(formData));
  if (!account.success) return { error: "Data akun tidak valid." };

  const hasProfile = formData.has("employeeNumber");
  const profile = z.object({
    userId: z.string().min(1),
    employeeNumber: z.string().trim().min(1).max(50),
    branchId: z.string().min(1),
    positionId: z.string().min(1),
    supervisorId: z.string().optional(),
    managerId: z.string().optional(),
    joinedAt: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
    endedAt: z.string().optional(),
  }).safeParse(Object.fromEntries(formData));
  if (hasProfile && !profile.success) return { error: "Profil organisasi pegawai tidak valid." };

  try {
    await prisma.$transaction(async (tx) => {
      await updateAccount(tx, user, {
        ...account.data,
        password: account.data.password || undefined,
        isActive: formData.get("isActive") === "on",
      });
      if (hasProfile && profile.success) await updateEmployeeProfile(tx, user, {
        ...profile.data,
        supervisorId: profile.data.supervisorId || undefined,
        managerId: profile.data.managerId || undefined,
        joinedAt: new Date(`${profile.data.joinedAt}T00:00:00.000Z`),
        endedAt: profile.data.endedAt ? new Date(`${profile.data.endedAt}T00:00:00.000Z`) : undefined,
      });
    });
  } catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: hasProfile ? "Akun dan penempatan diperbarui." : "Akun diperbarui." };
}

export async function saveIndicatorAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const parsed = z.object({
    id: z.string().optional(), versionId: z.string().min(1), code: text, name: text,
    description: z.string().trim().max(500).optional(), kind: z.enum(["NUMERIC", "RATING", "CHECKBOX", "CATEGORY", "SYSTEM", "IMPORTED"]), unit: text,
    aggregation: z.enum(["SUM", "AVERAGE", "LATEST", "COUNT"]), direction: z.enum(["HIGHER", "LOWER", "ZERO_TOLERANCE"]),
    target: z.coerce.number().finite(), failureLimit: z.preprocess((value) => value === "" ? undefined : value, z.coerce.number().finite().optional()),
    weight: z.coerce.number().positive().max(100), sortOrder: z.coerce.number().int().min(1).max(999),
  }).safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Konfigurasi indikator tidak valid." };
  try {
    const categoryBands = parsed.data.kind === "CATEGORY" ? categoryBandsFrom(formData) : undefined;
    await prisma.$transaction((tx) => saveIndicator(tx, user, { ...parsed.data, categoryBands }));
  }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Indikator draft tersimpan." };
}

function categoryBandsFrom(formData: FormData) {
  const keys = formData.getAll("categoryKey").map(String).map(Number).sort((left, right) => left - right);
  if (keys.length !== 5 || keys.some((key, index) => key !== index + 1)) throw new Error("Lima tingkat predikat wajib diisi.");
  return keys.map((sortOrder) => {
    const label = String(formData.get(`categoryLabel:${sortOrder}`) ?? "").trim();
    const raw = formData.get(`categoryThreshold:${sortOrder}`);
    const text = raw === null ? "" : String(raw).trim();
    const threshold = sortOrder === 5 ? null : text === "" ? Number.NaN : Number(text);
    if (!label || (sortOrder < 5 && !Number.isFinite(threshold))) throw new Error("Data predikat indikator tidak valid.");
    return { label, threshold, sortOrder, isActive: true };
  });
}

export async function removeIndicatorAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const id = z.string().min(1).safeParse(formData.get("indicatorId"));
  if (!id.success) return { error: "Indikator tidak valid." };
  try { await prisma.$transaction((tx) => removeIndicator(tx, user, id.data)); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Indikator dihapus dari draft." };
}

export async function activateTemplateAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const versionId = z.string().min(1).safeParse(formData.get("versionId"));
  if (!versionId.success) return { error: "Draft template tidak valid." };
  try { await prisma.$transaction((tx) => activateTemplate(tx, user, versionId.data)); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Versi template aktif dan siap dipakai periode baru." };
}

export async function saveTemplateNameAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const parsed = z.object({ templateId: z.string().min(1), name: text }).safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Nama template tidak valid." };
  try { await prisma.$transaction((tx) => saveTemplateName(tx, user, parsed.data.templateId, parsed.data.name)); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Nama template diperbarui." };
}

export async function startTemplateDraftAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const templateId = z.string().min(1).safeParse(formData.get("templateId"));
  if (!templateId.success) return { error: "Template tidak valid." };
  try { await prisma.$transaction((tx) => startTemplateDraft(tx, user, templateId.data)); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Draft revisi dibuat dari versi aktif." };
}

export async function discardTemplateDraftAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const versionId = z.string().min(1).safeParse(formData.get("versionId"));
  if (!versionId.success) return { error: "Draft template tidak valid." };
  try { await prisma.$transaction((tx) => discardTemplateDraft(tx, user, versionId.data)); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Draft dibatalkan; versi aktif tidak berubah." };
}

const ratingCodes = ["POOR", "FAIR", "GOOD", "VERY_GOOD", "STAR"] as const;

export async function saveRatingDraftAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const schemeId = z.string().min(1).safeParse(formData.get("schemeId"));
  const bandSchema = z.object({ label: text, minScore: z.coerce.number().min(0).max(100) });
  const parsedBands = ratingCodes.map((code, index) => bandSchema.safeParse({ label: formData.get(`label.${code}`), minScore: formData.get(`minScore.${code}`), sortOrder: index + 1 }));
  if (!schemeId.success || parsedBands.some((band) => !band.success)) return { error: "Lima predikat dan batas nilainya wajib valid." };
  const bands = parsedBands.map((band, index) => ({ code: ratingCodes[index], ...band.data!, sortOrder: index + 1 }));
  try { await prisma.$transaction((tx) => saveRatingDraft(tx, user, schemeId.data, bands)); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Draft predikat tersimpan." };
}

export async function startRatingDraftAction(state: SettingsState, formData: FormData): Promise<SettingsState> {
  void state; void formData;
  const user = await requireRole("ADMIN");
  try { await prisma.$transaction((tx) => startRatingDraft(tx, user)); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Draft skala predikat dibuat." };
}

export async function discardRatingDraftAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const schemeId = z.string().min(1).safeParse(formData.get("schemeId"));
  if (!schemeId.success) return { error: "Draft predikat tidak valid." };
  try { await prisma.$transaction((tx) => discardRatingDraft(tx, user, schemeId.data)); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Draft predikat dibatalkan." };
}

export async function activateRatingDraftAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const schemeId = z.string().min(1).safeParse(formData.get("schemeId"));
  if (!schemeId.success) return { error: "Draft predikat tidak valid." };
  try { await prisma.$transaction((tx) => activateRatingDraft(tx, user, schemeId.data)); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Skala predikat aktif untuk periode berikutnya." };
}

export async function createPeriodAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const parsed = z.object({ year: z.coerce.number().int().min(2020).max(2100), month: z.coerce.number().int().min(1).max(12) }).safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Bulan dan tahun tidak valid." };
  try { await prisma.$transaction((tx) => createPeriod(tx, user, parsed.data)); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Periode DRAFT berhasil dibuat." };
}

export async function savePeriodTemplateSelectionsAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const periodId = z.string().min(1).safeParse(formData.get("periodId"));
  if (!periodId.success) return { error: "Periode tidak valid." };
  const selections = [...formData.entries()]
    .filter(([name]) => name.startsWith("templateVersion:"))
    .map(([name, value]) => ({ positionId: name.slice("templateVersion:".length), templateVersionId: String(value) }));
  try { await prisma.$transaction((tx) => savePeriodTemplateSelections(tx, user, periodId.data, selections), { timeout: 15_000 }); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  return { success: "Versi template periode tersimpan." };
}

export async function openPeriodAction(_: SettingsState, formData: FormData): Promise<SettingsState> {
  const user = await requireRole("ADMIN");
  const periodId = z.string().min(1).safeParse(formData.get("periodId"));
  if (!periodId.success) return { error: "Periode tidak valid." };
  try { await prisma.$transaction((tx) => openPeriod(tx, user, periodId.data)); }
  catch (error) { return { error: errorMessage(error) }; }
  refreshSettings();
  revalidatePath("/app/harian");
  return { success: "Periode dibuka dan lembar harian dibuat." };
}
