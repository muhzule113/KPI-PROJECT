import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";
import { extname, resolve, sep } from "node:path";
import { prisma } from "@/lib/prisma";
import { scanEvidenceFile } from "@/modules/files/evidence-upload";
import { assertCashierImportFileSignature, MAX_IMPORT_ROWS, normalizeCashierRows, readTabularFile } from "@/modules/imports/cashier-import";

const safeMessage = (error: unknown) => error instanceof Error && !error.message.toLowerCase().includes("prisma") ? error.message : "File tidak dapat diproses.";

export async function processQueuedCashierImport(batchId: string) {
  const claimed = await prisma.importBatch.updateMany({ where: { id: batchId, status: "QUEUED" }, data: { status: "SCANNING" } });
  if (claimed.count !== 1) return false;
  try {
    const batch = await prisma.importBatch.findUnique({ where: { id: batchId }, include: { period: true, mappingVersion: { include: { template: true } } } });
    if (!batch || !batch.mappingVersion) throw new Error("Versi mapping batch tidak tersedia.");
    const importsRoot = resolve(process.cwd(), "storage", "imports");
    const absolutePath = resolve(process.cwd(), batch.filePath);
    if (!absolutePath.startsWith(`${importsRoot}${sep}`)) throw new Error("Lokasi file impor tidak valid.");
    const buffer = await readFile(absolutePath);
    const extension = extname(batch.fileName).toLowerCase();
    assertCashierImportFileSignature(buffer, extension);
    const actualHash = createHash("sha256").update(buffer).digest("hex");
    if (!batch.fileHashSha256 || actualHash !== batch.fileHashSha256) throw new Error("Hash file berubah setelah upload; batch ditolak.");
    await scanEvidenceFile(absolutePath);

    await prisma.importBatch.update({ where: { id: batch.id }, data: { status: "PARSING", scanStatus: "clean", scannedAt: new Date(), scanNote: "File lolos pemindaian malware dan pemeriksaan hash." } });
    const rows = await readTabularFile(buffer, extension);
    if (extension === ".pdf" && rows.length < 2) {
      await finishBatch(batch.id, "NEEDS_REVIEW", { totalRows: 0, validRows: 0, warningRows: 0, errorRows: 1, duplicateRows: 0, summaryJson: undefined, issuesJson: [{ row: 0, severity: "error", code: "ocr_required", message: "PDF tidak memiliki tabel teks yang dapat dibaca. OCR atau review manual diperlukan." }] }, "PDF aman, tetapi tidak memiliki tabel teks yang dapat diproses otomatis.");
      return true;
    }
    if (rows.length > MAX_IMPORT_ROWS + 1) throw new Error(`File melebihi batas ${MAX_IMPORT_ROWS.toLocaleString("id-ID")} baris per batch.`);
    const [placements, existing] = await Promise.all([
      prisma.employeePlacement.findMany({ where: { branchId: batch.branchId, position: { code: "POS-KSR" }, effectiveFrom: { lte: batch.period.endDate }, OR: [{ effectiveUntil: null }, { effectiveUntil: { gte: batch.period.startDate } }] }, include: { employee: { select: { id: true, name: true, employeeNumber: true } } } }),
      prisma.cashierTransaction.findMany({ where: { periodId: batch.periodId, sourceApplication: batch.sourceApplication }, select: { businessKey: true } }),
    ]);
    const analysis = normalizeCashierRows(rows, { periodStart: batch.period.startDate, periodEnd: batch.period.endDate, branchId: batch.branchId, sourceApplication: batch.sourceApplication, cashiers: placements.map((placement) => ({ id: placement.employee.id, name: placement.employee.name, employeeNumber: placement.employee.employeeNumber, effectiveFrom: placement.effectiveFrom, effectiveUntil: placement.effectiveUntil })), existingBusinessKeys: new Set(existing.map((row) => row.businessKey)), mapping: batch.mappingVersion.mappingsJson });
    const status = analysis.issues.some((issue) => ["cashier_unresolved", "cashier_ambiguous"].includes(issue.code)) ? "NEEDS_MAPPING" : "READY_FOR_PREVIEW";
    await finishBatch(batch.id, status, { totalRows: analysis.totalRows, validRows: analysis.validRows, warningRows: analysis.warningRows, errorRows: analysis.errorRows, duplicateRows: analysis.duplicateRows, summaryJson: { normalized_rows: analysis.normalizedRows, sample_rows: analysis.normalizedRows.slice(0, 10), source_application: batch.sourceApplication }, issuesJson: analysis.issues.slice(0, 200) }, status === "READY_FOR_PREVIEW" ? "File siap ditinjau dan dikonfirmasi." : "Pemetaan kasir perlu diperbaiki sebelum konfirmasi.");
    return true;
  } catch (error) {
    const message = safeMessage(error);
    await prisma.$transaction(async (tx) => {
      const batch = await tx.importBatch.findUnique({ where: { id: batchId } });
      if (!batch) return;
      await tx.importBatch.update({ where: { id: batch.id }, data: { status: "FAILED", scanStatus: "rejected", scannedAt: new Date(), scanNote: message, errorRows: Math.max(1, batch.errorRows), issuesJson: [{ row: 0, severity: "error", code: "processing_failed", message }] } });
      await tx.systemNotification.create({ data: { userId: batch.uploaderId, title: "Impor laporan gagal", body: message, type: "import_failed", entityType: "ImportBatch", entityId: batch.id, actionUrl: `/app/impor#batch-${batch.id}`, dedupeKey: `import-processed:${batch.id}:failed` } });
      await tx.auditEvent.create({ data: { actorType: "system", action: "process_cashier_import_failed", subjectType: "ImportBatch", subjectId: batch.id, beforeJson: { status: batch.status }, afterJson: { status: "FAILED", error: message } } });
    });
    return false;
  }
}

