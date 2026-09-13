import { notFound } from "next/navigation";
import Link from "next/link";
import { DailyHistory } from "@/components/daily-history";
import { PageHeader } from "@/components/page-elements";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { todayInMakassar } from "@/lib/date";
import { prisma } from "@/lib/prisma";
import { formatNumber } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";
import { canViewDailyDetail } from "@/modules/access/policy";
import { kpiSubjectFromSnapshot } from "@/modules/kpi/monthly-operations";
import { aggregationLabel, kindLabel } from "@/modules/kpi/target-label";

export default async function MyKpiDetailPage({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<{ sheet?: string }> }) {
  const user = await requireRole("EMPLOYEE");
  const [{ id }, query] = await Promise.all([params, searchParams]);
  const today = new Date(`${todayInMakassar()}T00:00:00.000Z`);
  const kpi = await prisma.monthlyKpi.findFirst({
    where: { id, employeeId: user.employee!.id },
    include: {
      period: true,
      items: { orderBy: { sortOrderSnapshot: "asc" } },
      dailySheets: {
        where: { entryDate: { lte: today } },
        orderBy: { entryDate: "asc" },
        include: { values: true, evidence: { orderBy: { createdAt: "asc" } } },
      },
    },
  });
  if (!kpi) notFound();
  const subject = kpiSubjectFromSnapshot(kpi);
  const selectedSheet = kpi.dailySheets.find((sheet) => sheet.id === query.sheet);
  const finalized = kpi.status === "FINALIZED";
  const approved = kpi.dailySheets.filter((sheet) => sheet.status === "APPROVED").length;
  const worked = kpi.dailySheets.filter((sheet) => sheet.status === "APPROVED" && sheet.effectiveWorkStatus === "WORKED").length;
  const hasScore = finalized || worked > 0;
  const scoreDescription = hasScore ? kpi.ratingLabel ?? kpi.noScoreReason ?? "Belum dapat dihitung" : "Belum dapat dihitung";

  return <><PageHeader eyebrow={kpi.period.name} title="Rincian KPI saya" description={`${kpi.positionNameSnapshot} · ${kpi.branchNameSnapshot}`} actions={<Button asChild variant="secondary"><Link href="/app/kpi-saya">Kembali</Link></Button>} />
    <section className="panel"><div className="panel-header"><div><h2>Akumulasi indikator</h2><p>{finalized ? "Hasil resmi periode ini" : "Sementara, hanya dari hari Bekerja yang sudah disetujui Manager"}</p></div><StatusBadge status={kpi.status} /></div><div className="panel-body"><p className="eyebrow">{finalized ? "Nilai akhir" : "Nilai sementara"}</p><div className="score">{hasScore ? formatNumber(kpi.finalScore) : "-"}</div><p className="page-description">{scoreDescription}</p>{!finalized ? <div className="notice stack-top">Nilai dan predikat ini dapat berubah sampai Manager memfinalkan KPI bulanan.</div> : null}<dl className="definition-list sheet-definition"><div><dt>Hari disetujui</dt><dd>{approved}/{kpi.dailySheets.length}</dd></div><div><dt>Hari bekerja</dt><dd>{worked}</dd></div><div><dt>Finalisasi</dt><dd>{kpi.finalizedAt ? "Sudah final" : "Belum final"}</dd></div></dl></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Indikator</th><th>Aktual</th><th>Pencapaian</th><th>Bobot</th><th>Skor</th></tr></thead><tbody>{kpi.items.map((item) => <tr key={item.id}><td data-label="Indikator"><span className="cell-title">{item.nameSnapshot}</span><span className="cell-subtitle">{kindLabel(item.kindSnapshot)} · {aggregationLabel(item.aggregationSnapshot)} · Target {formatNumber(item.targetSnapshot)} {item.unitSnapshot}</span></td><td data-label="Aktual">{hasScore ? `${formatNumber(item.actual)} ${item.unitSnapshot}` : "-"}</td><td data-label="Pencapaian">{hasScore ? `${formatNumber(item.achievementPercentage)}%` : "-"}</td><td data-label="Bobot">{formatNumber(item.weightSnapshot)}%</td><td data-label="Skor"><strong>{hasScore ? formatNumber(item.weightedScore) : "-"}</strong></td></tr>)}</tbody></table></div></section>
    <DailyHistory sheets={kpi.dailySheets} items={kpi.items} selectedSheetId={query.sheet} baseHref={`/app/kpi-saya/${kpi.id}`} canViewSelectedDetails={selectedSheet ? canViewDailyDetail(user, subject, selectedSheet.status) : false} />
  </>;
}
