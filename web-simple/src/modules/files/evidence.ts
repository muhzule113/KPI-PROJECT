import { execFile } from "node:child_process";
import { createHash } from "node:crypto";
import { extname, resolve, sep } from "node:path";
import { promisify } from "node:util";

export const MAX_EVIDENCE_BYTES = 10 * 1024 * 1024;
export const MAX_EVIDENCE_PER_SHEET = 3;
const mimeByExtension: Record<string, string> = {
  ".jpg": "image/jpeg",
  ".jpeg": "image/jpeg",
  ".png": "image/png",
  ".webp": "image/webp",
  ".pdf": "application/pdf",
};

export function canChangeEvidence(sheetStatus: string, monthlyStatus: string) {
  return monthlyStatus !== "FINALIZED" && ["PENDING", "DRAFT", "REVISION_REQUIRED"].includes(sheetStatus);
}

export function validateEvidenceFile(buffer: Buffer, fileName: string, clientMime: string) {
  const extension = extname(fileName).toLowerCase();
  const mimeType = mimeByExtension[extension];
  if (!mimeType) throw new Error("Evidence harus berupa JPG, PNG, WEBP, atau PDF.");
  if (!buffer.length || buffer.length > MAX_EVIDENCE_BYTES) throw new Error("Ukuran evidence harus lebih dari 0 dan maksimal 10 MB.");
  if (clientMime !== mimeType) throw new Error("MIME evidence tidak sesuai dengan ekstensi.");
  const valid = extension === ".pdf"
    ? buffer.subarray(0, 5).toString("ascii") === "%PDF-"
    : extension === ".jpg" || extension === ".jpeg"
      ? buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff
      : extension === ".png"
        ? buffer.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))
        : buffer.subarray(0, 4).toString("ascii") === "RIFF" && buffer.subarray(8, 12).toString("ascii") === "WEBP";
  if (!valid) throw new Error("Isi file evidence tidak sesuai dengan formatnya.");
  return { extension, mimeType };
}

export function evidenceHash(buffer: Buffer) {
  return createHash("sha256").update(buffer).digest("hex");
}

export function resolveEvidencePath(relativePath: string, root = resolve(process.cwd(), "storage", "evidence")) {
  const filePath = resolve(root, relativePath);
  if (!filePath.startsWith(`${resolve(root)}${sep}`)) throw new Error("Lokasi evidence tidak valid.");
  return filePath;
}

export async function scanEvidenceFile(filePath: string) {
  const windows = process.platform === "win32";
  const scanner = process.env.MALWARE_SCANNER_PATH?.trim() || (windows ? "C:\\Program Files\\Windows Defender\\MpCmdRun.exe" : "clamdscan");
  const args = windows ? ["-Scan", "-ScanType", "3", "-File", filePath, "-DisableRemediation"] : ["--no-summary", filePath];
  try {
    await promisify(execFile)(scanner, args, { timeout: 120_000, windowsHide: true, maxBuffer: 1024 * 1024 });
  } catch {
    throw new Error("File ditolak karena pemindaian malware gagal atau menemukan ancaman.");
  }
}