async function finishBatch(batchId: string, status: "READY_FOR_PREVIEW" | "NEEDS_MAPPING" | "NEEDS_REVIEW", values: { totalRows: number; validRows: number; warningRows: number; errorRows: number; duplicateRows: number; summaryJson: object | undefined; issuesJson: object[] }, message: string) {
  await prisma.$transaction(async (tx) => {
    const batch = await tx.importBatch.findUniqueOrThrow({ where: { id: batchId } });
    await tx.importBatch.update({ where: { id: batch.id }, data: { ...values, status, scanStatus: "clean", scannedAt: new Date(), scanNote: "File lolos pemindaian malware, verifikasi hash, dan pemeriksaan struktur." } });
    await tx.systemNotification.create({ data: { userId: batch.uploaderId, title: status === "READY_FOR_PREVIEW" ? "Impor siap ditinjau" : "Impor perlu perhatian", body: message, type: status === "READY_FOR_PREVIEW" ? "import_ready" : "import_needs_review", entityType: "ImportBatch", entityId: batch.id, actionUrl: `/app/impor#batch-${batch.id}`, dedupeKey: `import-processed:${batch.id}:${status.toLowerCase()}` } });
    await tx.auditEvent.create({ data: { actorType: "system", action: "process_cashier_import", subjectType: "ImportBatch", subjectId: batch.id, beforeJson: { status: batch.status }, afterJson: { status, totalRows: values.totalRows, errorRows: values.errorRows, duplicateRows: values.duplicateRows } } });
  });
}

export async function processQueuedCashierImports(limit = 5) {
  await prisma.importBatch.updateMany({ where: { status: { in: ["SCANNING", "PARSING", "NORMALIZING", "VALIDATING"] }, updatedAt: { lt: new Date(Date.now() - 15 * 60_000) } }, data: { status: "QUEUED", scanNote: "Worker sebelumnya terputus; batch diantrikan ulang secara otomatis." } });
  const batches = await prisma.importBatch.findMany({ where: { status: "QUEUED" }, orderBy: { createdAt: "asc" }, take: limit, select: { id: true } });
  let processed = 0;
  for (const batch of batches) if (await processQueuedCashierImport(batch.id)) processed += 1;
  return processed;
}
