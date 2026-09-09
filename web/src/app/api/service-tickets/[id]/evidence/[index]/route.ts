import { createHash } from "node:crypto";
import { readFile } from "node:fs/promises";
import { resolve, sep } from "node:path";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { currentUser } from "@/modules/access/current-user";
import { canAccessTicket } from "@/modules/access/scope";
import { verifyEvidenceDownloadToken } from "@/modules/files/evidence-upload";

const notFound = () => new Response("Evidence tidak ditemukan", { status: 404, headers: { "Cache-Control": "no-store" } });

export async function GET(request: Request, { params }: { params: Promise<{ id: string; index: string }> }) {
  const user = await currentUser();
  if (!user || !hasCapability(user, "tickets.view")) return notFound();
  const { id, index: indexValue } = await params;
  if (!/^\d+$/.test(indexValue)) return notFound();
  const index = Number(indexValue);
  const token = new URL(request.url).searchParams.get("token") ?? "";
  if (!verifyEvidenceDownloadToken(token, id, index)) return notFound();
  const ticket = await prisma.serviceTicket.findUnique({ where: { id }, include: { intakeBy: { select: { supervisorId: true } }, technician: { select: { supervisorId: true } } } });
  if (!ticket || !canAccessTicket(user, ticket)) return notFound();
  const entries = Array.isArray(ticket.technicalEvidenceJson) ? ticket.technicalEvidenceJson : [];
  const raw = entries[index];
  const evidence = raw !== null && !Array.isArray(raw) && typeof raw === "object" ? raw : {};
  const relativePath = typeof evidence.file_path === "string" ? evidence.file_path : "";
  const expectedHash = typeof evidence.sha256_hash === "string" ? evidence.sha256_hash : "";
  if (evidence.scan_status !== "clean" || !relativePath || !expectedHash) return notFound();
  const root = resolve(process.cwd(), "storage", "service-ticket-evidence");
  const absolutePath = resolve(process.cwd(), relativePath);
  if (!absolutePath.startsWith(`${root}${sep}`)) return notFound();
  let buffer: Buffer;
  try { buffer = await readFile(absolutePath); } catch { return notFound(); }
  if (createHash("sha256").update(buffer).digest("hex") !== expectedHash) {
    await prisma.auditEvent.create({ data: { actorId: user.id, action: "service_ticket_evidence_hash_mismatch", subjectType: "ServiceTicket", subjectId: ticket.id, afterJson: { index, expectedHash } } }).catch(() => undefined);
    return new Response("Integritas evidence tidak valid", { status: 409, headers: { "Cache-Control": "no-store" } });
  }
  const originalName = typeof evidence.file_name === "string" ? evidence.file_name.replace(/[\r\n"]/g, "_") : "evidence";
  const mimeType = typeof evidence.mime_type === "string" ? evidence.mime_type : "application/octet-stream";
  return new Response(Uint8Array.from(buffer).buffer, { headers: { "Content-Type": mimeType, "Content-Disposition": `attachment; filename="evidence"; filename*=UTF-8''${encodeURIComponent(originalName)}`, "Cache-Control": "no-store, private", "X-Content-Type-Options": "nosniff" } });
}
