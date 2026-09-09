import { mkdir, unlink, writeFile } from "node:fs/promises";
import { randomUUID } from "node:crypto";
import { resolve } from "node:path";
import type { AccessProfile } from "@/modules/access/policy";
import { canEnterDailySheet } from "@/modules/access/policy";
import { prisma } from "@/lib/prisma";
import { kpiSubjectFromSnapshot, lockMonthlyKpi } from "@/modules/kpi/monthly-operations";
import { canChangeEvidence, evidenceHash, MAX_EVIDENCE_PER_SHEET, resolveEvidencePath, scanEvidenceFile, validateEvidenceFile } from "@/modules/files/evidence";

function mayManageEvidence(actor: AccessProfile, sheet: { monthlyKpi: Parameters<typeof kpiSubjectFromSnapshot>[0] }) {
  const subject = kpiSubjectFromSnapshot(sheet.monthlyKpi);
  return canEnterDailySheet(actor, subject);
}

export async function uploadEvidence(actor: AccessProfile, sheetId: string, file: File) {
  if (!file.name) throw new Error("Pilih file evidence terlebih dahulu.");
  const buffer = Buffer.from(await file.arrayBuffer());
  const { extension, mimeType } = validateEvidenceFile(buffer, file.name, file.type);
  const initialSheet = await prisma.dailySheet.findUnique({ where: { id: sheetId }, include: { monthlyKpi: true } });
  if (!initialSheet || !mayManageEvidence(actor, initialSheet)) throw new Error("Anda tidak berwenang mengunggah evidence pada lembar ini.");
  if (!canChangeEvidence(initialSheet.status, initialSheet.monthlyKpi.status)) throw new Error("Evidence hanya dapat diubah sebelum lembar dikirim.");

  const relativePath = `${randomUUID()}${extension}`;
  const root = resolve(process.cwd(), "storage", "evidence");
  const filePath = resolveEvidencePath(relativePath, root);
  await mkdir(root, { recursive: true });
  await writeFile(filePath, buffer, { flag: "wx" });
  try {
    await scanEvidenceFile(filePath);
    return await prisma.$transaction(async (tx) => {
      await lockMonthlyKpi(tx, initialSheet.monthlyKpiId);
      await tx.dailySheet.updateMany({ where: { id: sheetId }, data: { rowVersion: { increment: 0 } } });
      const sheet = await tx.dailySheet.findUnique({ where: { id: sheetId }, include: { monthlyKpi: true, _count: { select: { evidence: true } } } });
      if (!sheet || !mayManageEvidence(actor, sheet)) throw new Error("Anda tidak berwenang mengunggah evidence pada lembar ini.");
      if (!canChangeEvidence(sheet.status, sheet.monthlyKpi.status)) throw new Error("Evidence hanya dapat diubah sebelum lembar dikirim.");
      if (sheet._count.evidence >= MAX_EVIDENCE_PER_SHEET) throw new Error(`Maksimal ${MAX_EVIDENCE_PER_SHEET} evidence per lembar harian.`);
      const evidence = await tx.evidence.create({
        data: {
          dailySheetId: sheet.id,
          fileName: file.name.slice(0, 255),
          storagePath: relativePath,
          mimeType,
          fileSize: buffer.length,
          sha256Hash: evidenceHash(buffer),
          scanStatus: "clean",
          uploadedById: actor.userId,
        },
      });
      await tx.auditEvent.create({ data: { actorId: actor.userId, action: "upload_evidence", subjectType: "Evidence", subjectId: evidence.id, afterJson: { dailySheetId: sheet.id, fileName: evidence.fileName, fileSize: buffer.length } } });
      return evidence;
    });
  } catch (error) {
    await unlink(filePath).catch(() => undefined);
    throw error;
  }
}

export async function removeEvidence(actor: AccessProfile, evidenceId: string) {
  const result = await prisma.$transaction(async (tx) => {
    const candidate = await tx.evidence.findUnique({ where: { id: evidenceId }, select: { dailySheet: { select: { monthlyKpiId: true } } } });
    if (!candidate) throw new Error("Evidence tidak ditemukan atau tidak dapat dihapus.");
    await lockMonthlyKpi(tx, candidate.dailySheet.monthlyKpiId);
    const evidence = await tx.evidence.findUnique({ where: { id: evidenceId }, include: { dailySheet: { include: { monthlyKpi: true } } } });
    if (!evidence || !mayManageEvidence(actor, evidence.dailySheet)) throw new Error("Evidence tidak ditemukan atau tidak dapat dihapus.");
    if (!canChangeEvidence(evidence.dailySheet.status, evidence.dailySheet.monthlyKpi.status)) throw new Error("Evidence hanya dapat diubah sebelum lembar dikirim.");
    await tx.evidence.delete({ where: { id: evidence.id } });
    await tx.auditEvent.create({ data: { actorId: actor.userId, action: "remove_evidence", subjectType: "Evidence", subjectId: evidence.id, beforeJson: { dailySheetId: evidence.dailySheetId, fileName: evidence.fileName } } });
    return evidence.storagePath;
  });
  await unlink(resolveEvidencePath(result)).catch(() => undefined);
}
