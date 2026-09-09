import { execFile } from "node:child_process";
import { createHmac, timingSafeEqual } from "node:crypto";
import { extname } from "node:path";
import { promisify } from "node:util";

export const MAX_EVIDENCE_BYTES = 10 * 1024 * 1024;
const mimeByExtension: Record<string, string> = { ".jpg": "image/jpeg", ".jpeg": "image/jpeg", ".png": "image/png", ".webp": "image/webp", ".pdf": "application/pdf" };
const MAX_DOWNLOAD_LIFETIME_SECONDS = 15 * 60;

function signingKey(secret = process.env.BETTER_AUTH_SECRET) {
  if (!secret || secret.length < 32) throw new Error("BETTER_AUTH_SECRET minimal 32 karakter diperlukan untuk tautan evidence.");
  return secret;
}

export function createEvidenceDownloadToken(ticketId: string, index: number, expiresAt: Date, secret?: string) {
  const expires = Math.floor(expiresAt.valueOf() / 1000);
  const signature = createHmac("sha256", signingKey(secret)).update(`${ticketId}.${index}.${expires}`).digest("base64url");
  return `${expires}.${signature}`;
}

export function verifyEvidenceDownloadToken(token: string, ticketId: string, index: number, now = new Date(), secret?: string) {
  const [rawExpires, supplied, extra] = token.split(".");
  const expires = Number(rawExpires);
  const nowSeconds = Math.floor(now.valueOf() / 1000);
  if (extra || !supplied || !Number.isSafeInteger(expires) || expires < nowSeconds || expires > nowSeconds + MAX_DOWNLOAD_LIFETIME_SECONDS) return false;
  const expected = createHmac("sha256", signingKey(secret)).update(`${ticketId}.${index}.${expires}`).digest();
  let actual: Buffer;
  try { actual = Buffer.from(supplied, "base64url"); } catch { return false; }
  return actual.length === expected.length && timingSafeEqual(actual, expected);
}

export function validateEvidenceFile(buffer: Buffer, fileName: string, clientMime: string) {
  const extension = extname(fileName).toLowerCase();
  const mimeType = mimeByExtension[extension];
  if (!mimeType) throw new Error("Evidence harus berupa JPG, PNG, WEBP, atau PDF.");
  if (!buffer.length || buffer.length > MAX_EVIDENCE_BYTES) throw new Error("Ukuran evidence harus lebih dari 0 dan maksimal 10 MB.");
  if (clientMime && clientMime !== mimeType) throw new Error("Tipe file evidence tidak sesuai dengan ekstensi.");
  const valid = extension === ".pdf" ? buffer.subarray(0, 5).toString("ascii") === "%PDF-"
    : [".jpg", ".jpeg"].includes(extension) ? buffer[0] === 0xff && buffer[1] === 0xd8 && buffer[2] === 0xff
      : extension === ".png" ? buffer.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a]))
        : buffer.subarray(0, 4).toString("ascii") === "RIFF" && buffer.subarray(8, 12).toString("ascii") === "WEBP";
  if (!valid) throw new Error("Isi file evidence tidak sesuai dengan formatnya.");
  return { extension, mimeType };
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
