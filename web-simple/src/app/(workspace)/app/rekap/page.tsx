import { ChartBarIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { EmptyState, PageHeader } from "@/components/page-elements";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { SelectField } from "@/components/ui/form-controls";
import { prisma } from "@/lib/prisma";
import { formatNumber } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";
import { monthlyKpiScope } from "@/modules/access/query-scope";

export default async function RecapPage({ searchParams }: { searchParams: Promise<{ periodId?: string; status?: string }> }) {
  const user = await requireRole("ADMIN", "MANAGER", "SUPERVISOR");
  const query = await searchParams;
  const periods = await prisma.kpiPeriod.findMany({ orderBy: [{ year: "desc" }, { month: "desc" }] });
  const period = periods.find((item) => item.id === query.periodId) ?? periods[0];
  const statuses = ["IN_PROGRESS", "READY", "FINALIZED", "REOPENED"] as const;
  const status = statuses.find((item) => item === query.status);
  const results = period ? await prisma.monthlyKpi.findMany({
    where: { periodId: period.id, ...monthlyKpiScope(user), ...(status ? { status } : {}) },
    orderBy: [{ branchNameSnapshot: "asc" }, { employeeNameSnapshot: "asc" }],
    include: { dailySheets: { select: { status: true } } },
  }) : [];

  return <><PageHeader eyebrow="Rekap bulanan" title="Hasil KPI per pegawai" description="Nilai aktual diakumulasikan dari lembar Bekerja yang sudah disetujui Manager." actions={period ? <Button asChild variant="secondary"><a href={`/api/export/monthly?periodId=${period.id}`}>Unduh CSV</a></Button> : null} />
    <form method="get" className="toolbar"><SelectField label="Periode" name="periodId" defaultValue={period?.id} options={periods.map((item) => ({ value: item.id, label: item.name }))} required /><SelectField label="Status" name="status" defaultValue={status ?? ""} placeholder="Semua status" options={statuses.map((item) => ({ value: item, label: label(item) }))} /><Button type="submit" variant="secondary">Tampilkan</Button></form>
    {!period ? <EmptyState icon={ChartBarIcon} title="Belum ada periode" description="Super Admin perlu membuat dan membuka periode sebelum rekap tersedia." /> : !results.length ? <EmptyState icon={ChartBarIcon} title="Rekap tidak ditemukan" description="Tidak ada hasil yang sesuai dengan periode, status, dan cakupan akun Anda." /> : <section className="panel"><div className="panel-header"><div><h2>{period.name}</h2><p>{results.length} pegawai dalam cakupan Anda</p></div><StatusBadge status={period.status} /></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Pegawai</th><th>Cabang / jabatan</th><th>Kelengkapan hari</th><th>Nilai</th><th>Status</th><th></th></tr></thead><tbody>{results.map((result) => { const approved = result.dailySheets.filter((sheet) => sheet.status === "APPROVED").length; const percent = result.dailySheets.length ? Math.round(approved / result.dailySheets.length * 100) : 0; return <tr key={result.id}><td><span className="cell-title">{result.employeeNameSnapshot}</span><span className="cell-subtitle">{result.employeeNumberSnapshot}</span></td><td>{result.branchNameSnapshot}<span className="cell-subtitle">{result.positionNameSnapshot}</span></td><td><span>{approved}/{result.dailySheets.length} hari</span><div className="progress-line table-progress"><span style={{width:`${percent}%`}} /></div></td><td><strong>{formatNumber(result.finalScore)}</strong><span className="cell-subtitle">{result.ratingLabel ?? result.noScoreReason ?? "Belum tersedia"}</span></td><td><StatusBadge status={result.status} /></td><td><Button asChild variant="secondary" size="small"><Link href={`/app/rekap/${result.id}`}>Rincian</Link></Button></td></tr>; })}</tbody></table></div></section>}
  </>;
}

function label(status: string) { return ({ IN_PROGRESS: "Berjalan", READY: "Siap difinalkan", FINALIZED: "Final", REOPENED: "Dibuka kembali" } as Record<string, string>)[status] ?? status; }
