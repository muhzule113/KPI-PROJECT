import { createHash, timingSafeEqual } from "node:crypto";
import { readFile } from "node:fs/promises";
import { NextResponse } from "next/server";
import { currentUser } from "@/modules/access/current-user";
import { canEnterDailySheet, canReviewDailySheet, canViewMonthly } from "@/modules/access/policy";
import { prisma } from "@/lib/prisma";
import { resolveEvidencePath } from "@/modules/files/evidence";
import { kpiSubjectFromSnapshot } from "@/modules/kpi/monthly-operations";

export async function GET(_: Request, context: { params: Promise<{ id: string }> }) {
  const user = await currentUser();
  if (!user?.active) return NextResponse.json({ error: "Autentikasi diperlukan." }, { status: 401 });
  const { id } = await context.params;
  const evidence = await prisma.evidence.findUnique({
    where: { id },
    include: { dailySheet: { include: { monthlyKpi: true } } },
  });
  if (!evidence) return NextResponse.json({ error: "Evidence tidak ditemukan." }, { status: 404 });
  const subject = kpiSubjectFromSnapshot(evidence.dailySheet.monthlyKpi);
  if (!canViewMonthly(user, subject) && !canEnterDailySheet(user, subject) && !canReviewDailySheet(user, subject)) {
    return NextResponse.json({ error: "Akses ditolak." }, { status: 403 });
  }
  try {
    const buffer = await readFile(resolveEvidencePath(evidence.storagePath));
    const actual = Buffer.from(createHash("sha256").update(buffer).digest("hex"));
    const expected = Buffer.from(evidence.sha256Hash);
    if (actual.length !== expected.length || !timingSafeEqual(actual, expected)) throw new Error("Hash tidak sesuai.");
    const safeName = evidence.fileName.replace(/[\r\n"]/g, "_");
    return new NextResponse(buffer, {
      headers: {
        "Content-Type": evidence.mimeType,
        "Content-Length": String(buffer.length),
        "Content-Disposition": `attachment; filename="${safeName}"; filename*=UTF-8''${encodeURIComponent(evidence.fileName)}`,
        "Cache-Control": "private, no-store",
        "X-Content-Type-Options": "nosniff",
      },
    });
  } catch {
    return NextResponse.json({ error: "Evidence tidak dapat dibaca atau gagal diverifikasi." }, { status: 410 });
  }
}
