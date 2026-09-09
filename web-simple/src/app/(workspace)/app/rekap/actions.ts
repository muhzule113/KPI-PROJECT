"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { actionError } from "@/lib/action-error";
import { prisma } from "@/lib/prisma";
import { requireRole } from "@/modules/access/current-user";
import { finalizeMonthlyKpi, reopenFinalizedKpi } from "@/modules/kpi/monthly-operations";

export type MonthlyState = { error?: string; success?: string };
const base = z.object({ monthlyKpiId: z.string().min(1), rowVersion: z.coerce.number().int().positive() });

export async function finalizeMonthlyAction(_: MonthlyState, formData: FormData): Promise<MonthlyState> {
  const user = await requireRole("MANAGER");
  const parsed = base.extend({ noScoreReason: z.string().trim().max(1000).optional() }).safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Data finalisasi tidak valid." };
  try {
    await prisma.$transaction((tx) => finalizeMonthlyKpi(tx, user, parsed.data));
  } catch (error) {
    return { error: actionError(error, "KPI bulanan tidak dapat difinalkan.") };
  }
  revalidatePath("/app/rekap");
  revalidatePath("/app/kpi-saya");
  return { success: "KPI bulanan berhasil difinalkan." };
}

export async function reopenMonthlyAction(_: MonthlyState, formData: FormData): Promise<MonthlyState> {
  const user = await requireRole("ADMIN");
  const parsed = base.extend({ reason: z.string().trim().min(1).max(1000) }).safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Alasan membuka kembali hasil wajib diisi." };
  try {
    await prisma.$transaction((tx) => reopenFinalizedKpi(tx, user, parsed.data));
  } catch (error) {
    return { error: actionError(error, "KPI bulanan tidak dapat dibuka kembali.") };
  }
  revalidatePath("/app/rekap");
  revalidatePath("/app/kpi-saya");
  return { success: "KPI dibuka kembali dan Manager telah diberi notifikasi." };
}
