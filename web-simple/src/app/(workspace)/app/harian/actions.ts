"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { actionError } from "@/lib/action-error";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/modules/access/current-user";
import { saveDailySheet } from "@/modules/kpi/daily-operations";
import { removeEvidence, uploadEvidence } from "@/modules/files/evidence-operations";

export type FormState = { error?: string; success?: string };

const sheetSchema = z.object({
  sheetId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
  workStatus: z.enum(["WORKED", "OFF", "PERMIT", "SICK"]),
  note: z.string().trim().max(1000).optional(),
  intent: z.enum(["save", "submit"]),
  correctionReason: z.string().trim().max(1000).optional(),
});

const evidenceSchema = z.object({ sheetId: z.string().min(1) });

function valuesFrom(formData: FormData) {
  const ids = formData.getAll("itemId").map(String);
  const values = formData.getAll("value");
  if (ids.length !== values.length) throw new Error("Daftar nilai indikator tidak lengkap.");
  return ids.map((itemId, index) => {
    const value = Number(values[index]);
    if (String(values[index]).trim() === "" || !Number.isFinite(value)) throw new Error("Seluruh nilai indikator wajib berupa angka.");
    return { itemId, value };
  });
}

export async function saveDailySheetAction(_: FormState, formData: FormData): Promise<FormState> {
  const user = await requireUser();
  const parsed = sheetSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa status kerja, catatan, dan versi lembar." };
  try {
    const values = parsed.data.workStatus === "WORKED" ? valuesFrom(formData) : [];
    await prisma.$transaction((tx) => saveDailySheet(tx, user, {
      ...parsed.data,
      values,
      submit: parsed.data.intent === "submit",
    }));
  } catch (error) {
    return { error: actionError(error, "Lembar harian tidak dapat disimpan. Muat ulang lalu coba lagi.") };
  }
  revalidatePath("/app/harian");
  revalidatePath("/app/review");
  revalidatePath("/app/rekap");
  return { success: parsed.data.intent === "submit" ? "Lembar dikirim." : "Draf tersimpan." };
}

export async function uploadEvidenceAction(_: FormState, formData: FormData): Promise<FormState> {
  const user = await requireUser();
  const parsed = evidenceSchema.safeParse(Object.fromEntries(formData));
  const file = formData.get("file");
  if (!parsed.success || !(file instanceof File) || !file.size) return { error: "Pilih file evidence yang valid." };
  try {
    await uploadEvidence(user, parsed.data.sheetId, file);
  } catch (error) {
    return { error: actionError(error, "Evidence tidak dapat diunggah. Coba lagi.") };
  }
  revalidatePath("/app/harian");
  return { success: "Evidence berhasil diunggah." };
}

export async function removeEvidenceAction(_: FormState, formData: FormData): Promise<FormState> {
  const user = await requireUser();
  const evidenceId = z.string().min(1).safeParse(formData.get("evidenceId"));
  if (!evidenceId.success) return { error: "Evidence tidak valid." };
  try { await removeEvidence(user, evidenceId.data); }
  catch (error) { return { error: actionError(error, "Evidence tidak dapat dihapus.") }; }
  revalidatePath("/app/harian");
  return { success: "Evidence dihapus." };
}
