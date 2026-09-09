"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import type { PeriodStatus } from "@/generated/prisma/client";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { syncPeriodFactsThrough } from "@/modules/kpi/period-sync";
import { transitionPeriod, validatePeriodReadiness } from "@/modules/kpi/periods";

export type PeriodActionState = { error?: string; success?: string };

const dateValue = z.string().regex(/^\d{4}-\d{2}-\d{2}$/).transform((value, context) => {
  const date = new Date(`${value}T00:00:00.000Z`);
  if (Number.isNaN(date.valueOf()) || date.toISOString().slice(0, 10) !== value) {
    context.addIssue({ code: "custom", message: "Tanggal tidak valid." });
    return z.NEVER;
  }
  return date;
});
const dateTimeValue = z.string().regex(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/).transform((value, context) => {
  const date = new Date(`${value}:00+08:00`);
  if (Number.isNaN(date.valueOf())) {
    context.addIssue({ code: "custom", message: "Waktu tidak valid." });
    return z.NEVER;
  }
  return date;
});
const periodSchema = z.object({
  periodId: z.string().optional(),
  name: z.string().trim().min(3).max(100),
  year: z.coerce.number().int().min(2000).max(2100),
  month: z.coerce.number().int().min(1).max(12),
  startDate: dateValue,
  endDate: dateValue,
  submissionDeadline: dateTimeValue,
  reviewDeadline: dateTimeValue,
  approvalDeadline: dateTimeValue,
});
const safeMessage = (error: unknown) => error instanceof Error && !error.message.toLowerCase().includes("prisma") ? error.message : "Periode tidak dapat disimpan. Periksa data lalu coba lagi.";

export async function savePeriod(_: PeriodActionState, formData: FormData): Promise<PeriodActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "kpi.period.manage")) return { error: "Anda tidak berwenang mengelola periode KPI." };
  const parsed = periodSchema.safeParse(Object.fromEntries(formData));
  const branchIds = [...new Set(formData.getAll("branchId").map(String).filter(Boolean))];
  if (!parsed.success || !branchIds.length) return { error: "Lengkapi periode, deadline, dan minimal satu cabang." };
  const data = parsed.data;
  if (data.endDate < data.startDate) return { error: "Tanggal selesai tidak boleh sebelum tanggal mulai." };
  if (data.startDate.getUTCFullYear() !== data.year || data.startDate.getUTCMonth() + 1 !== data.month || data.endDate.getUTCFullYear() !== data.year || data.endDate.getUTCMonth() + 1 !== data.month) return { error: "Tanggal mulai dan selesai harus berada pada bulan dan tahun periode." };
  if (!(data.submissionDeadline < data.reviewDeadline && data.reviewDeadline < data.approvalDeadline)) return { error: "Urutan deadline harus input, review, lalu approval." };

  try {
    await prisma.$transaction(async (tx) => {
      const validBranches = await tx.branch.count({ where: { id: { in: branchIds }, isActive: true } });
      if (validBranches !== branchIds.length) throw new Error("Satu atau lebih cabang tidak ditemukan atau tidak aktif.");
      const existing = data.periodId ? await tx.kpiPeriod.findUnique({ where: { id: data.periodId } }) : null;
      if (data.periodId && !existing) throw new Error("Periode KPI tidak ditemukan.");
      if (existing && existing.status !== "DRAFT") throw new Error("Hanya periode Draft yang dapat diubah.");
      const duplicate = await tx.kpiPeriod.findFirst({ where: { year: data.year, month: data.month, id: existing ? { not: existing.id } : undefined } });
      if (duplicate) throw new Error("Periode untuk bulan dan tahun tersebut sudah ada.");
      const values = { name: data.name, year: data.year, month: data.month, startDate: data.startDate, endDate: data.endDate, submissionDeadline: data.submissionDeadline, reviewDeadline: data.reviewDeadline, approvalDeadline: data.approvalDeadline };
      const period = existing
        ? await tx.kpiPeriod.update({ where: { id: existing.id }, data: values })
        : await tx.kpiPeriod.create({ data: { ...values, createdById: user.id } });
      await tx.kpiPeriodBranch.deleteMany({ where: { periodId: period.id, branchId: { notIn: branchIds } } });
      await tx.kpiPeriodBranch.createMany({ data: branchIds.map((branchId) => ({ periodId: period.id, branchId })), skipDuplicates: true });
      await tx.auditEvent.create({ data: { actorId: user.id, action: existing ? "update_period" : "create_period", subjectType: "KpiPeriod", subjectId: period.id, beforeJson: existing ? { name: existing.name, year: existing.year, month: existing.month } : undefined, afterJson: { name: period.name, year: period.year, month: period.month, branchIds } } });
    });
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath("/app/pengaturan/periode");
  revalidatePath("/app/pengaturan");
  return { success: data.periodId ? "Periode Draft diperbarui." : "Periode Draft dibuat." };
}

