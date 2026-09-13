"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { actionError } from "@/lib/action-error";
import { prisma } from "@/lib/prisma";
import { requireRole } from "@/modules/access/current-user";
import { reviewDailySheet } from "@/modules/kpi/daily-operations";
import { dailyValuesFromForm } from "@/modules/kpi/daily-value-form";

export type ReviewState = { error?: string; success?: string };

const schema = z.object({
  sheetId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
  decision: z.enum(["APPROVE", "CORRECT", "RETURN"]),
  workStatus: z.enum(["WORKED", "OFF", "PERMIT", "SICK"]).optional(),
  reason: z.string().trim().max(1000).optional(),
});

export async function reviewDailySheetAction(_: ReviewState, formData: FormData): Promise<ReviewState> {
  const user = await requireRole("MANAGER");
  const parsed = schema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Keputusan review atau data lembar tidak valid." };
  try {
    await prisma.$transaction((tx) => reviewDailySheet(tx, user, {
      ...parsed.data,
      values: parsed.data.decision === "CORRECT" && parsed.data.workStatus === "WORKED" ? dailyValuesFromForm(formData, "nilai koreksi") : [],
    }));
  } catch (error) {
    return { error: actionError(error, "Review tidak dapat disimpan. Muat ulang lalu coba lagi.") };
  }
  revalidatePath("/app/review");
  revalidatePath("/app/harian");
  revalidatePath("/app/rekap");
  return { success: parsed.data.decision === "RETURN" ? "Lembar dikembalikan kepada Supervisor." : "Review Manager tersimpan." };
}
