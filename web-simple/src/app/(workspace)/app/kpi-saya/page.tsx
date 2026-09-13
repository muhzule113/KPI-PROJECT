import { ChartBarIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { EmptyState, PageHeader } from "@/components/page-elements";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { todayInMakassar } from "@/lib/date";
import { prisma } from "@/lib/prisma";
import { formatNumber } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";

export default async function MyKpiPage() {
  const user = await requireRole("EMPLOYEE");
  const today = new Date(`${todayInMakassar()}T00:00:00.000Z`);
  const results = await prisma.monthlyKpi.findMany({
    where: { employeeId: user.employee!.id },
    orderBy: [{ period: { year: "desc" } }, { period: { month: "desc" } }],
    include: { period: true, dailySheets: { where: { entryDate: { lte: today } }, select: { status: true, effectiveWorkStatus: true } } },
  });
  return <><PageHeader eyebrow="Pegawai" title="KPI saya" description="Pantau nilai sementara, status review harian, dan hasil resmi setiap periode." />{!results.length ? <EmptyState icon={ChartBarIcon} title="Belum ada KPI" description="KPI akan tersedia setelah Super Admin membuka periode penilaian." /> : <section className="panel"><div className="table-wrap"><table className="data-table"><thead><tr><th>Periode</th><th>Progres hari</th><th>Nilai</th><th>Predikat</th><th>Status</th><th></th></tr></thead><tbody>{results.map((result) => {
    const approved = result.dailySheets.filter((sheet) => sheet.status === "APPROVED").length;
    const percent = result.dailySheets.length ? Math.round(approved / result.dailySheets.length * 100) : 0;
    const finalized = result.status === "FINALIZED";
    const hasScore = finalized || result.dailySheets.some((sheet) => sheet.status === "APPROVED" && sheet.effectiveWorkStatus === "WORKED");
    return <tr key={result.id}>
      <td data-label="Periode"><strong>{result.period.name}</strong></td>
      <td data-label="Progres hari"><span>{approved}/{result.dailySheets.length} hari</span><div className="progress-line table-progress"><span style={{ width: `${percent}%` }} /></div></td>
      <td data-label="Nilai"><strong>{hasScore ? formatNumber(result.finalScore) : "-"}</strong><span className="cell-subtitle">{finalized ? "Nilai final" : "Nilai sementara"}</span></td>
      <td data-label="Predikat">{hasScore ? result.ratingLabel ?? result.noScoreReason ?? "Belum dapat dihitung" : "Belum dapat dihitung"}</td>
      <td data-label="Status"><StatusBadge status={result.status} /></td>
      <td data-label="Aksi"><Button asChild variant="secondary" size="small"><Link href={`/app/kpi-saya/${result.id}`}>Rincian</Link></Button></td>
    </tr>;
  })}</tbody></table></div></section>}</>;
}