const readinessSchema = z.object({ periodId: z.string().min(1) });
export async function checkReadiness(_: PeriodActionState, formData: FormData): Promise<PeriodActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "kpi.period.manage")) return { error: "Anda tidak berwenang memeriksa periode KPI." };
  const parsed = readinessSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periode tidak valid." };
  try {
    const result = await prisma.$transaction((tx) => validatePeriodReadiness(tx, parsed.data.periodId));
    return result.isReady ? { success: `Periode siap. ${result.eligibleCount} karyawan eligible.` } : { error: result.issues.join(" ") };
  } catch (error) {
    return { error: safeMessage(error) };
  }
}

const syncSchema = z.object({ periodId: z.string().min(1), throughDate: dateValue });
export async function syncPeriodFacts(_: PeriodActionState, formData: FormData): Promise<PeriodActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "kpi.sync")) return { error: "Anda tidak berwenang menjalankan sinkronisasi KPI." };
  const parsed = syncSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Periode dan tanggal sinkronisasi tidak valid." };
  const today = new Date(`${new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Makassar", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date())}T00:00:00.000Z`);
  if (parsed.data.throughDate > today) return { error: "Tanggal sinkronisasi tidak boleh di masa depan." };

  try {
    const result = await prisma.$transaction((tx) => syncPeriodFactsThrough(tx, parsed.data.periodId, parsed.data.throughDate, user.id), { maxWait: 10_000, timeout: 120_000 });
    revalidatePath("/app/pengaturan/periode");
    revalidatePath("/app/tim");
    revalidatePath("/app");
    return { success: `${result.name}: fakta ${result.count} KPI disinkronkan secara idempoten.` };
  } catch (error) {
    await prisma.auditEvent.create({ data: { actorId: user.id, action: "daily_kpi_sync_failed", subjectType: "KpiPeriod", subjectId: parsed.data.periodId, afterJson: { throughDate: parsed.data.throughDate.toISOString().slice(0, 10), error: safeMessage(error) } } }).catch(() => undefined);
    return { error: safeMessage(error) };
  }
}

const transitionSchema = z.object({ periodId: z.string().min(1), next: z.enum(["READY", "OPEN", "SUBMISSION_CLOSED", "IN_REVIEW", "WAITING_APPROVAL", "PUBLISHED", "LOCKED", "CANCELLED"]), reason: z.string().trim().max(1000).optional() });
export async function changePeriodStatus(_: PeriodActionState, formData: FormData): Promise<PeriodActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "kpi.period.manage")) return { error: "Anda tidak berwenang mengubah status periode." };
  const parsed = transitionSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Permintaan perubahan status tidak valid." };
  try {
    await prisma.$transaction((tx) => transitionPeriod(tx, parsed.data.periodId, parsed.data.next as PeriodStatus, user.id, parsed.data.reason));
  } catch (error) {
    return { error: safeMessage(error) };
  }
  revalidatePath("/app/pengaturan/periode");
  revalidatePath("/app/pengaturan");
  revalidatePath("/app");
  return { success: `Status periode berubah menjadi ${parsed.data.next}.` };
}
