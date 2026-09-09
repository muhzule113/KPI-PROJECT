"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { requireUser } from "@/modules/access/current-user";
import { assessDailyEntry } from "@/modules/kpi/daily-assessment";
import { fulfilledCriterionIds, ratingCodeFromJson } from "@/modules/kpi/daily-values";

export type DailyActionState = { error?: string; success?: string };

const optionalNumber = z.preprocess((value) => value === "" || value == null ? undefined : value, z.coerce.number().finite().min(-1_000_000_000).max(1_000_000_000).optional());
const schema = z.object({
  entryId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
  role: z.enum(["supervisor", "manager"]),
  decision: z.enum(["approved", "revision_required"]),
  note: z.string().trim().max(1000).optional(),
  actual: optionalNumber,
  ratingCode: z.string().trim().max(100).optional(),
});

const bulkSchema = z.object({
  employeeKpiId: z.string().min(1),
  entryDate: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
});

const message = (error: unknown) => error instanceof Error && !error.message.toLowerCase().includes("prisma")
  ? error.message
  : "Penilaian harian tidak dapat disimpan. Muat ulang lalu coba lagi.";

export async function saveDailyAssessment(_: DailyActionState, formData: FormData): Promise<DailyActionState> {
  const user = await requireUser();
  const parsed = schema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periksa keputusan, nilai, dan panjang catatan." };
  try {
    await prisma.$transaction((tx) => assessDailyEntry(tx, user, {
      ...parsed.data,
      criterionIds: formData.getAll("criterion").map(String),
    }));
  } catch (error) {
    return { error: message(error) };
  }
  revalidatePath("/app/tim/harian");
  revalidatePath("/app/tim");
  return { success: parsed.data.decision === "approved" ? "Penilaian harian disetujui." : "Permintaan koreksi dikirim." };
}

export async function approveAllDailyAssessments(_: DailyActionState, formData: FormData): Promise<DailyActionState> {
  const user = await requireUser();
  const parsed = bulkSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Karyawan atau tanggal penilaian tidak valid." };
  try {
    const approved = await prisma.$transaction(async (tx) => {
      const entries = await tx.kpiDailyEntry.findMany({
        where: {
          entryDate: new Date(`${parsed.data.entryDate}T00:00:00.000Z`),
          managerStatus: "PENDING",
          supervisorStatus: "APPROVED",
          item: { employeeKpi: { id: parsed.data.employeeKpiId, positionCodeSnapshot: { not: "POS-SPV" } } },
        },
        orderBy: { id: "asc" },
        select: {
          id: true,
          rowVersion: true,
          supervisorActualDecimal: true,
          supervisorActualJson: true,
          supervisorAnswersJson: true,
        },
      });
      if (!entries.length) throw new Error("Tidak ada penilaian Supervisor yang menunggu persetujuan Manager.");
      for (const entry of entries) {
        await assessDailyEntry(tx, user, {
          entryId: entry.id,
          rowVersion: entry.rowVersion,
          role: "manager",
          decision: "approved",
          actual: entry.supervisorActualDecimal?.toNumber(),
          ratingCode: ratingCodeFromJson(entry.supervisorActualJson),
          criterionIds: fulfilledCriterionIds(entry.supervisorAnswersJson),
        });
      }
      return entries.length;
    });
    revalidatePath("/app/tim/harian");
    revalidatePath("/app/tim");
    return { success: `${approved} indikator disetujui.` };
  } catch (error) {
    return { error: message(error) };
  }
}
