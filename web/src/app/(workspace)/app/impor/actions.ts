"use server";

import { createHash, randomUUID } from "node:crypto";
import { mkdir, unlink, writeFile } from "node:fs/promises";
import { dirname, extname, join } from "node:path";
import { revalidatePath } from "next/cache";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { ALLOWED_IMPORT_EXTENSIONS, assertCashierImportFileSignature, MAX_IMPORT_FILE_BYTES } from "@/modules/imports/cashier-import";
import { syncEmployeeOperationalKpis } from "@/modules/kpi/operational-sync";

export type ImportActionState = { error?: string; success?: string; batchId?: string };

const uploadSchema = z.object({ periodId: z.string().min(1), branchId: z.string().min(1), mappingVersionId: z.string().min(1) });
const confirmSchema = z.object({ batchId: z.string().min(1), acknowledgeWarnings: z.string().optional() });
const object = (value: unknown) => value !== null && !Array.isArray(value) && typeof value === "object" ? value as Record<string, unknown> : {};
const safeMessage = (error: unknown) => error instanceof Error && !error.message.toLowerCase().includes("prisma") ? error.message : "Impor tidak dapat diproses. Periksa file lalu coba lagi.";

export async function stageCashierImport(_: ImportActionState, formData: FormData): Promise<ImportActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "imports.configure") && !hasCapability(user, "cashier.import")) return { error: "Anda tidak berwenang mengimpor laporan kasir." };
  const parsed = uploadSchema.safeParse(Object.fromEntries(formData));
  const file = formData.get("file");
  if (!parsed.success || !(file instanceof File)) return { error: "Periode, cabang, mapping, dan file wajib dipilih." };
  const extension = extname(file.name).toLowerCase();
  if (!ALLOWED_IMPORT_EXTENSIONS.has(extension)) return { error: "Format yang didukung hanya XLSX, CSV, dan PDF." };
  if (file.size <= 0 || file.size > MAX_IMPORT_FILE_BYTES) return { error: "Ukuran file harus lebih dari 0 dan maksimal 10 MB." };
  if (!hasCapability(user, "imports.configure") && parsed.data.branchId !== user.employee?.branchId) return { error: "Cabang impor harus sesuai dengan akun Kasir." };

  try {
    const [period, branch, mapping] = await Promise.all([
      prisma.kpiPeriod.findFirst({ where: { id: parsed.data.periodId, status: "OPEN", branches: { some: { branchId: parsed.data.branchId } } } }),
      prisma.branch.findFirst({ where: { id: parsed.data.branchId, isActive: true } }),
      prisma.importMappingVersion.findFirst({ where: { id: parsed.data.mappingVersionId, isActive: true, template: { isActive: true } }, include: { template: true } }),
    ]);
    if (!period || !branch) throw new Error("Periode harus OPEN dan mencakup cabang yang dipilih.");
    if (!mapping) throw new Error("Versi mapping impor aktif belum dikonfigurasi. Jalankan seed atau aktifkan mapping terlebih dahulu.");
    const buffer = Buffer.from(await file.arrayBuffer());
    assertCashierImportFileSignature(buffer, extension);
    const sourceApplication = mapping.template.sourceApplication;
    const fileHashSha256 = createHash("sha256").update(buffer).digest("hex");
    const duplicate = await prisma.importBatch.findFirst({ where: { sourceApplication, fileHashSha256, status: { not: "SUPERSEDED" } }, select: { id: true } });
    if (duplicate) throw new Error(`File identik sudah pernah diunggah pada batch ${duplicate.id}.`);

    const relativePath = `storage/imports/${new Date().toISOString().slice(0, 7).replace("-", "/")}/${randomUUID()}${extension}`;
    const absolutePath = join(process.cwd(), relativePath);
    await mkdir(dirname(absolutePath), { recursive: true });
    await writeFile(absolutePath, buffer, { flag: "wx" });
    try {
      const batch = await prisma.$transaction(async (tx) => {
        const created = await tx.importBatch.create({ data: { fileName: file.name.slice(0, 255), filePath: relativePath, fileHashSha256, sourceApplication, mappingVersionId: mapping.id, periodId: period.id, branchId: branch.id, uploaderId: user.id, status: "QUEUED", scanStatus: "quarantine", scanNote: "Menunggu worker pemrosesan." } });
        await tx.auditEvent.create({ data: { actorId: user.id, action: "stage_cashier_import", subjectType: "ImportBatch", subjectId: created.id, afterJson: { fileName: created.fileName, fileHashSha256, periodId: period.id, branchId: branch.id, mappingVersionId: mapping.id, status: "QUEUED" } } });
        return created;
      });
      revalidatePath("/app/impor");
      return { success: "File tersimpan aman dan masuk antrean pemrosesan. Halaman akan memperbarui status otomatis.", batchId: batch.id };
    } catch (error) {
      await unlink(absolutePath).catch(() => undefined);
      throw error;
    }
  } catch (error) {
    return { error: safeMessage(error) };
  }
}

