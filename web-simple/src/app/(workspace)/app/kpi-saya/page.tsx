import { ChartBarIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { EmptyState, PageHeader } from "@/components/page-elements";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { prisma } from "@/lib/prisma";
import { formatNumber } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";

export default async function MyKpiPage() {
  const user = await requireRole("EMPLOYEE");
  const results = await prisma.monthlyKpi.findMany({ where: { employeeId: user.employee!.id, status: "FINALIZED" }, orderBy: [{ period: { year: "desc" } }, { period: { month: "desc" } }], include: { period: true } });
  return <><PageHeader eyebrow="Pegawai" title="KPI saya" description="Hanya hasil yang sudah difinalkan Manager yang ditampilkan." />{!results.length ? <EmptyState icon={ChartBarIcon} title="Belum ada hasil final" description="Anda akan menerima notifikasi setelah Manager memfinalkan KPI bulanan." /> : <section className="panel"><div className="table-wrap"><table className="data-table"><thead><tr><th>Periode</th><th>Nilai akhir</th><th>Predikat</th><th>Status</th><th></th></tr></thead><tbody>{results.map((result) => <tr key={result.id}><td><strong>{result.period.name}</strong></td><td><strong>{formatNumber(result.finalScore)}</strong></td><td>{result.ratingLabel ?? result.noScoreReason ?? "Tanpa skor"}</td><td><StatusBadge status={result.status} /></td><td><Button asChild variant="secondary" size="small"><Link href={`/app/kpi-saya/${result.id}`}>Rincian</Link></Button></td></tr>)}</tbody></table></div></section>}</>;
}
