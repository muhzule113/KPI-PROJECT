import { notFound } from "next/navigation";
import Link from "next/link";
import { PageHeader } from "@/components/page-elements";
import { Button } from "@/components/ui/button";
import { prisma } from "@/lib/prisma";
import { formatNumber } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";

export default async function MyKpiDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const user = await requireRole("EMPLOYEE");
  const kpi = await prisma.monthlyKpi.findFirst({ where: { id: (await params).id, employeeId: user.employee!.id, status: "FINALIZED" }, include: { period: true, items: { orderBy: { sortOrderSnapshot: "asc" } } } });
  if (!kpi) notFound();
  return <><PageHeader eyebrow={kpi.period.name} title="Rincian KPI saya" description={`${kpi.positionNameSnapshot} · ${kpi.branchNameSnapshot}`} actions={<Button asChild variant="secondary"><Link href="/app/kpi-saya">Kembali</Link></Button>} /><section className="panel"><div className="panel-body"><p className="eyebrow">Nilai akhir</p><div className="score">{formatNumber(kpi.finalScore)}</div><p className="page-description">{kpi.ratingLabel ?? kpi.noScoreReason ?? "Tanpa skor"}</p></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Indikator</th><th>Aktual</th><th>Pencapaian</th><th>Bobot</th><th>Skor</th></tr></thead><tbody>{kpi.items.map((item) => <tr key={item.id}><td><span className="cell-title">{item.nameSnapshot}</span><span className="cell-subtitle">Target {formatNumber(item.targetSnapshot)} {item.unitSnapshot}</span></td><td>{formatNumber(item.actual)} {item.unitSnapshot}</td><td>{formatNumber(item.achievementPercentage)}%</td><td>{formatNumber(item.weightSnapshot)}%</td><td><strong>{formatNumber(item.weightedScore)}</strong></td></tr>)}</tbody></table></div></section></>;
}
