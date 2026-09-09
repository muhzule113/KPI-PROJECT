import assert from "node:assert/strict";
import { createHash, randomUUID } from "node:crypto";
import { mkdir, unlink, writeFile } from "node:fs/promises";
import { dirname, join } from "node:path";
import { prisma } from "../src/lib/prisma";
import { REQUIRED_COLUMNS } from "../src/modules/imports/mapping";
import { processQueuedCashierImport } from "../src/modules/imports/processing";

const csv = (value: unknown) => `"${String(value).replaceAll('"', '""')}"`;
const token = randomUUID();
let batchId: string | undefined;
let absolutePath: string | undefined;

try {
  const period = await prisma.kpiPeriod.findFirst({ where: { status: "OPEN" }, include: { branches: true } });
  assert(period, "Tidak ada periode OPEN untuk smoke test worker impor.");
  const mapping = await prisma.importMappingVersion.findFirst({ where: { isActive: true, template: { isActive: true } }, include: { template: true } });
  assert(mapping, "Tidak ada mapping impor aktif.");
  const placement = await prisma.employeePlacement.findFirst({ where: { branchId: { in: period.branches.map((row) => row.branchId) }, position: { code: "POS-KSR" }, effectiveFrom: { lte: period.endDate }, OR: [{ effectiveUntil: null }, { effectiveUntil: { gte: period.startDate } }], employee: { userId: { not: null } } }, include: { employee: true } });
  assert(placement?.employee.userId, "Tidak ada Kasir dengan akun dan placement aktif pada periode OPEN.");
  const configured = mapping.mappingsJson && !Array.isArray(mapping.mappingsJson) && typeof mapping.mappingsJson === "object" ? mapping.mappingsJson : {};
  const headers = REQUIRED_COLUMNS.map((field) => typeof configured[field] === "string" ? configured[field] : field);
  const date = new Date(Math.max(period.startDate.valueOf(), placement.effectiveFrom.valueOf()));
  assert(date <= period.endDate && (!placement.effectiveUntil || date <= placement.effectiveUntil), "Tanggal smoke test tidak berada dalam placement Kasir.");
  const values = [`SMOKE-${token}`, date.toISOString().slice(0, 10), placement.employee.employeeNumber, 100000, 100000, 100000, 120, "SUCCESS"];
  const buffer = Buffer.from(`${headers.map(csv).join(",")}\r\n${values.map(csv).join(",")}\r\n`, "utf8");
  const relativePath = `storage/imports/smoke/${token}.csv`;
  absolutePath = join(process.cwd(), relativePath);
  await mkdir(dirname(absolutePath), { recursive: true });
  await writeFile(absolutePath, buffer, { flag: "wx" });
  const batch = await prisma.importBatch.create({ data: { fileName: `${token}.csv`, filePath: relativePath, fileHashSha256: createHash("sha256").update(buffer).digest("hex"), sourceApplication: mapping.template.sourceApplication, mappingVersionId: mapping.id, periodId: period.id, branchId: placement.branchId, uploaderId: placement.employee.userId, status: "QUEUED", scanStatus: "quarantine" } });
  batchId = batch.id;
  assert.equal(await processQueuedCashierImport(batch.id), true);
  const processed = await prisma.importBatch.findUniqueOrThrow({ where: { id: batch.id } });
  assert.equal(processed.status, "READY_FOR_PREVIEW");
  assert.equal(processed.scanStatus, "clean");
  assert.equal(processed.totalRows, 1);
  assert.equal(processed.errorRows, 0);
  process.stdout.write("Worker impor: scan, hash, parsing, mapping, dan preview lulus.\n");
} finally {
  if (batchId) {
    await prisma.systemNotification.deleteMany({ where: { entityType: "ImportBatch", entityId: batchId } });
    await prisma.auditEvent.deleteMany({ where: { subjectType: "ImportBatch", subjectId: batchId } });
    await prisma.importBatch.deleteMany({ where: { id: batchId } });
  }
  if (absolutePath) await unlink(absolutePath).catch(() => undefined);
  await prisma.$disconnect();
}
