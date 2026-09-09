import { prisma } from "@/lib/prisma";
import { requireUser } from "@/modules/access/current-user";
import { monthlyKpiScope } from "@/modules/access/query-scope";
import { monthlyCsv } from "@/modules/reporting/monthly-csv";

export const dynamic = "force-dynamic";

export async function GET(request: Request) {
  const user = await requireUser();
  const periodId = new URL(request.url).searchParams.get("periodId")?.trim();
  const period = periodId
    ? await prisma.kpiPeriod.findUnique({ where: { id: periodId } })
    : await prisma.kpiPeriod.findFirst({ orderBy: [{ year: "desc" }, { month: "desc" }] });
  if (!period) return new Response("Periode tidak ditemukan.", { status: 404 });

  const results = await prisma.monthlyKpi.findMany({
    where: { periodId: period.id, ...monthlyKpiScope(user) },
    orderBy: [{ branchNameSnapshot: "asc" }, { employeeNameSnapshot: "asc" }],
  });
  const body = monthlyCsv(results.map((result) => ({
    period: period.name,
    employeeNumber: result.employeeNumberSnapshot,
    employeeName: result.employeeNameSnapshot,
    branch: result.branchNameSnapshot,
    position: result.positionNameSnapshot,
    status: result.status,
    finalScore: result.finalScore?.toFixed(2) ?? "",
    rating: result.ratingLabel ?? "",
    noScoreReason: result.noScoreReason ?? "",
    finalizedAt: result.finalizedAt?.toISOString() ?? "",
  })));
  const safeName = `kpi-${period.year}-${String(period.month).padStart(2, "0")}.csv`;
  return new Response(`\uFEFF${body}`, {
    headers: {
      "Content-Type": "text/csv; charset=utf-8",
      "Content-Disposition": `attachment; filename="${safeName}"`,
      "Cache-Control": "private, no-store",
      "X-Content-Type-Options": "nosniff",
    },
  });
}
