import { ChartBarIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { Pagination } from "@/components/pagination";
import { EmptyState, PageHeader } from "@/components/page-elements";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { SelectField } from "@/components/ui/form-controls";
import { prisma } from "@/lib/prisma";
import { getPageCount, PAGINATION_PAGE_SIZE, parsePage } from "@/lib/pagination";
import { formatNumber } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";
import { monthlyKpiScope } from "@/modules/access/query-scope";

export default async function RecapPage({ searchParams }: { searchParams: Promise<{ periodId?: string; status?: string; page?: string }> }) {
  const user = await requireRole("ADMIN", "MANAGER", "SUPERVISOR");
  const query = await searchParams;
  const periods = await prisma.kpiPeriod.findMany({ orderBy: [{ year: "desc" }, { month: "desc" }] });
  const period = periods.find((item) => item.id === query.periodId) ?? periods[0];
  const statuses = ["IN_PROGRESS", "READY", "FINALIZED", "REOPENED"] as const;
  const status = statuses.find((item) => item === query.status);
  const resultWhere = period ? { periodId: period.id, ...monthlyKpiScope(user), ...(status ? { status } : {}) } : null;
  const resultCount = resultWhere ? await prisma.monthlyKpi.count({ where: resultWhere }) : 0;
  const totalPages = getPageCount(resultCount);
  const page = Math.min(parsePage(query.page), totalPages);
  const results = resultWhere ? await prisma.monthlyKpi.findMany({
    where: resultWhere,
    orderBy: [{ branchNameSnapshot: "asc" }, { employeeNameSnapshot: "asc" }],
    skip: (page - 1) * PAGINATION_PAGE_SIZE,
    take: PAGINATION_PAGE_SIZE,
    include: { dailySheets: { select: { status: true, effectiveWorkStatus: true } } },
  }) : [];
  const firstResult = resultCount ? (page - 1) * PAGINATION_PAGE_SIZE + 1 : 0;
  const lastResult = Math.min(page * PAGINATION_PAGE_SIZE, resultCount);

  return <><PageHeader eyebrow="Rekap bulanan" title="Hasil KPI per pegawai" description="Nilai aktual diakumulasikan dari lembar Bekerja yang sudah disetujui Manager." actions={period ? <Button asChild variant="secondary"><a href={`/api/export/monthly?periodId=${period.id}`}>Unduh CSV</a></Button> : null} />
    <form method="get" className="toolbar"><SelectField label="Periode" name="periodId" defaultValue={period?.id} options={periods.map((item) => ({ value: item.id, label: item.name }))} required /><SelectField label="Status" name="status" defaultValue={status ?? ""} placeholder="Semua status" options={statuses.map((item) => ({ value: item, label: label(item) }))} /><Button type="submit" variant="secondary">Tampilkan</Button></form>
    {!period ? <EmptyState icon={ChartBarIcon} title="Belum ada periode" description="Super Admin perlu membuat dan membuka periode sebelum rekap tersedia." /> : !results.length ? <EmptyState icon={ChartBarIcon} title="Rekap tidak ditemukan" description="Tidak ada hasil yang sesuai dengan periode, status, dan cakupan akun Anda." /> : <section className="panel"><div className="panel-header"><div><h2>{period.name}</h2><p>Menampilkan {firstResult}–{lastResult} dari {resultCount} pegawai dalam cakupan Anda</p></div><StatusBadge status={period.status} /></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Pegawai</th><th>Cabang / jabatan</th><th>Kelengkapan hari</th><th>Nilai</th><th>Status</th><th></th></tr></thead><tbody>{results.map((result) => { const approved = result.dailySheets.filter((sheet) => sheet.status === "APPROVED").length; const percent = result.dailySheets.length ? Math.round(approved / result.dailySheets.length * 100) : 0; const hasScore = result.status === "FINALIZED" || result.dailySheets.some((sheet) => sheet.status === "APPROVED" && sheet.effectiveWorkStatus === "WORKED"); return <tr key={result.id}><td data-label="Pegawai"><span className="cell-title">{result.employeeNameSnapshot}</span><span className="cell-subtitle">{result.employeeNumberSnapshot}</span></td><td data-label="Cabang / jabatan">{result.branchNameSnapshot}<span className="cell-subtitle">{result.positionNameSnapshot}</span></td><td data-label="Kelengkapan hari"><span>{approved}/{result.dailySheets.length} hari</span><div className="progress-line table-progress"><span style={{width:`${percent}%`}} /></div></td><td data-label="Nilai"><strong>{hasScore ? formatNumber(result.finalScore) : "-"}</strong><span className="cell-subtitle">{hasScore ? result.ratingLabel ?? result.noScoreReason ?? "Belum tersedia" : "Belum dapat dihitung"}</span></td><td data-label="Status"><StatusBadge status={result.status} /></td><td data-label="Aksi"><Button asChild variant="secondary" size="small"><Link href={`/app/rekap/${result.id}`}>Rincian</Link></Button></td></tr>; })}</tbody></table></div><Pagination page={page} totalPages={totalPages} hrefForPage={(nextPage) => { const params = new URLSearchParams({ periodId: period.id, page: String(nextPage) }); if (status) params.set("status", status); return `/app/rekap?${params.toString()}`; }} /></section>}
  </>;
}

function label(status: string) { return ({ IN_PROGRESS: "Berjalan", READY: "Siap difinalkan", FINALIZED: "Final", REOPENED: "Dibuka kembali" } as Record<string, string>)[status] ?? status; }