const normalizedRowSchema = z.object({ row_status: z.enum(["valid", "warning"]), transaction_number: z.string().min(1), transaction_date: z.string().datetime(), cashier_employee_id: z.string().min(1), cashier_name_raw: z.string(), transaction_amount: z.number().nonnegative(), system_cash_amount: z.number().nonnegative(), actual_cash_amount: z.number().nonnegative(), cash_difference: z.number().nonnegative(), duration_seconds: z.number().int().nonnegative(), status: z.enum(["SUCCESS", "REFUND", "VOID", "CANCELLED"]), business_key: z.string().min(1) });

export async function confirmCashierImport(_: ImportActionState, formData: FormData): Promise<ImportActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "imports.configure") && !hasCapability(user, "cashier.import")) return { error: "Anda tidak berwenang mengonfirmasi impor." };
  const parsed = confirmSchema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Batch impor tidak valid." };
  try {
    const committed = await prisma.$transaction(async (tx) => {
      await tx.$queryRaw`SELECT id FROM import_batches WHERE id = ${parsed.data.batchId} FOR UPDATE`;
      const batch = await tx.importBatch.findUnique({ where: { id: parsed.data.batchId }, include: { period: true } });
      if (!batch) throw new Error("Batch impor tidak ditemukan.");
      if (!hasCapability(user, "imports.configure") && batch.branchId !== user.employee?.branchId) throw new Error("Batch berada di luar cabang Anda.");
      if (batch.status !== "READY_FOR_PREVIEW") throw new Error("Batch belum siap dikonfirmasi.");
      if (batch.period.status !== "OPEN") throw new Error("Periode impor sudah tidak OPEN.");
      if (batch.errorRows > 0) throw new Error("Perbaiki seluruh baris error sebelum konfirmasi.");
      if (batch.warningRows > 0 && parsed.data.acknowledgeWarnings !== "on") throw new Error("Centang pengakuan peringatan sebelum melanjutkan.");
      const rows = z.array(normalizedRowSchema).safeParse(object(batch.summaryJson).normalized_rows);
      if (!rows.success || !rows.data.length) throw new Error("Data preview tidak valid. Unggah ulang file.");
      const result = await tx.cashierTransaction.createMany({ data: rows.data.map((row) => ({ importBatchId: batch.id, periodId: batch.periodId, sourceApplication: batch.sourceApplication, cashierEmployeeId: row.cashier_employee_id, cashierNameRaw: row.cashier_name_raw, transactionNumber: row.transaction_number, transactionDate: new Date(row.transaction_date), transactionAmount: row.transaction_amount, systemCashAmount: row.system_cash_amount, actualCashAmount: row.actual_cash_amount, cashDifference: row.cash_difference, durationSeconds: row.duration_seconds, status: row.status, businessKey: row.business_key, isDuplicate: false })), skipDuplicates: true });
      const lateDuplicates = rows.data.length - result.count;
      const now = new Date();
      const finalStatus = batch.warningRows > 0 || lateDuplicates > 0 ? "COMPLETED_WITH_WARNINGS" : "COMPLETED";
      await tx.importBatch.update({ where: { id: batch.id }, data: { status: finalStatus, confirmedAt: now, confirmedById: user.id, warningsAcknowledgedAt: batch.warningRows > 0 ? now : null, duplicateRows: { increment: lateDuplicates } } });
      for (const cashierId of new Set(rows.data.map((row) => row.cashier_employee_id))) await syncEmployeeOperationalKpis(tx, cashierId, batch.period.startDate, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "confirm_cashier_import", subjectType: "ImportBatch", subjectId: batch.id, afterJson: { committedRows: result.count, lateDuplicates, warningsAcknowledged: batch.warningRows > 0 } } });
      return result.count;
    });
    revalidatePath("/app/impor");
    revalidatePath("/app");
    return { success: `${committed} transaksi berhasil dicatat dan KPI Kasir dihitung ulang.` };
  } catch (error) {
    return { error: safeMessage(error) };
  }
}
