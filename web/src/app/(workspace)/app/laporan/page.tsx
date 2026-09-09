import { ChartLineUpIcon, DownloadSimpleIcon, FileTextIcon, MedalIcon, UsersIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { EmptyState } from "@/components/empty-state";
import { PageHeading } from "@/components/page-heading";
import { StatusBadge } from "@/components/status-badge";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatNumber } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { kpiScopeFor } from "@/modules/access/scope";
import { rankKpisByPosition } from "@/modules/reports/ranking";
import { TYPED_REPORTS } from "@/modules/reports/typed-report";

export const metadata: Metadata = { title: "Laporan" };
const selectClass = "flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring";
const queryValue = (input: string | string[] | undefined) => typeof input === "string" && input.length <= 100 ? input : "";

function TrendChart({ points }: { points: Array<{ label: string; score: number | null }> }) {
  const available = points.map((point, index) => ({ ...point, index })).filter((point): point is { label: string; score: number; index: number } => point.score != null);
  if (!available.length) return <EmptyState icon={ChartLineUpIcon} title="Belum ada tren" description="Tren muncul setelah nilai akhir tersedia." />;
  const x = (index: number) => points.length === 1 ? 300 : 24 + index * 552 / (points.length - 1);
  const y = (score: number) => 164 - Math.max(0, Math.min(100, score)) * 1.4;
  const path = available.map((point, index) => `${index ? "L" : "M"}${x(point.index)} ${y(point.score)}`).join(" ");
  return <div><svg viewBox="0 0 600 180" role="img" aria-label="Tren rata-rata skor KPI per periode" className="h-48 w-full"><path d="M24 24V164H576" fill="none" className="stroke-border" strokeWidth="1" /><path d={path} fill="none" className="stroke-primary" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />{available.map((point) => <circle key={`${point.label}-${point.index}`} cx={x(point.index)} cy={y(point.score)} r="5" className="fill-primary"><title>{point.label}: {formatNumber(point.score)}</title></circle>)}</svg><div className="grid gap-2 text-center text-[11px] text-muted-foreground" style={{ gridTemplateColumns: `repeat(${points.length}, minmax(0, 1fr))` }}>{points.map((point) => <span key={point.label} className="truncate" title={point.label}>{point.label}<strong className="block text-foreground">{point.score == null ? "–" : formatNumber(point.score)}</strong></span>)}</div></div>;
}

export default async function ReportsPage({ searchParams }: { searchParams: Promise<{ period?: string | string[]; branch?: string | string[]; position?: string | string[] }> }) {
  const user = await requireUser();
  if (!hasCapability(user, "reports.view")) redirect("/app");
  const query = await searchParams;
  const periods = await prisma.kpiPeriod.findMany({ where: { status: { not: "CANCELLED" } }, orderBy: [{ year: "desc" }, { month: "desc" }], take: 12 });
  const period = periods.find((item) => item.id === queryValue(query.period)) ?? periods[0];
  const requestedBranch = queryValue(query.branch);
  const requestedPosition = queryValue(query.position);
  const scope = kpiScopeFor(user);
  const baseRecords = period ? await prisma.employeeKpi.findMany({
    where: { periodId: period.id, ...scope },
    orderBy: [{ finalScore: "desc" }, { employeeNameSnapshot: "asc" }],
    include: { branch: true, position: true, items: { select: { weightSnapshot: true, achievementPercentage: true } }, corrections: { select: { status: true } } },
  }) : [];
  const branches = [...new Map(baseRecords.map((record) => [record.branch.id, record.branch])).values()].sort((left, right) => left.name.localeCompare(right.name));
  const positions = [...new Map(baseRecords.map((record) => [record.position.id, record.position])).values()].sort((left, right) => left.name.localeCompare(right.name));
  const branchId = branches.some((branch) => branch.id === requestedBranch) ? requestedBranch : "";
  const positionId = positions.some((position) => position.id === requestedPosition) ? requestedPosition : "";
  const records = baseRecords.filter((record) => (!branchId || record.branchIdSnapshot === branchId) && (!positionId || record.positionIdSnapshot === positionId));
  const trendRows = periods.length ? await prisma.employeeKpi.findMany({ where: { periodId: { in: periods.map((item) => item.id) }, ...scope, branchIdSnapshot: branchId || undefined, positionIdSnapshot: positionId || undefined, finalScore: { not: null } }, select: { periodId: true, finalScore: true } }) : [];
  const trend = [...periods].reverse().map((item) => {
    const scores = trendRows.filter((row) => row.periodId === item.id).map((row) => Number(row.finalScore));
    return { label: item.name, score: scores.length ? scores.reduce((sum, score) => sum + score, 0) / scores.length : null };
  });
  const scored = records.filter((record) => record.finalScore != null);
  const average = scored.length ? scored.reduce((sum, record) => sum + Number(record.finalScore), 0) / scored.length : null;
  const approved = records.filter((record) => ["APPROVED", "LOCKED"].includes(record.status)).length;
  const ranking = rankKpisByPosition(records.map((record) => ({ id: record.id, employeeNumber: record.employeeNumberSnapshot, employeeName: record.employeeNameSnapshot, positionId: record.position.id, positionCode: record.positionCodeSnapshot, positionName: record.position.name, branchName: record.branch.name, status: record.status, eligibility: record.eligibility, finalScore: record.finalScore == null ? null : Number(record.finalScore), items: record.items.map((item) => ({ weight: Number(item.weightSnapshot), achievement: item.achievementPercentage == null ? null : Number(item.achievementPercentage) })), corrections: record.corrections })));
  const labels = [...new Set(scored.map((record) => record.ratingLabel ?? "Belum ada predikat"))];
  const ratingDistribution = labels.map((label) => ({ label, count: scored.filter((record) => (record.ratingLabel ?? "Belum ada predikat") === label).length }));
  const positionAverages = positions.map((position) => { const rows = records.filter((record) => record.position.id === position.id && record.finalScore != null); return { name: position.name, score: rows.length ? rows.reduce((sum, row) => sum + Number(row.finalScore), 0) / rows.length : null }; }).filter((item): item is { name: string; score: number } => item.score != null);
  const exportParams = new URLSearchParams({ period: period?.id ?? "" });
  if (branchId) exportParams.set("branch", branchId);
  if (positionId) exportParams.set("position", positionId);

  return <div className="space-y-7">
    <PageHeading eyebrow="Analitik KPI" title="Laporan" description={period ? `Ringkasan ${period.name} berdasarkan cakupan akses Anda.` : "Belum ada periode KPI."} action={period && hasCapability(user, "reports.export") ? <div className="flex flex-wrap gap-2">{["csv", "xlsx", "pdf"].map((format) => <Button key={format} asChild variant="outline"><a href={`/api/reports/kpi.${format}?${exportParams}`}><DownloadSimpleIcon /> {format.toUpperCase()}</a></Button>)}</div> : undefined} />
    {period ? <form method="get" className="grid gap-4 rounded-2xl border bg-card p-5 sm:grid-cols-2 xl:grid-cols-[1fr_1fr_1fr_auto] xl:items-end"><div className="space-y-2"><label htmlFor="reportPeriod" className="text-sm font-medium">Periode</label><select id="reportPeriod" name="period" defaultValue={period.id} className={selectClass}>{periods.map((item) => <option key={item.id} value={item.id}>{item.name}</option>)}</select></div><div className="space-y-2"><label htmlFor="reportBranch" className="text-sm font-medium">Cabang</label><select id="reportBranch" name="branch" defaultValue={branchId} className={selectClass}><option value="">Semua dalam cakupan</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></div><div className="space-y-2"><label htmlFor="reportPosition" className="text-sm font-medium">Jabatan</label><select id="reportPosition" name="position" defaultValue={positionId} className={selectClass}><option value="">Semua jabatan</option>{positions.map((position) => <option key={position.id} value={position.id}>{position.name}</option>)}</select></div><Button type="submit">Terapkan filter</Button></form> : null}
    {period && hasCapability(user, "reports.export") ? <Card><CardHeader><CardTitle className="text-base">Paket laporan resmi</CardTitle><p className="text-xs text-muted-foreground">Filter periode, cabang, dan jabatan di atas juga berlaku pada hasil unduhan.</p></CardHeader><CardContent className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{TYPED_REPORTS.filter((report) => report.type !== "individual").map((report) => <div key={report.type} className="rounded-xl border p-4"><p className="text-sm font-medium">{report.label}</p><div className="mt-3 flex flex-wrap gap-2">{report.formats.map((format) => <Button key={format} asChild size="sm" variant="outline"><a href={`/api/reports/${report.type}.${format}?${exportParams}`}><DownloadSimpleIcon /> {format.toUpperCase()}</a></Button>)}</div></div>)}</CardContent></Card> : null}
    <section className="grid grid-cols-2 gap-3 lg:grid-cols-3"><Card><CardContent className="p-5"><UsersIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{records.length}</p><p className="text-xs text-muted-foreground">Karyawan dinilai</p></CardContent></Card><Card><CardContent className="p-5"><MedalIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{average == null ? "Belum ada" : formatNumber(average)}</p><p className="text-xs text-muted-foreground">Rata-rata nilai</p></CardContent></Card><Card className="col-span-2 lg:col-span-1"><CardContent className="p-5"><FileTextIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{approved}/{records.length}</p><p className="text-xs text-muted-foreground">Sudah disahkan</p></CardContent></Card></section>
    <div className="grid gap-5 xl:grid-cols-[1.2fr_0.8fr]"><Card><CardHeader><CardTitle className="text-base">Tren rata-rata skor</CardTitle></CardHeader><CardContent><TrendChart points={trend} /></CardContent></Card><Card><CardHeader><CardTitle className="text-base">Distribusi predikat</CardTitle></CardHeader><CardContent>{ratingDistribution.length ? <div className="space-y-3">{ratingDistribution.map((item) => <div key={item.label} className="flex items-center justify-between gap-3"><span className="text-sm">{item.label}</span><Badge variant="secondary">{item.count}</Badge></div>)}</div> : <p className="text-sm text-muted-foreground">Belum ada predikat.</p>}<div className="mt-5 border-t pt-4"><p className="mb-3 text-sm font-semibold">Rata-rata per jabatan</p>{positionAverages.map((item) => <div key={item.name} className="mb-3"><div className="mb-1 flex justify-between gap-3 text-xs"><span>{item.name}</span><strong>{formatNumber(item.score)}</strong></div><div className="h-2 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${Math.max(0, Math.min(100, item.score))}%` }} /></div></div>)}</div></CardContent></Card></div>
    {[...ranking.entries()].length ? <Card><CardHeader><CardTitle className="text-base">Ranking final per jabatan</CardTitle><p className="text-xs text-muted-foreground">Hanya KPI Locked dengan eligibility penuh. Skor seri dibandingkan memakai indikator berbobot tertinggi.</p></CardHeader><CardContent className="space-y-5">{[...ranking.entries()].map(([key, rows]) => <section key={key}><h3 className="mb-2 text-sm font-semibold">{rows[0].positionName}</h3><div className="overflow-x-auto rounded-xl border"><table className="w-full min-w-[650px] text-left text-sm"><thead className="bg-muted/60 text-xs"><tr><th className="px-3 py-2">Rank</th><th className="px-3 py-2">Karyawan</th><th className="px-3 py-2">Cabang</th><th className="px-3 py-2">Skor</th><th className="px-3 py-2">Tie-break</th><th className="px-3 py-2">Koreksi</th></tr></thead><tbody className="divide-y">{rows.slice(0, 10).map((row) => <tr key={row.id}><td className="px-3 py-3 font-bold">#{row.rank}</td><td className="px-3 py-3"><span className="font-medium">{row.employeeName}</span><span className="block text-xs text-muted-foreground">{row.employeeNumber}</span></td><td className="px-3 py-3">{row.branchName}</td><td className="px-3 py-3 font-semibold">{formatNumber(row.finalScore!)}</td><td className="px-3 py-3">{formatNumber(row.tieBreakAchievement)}%</td><td className="px-3 py-3">{row.correctionInProgress ? <Badge variant="warning">Pending</Badge> : "–"}</td></tr>)}</tbody></table></div></section>)}</CardContent></Card> : null}
    {!period || records.length === 0 ? <EmptyState icon={FileTextIcon} title="Belum ada hasil KPI" description="Laporan tersedia setelah penugasan KPI pada periode ini terbentuk." /> : <div className="overflow-hidden rounded-2xl border bg-card"><div className="hidden grid-cols-[1.4fr_1fr_1fr_0.7fr_auto] gap-4 border-b bg-muted/55 px-5 py-3 text-xs font-semibold text-muted-foreground md:grid"><span>Karyawan</span><span>Jabatan</span><span>Cabang</span><span>Nilai</span><span>Status / PDF</span></div><div className="divide-y">{records.map((record) => <div key={record.id} className="grid gap-3 p-4 md:grid-cols-[1.4fr_1fr_1fr_0.7fr_auto] md:items-center md:gap-4 md:px-5"><div><p className="text-sm font-semibold">{record.employeeNameSnapshot}</p><p className="text-xs text-muted-foreground">{record.employeeNumberSnapshot} · eligibility {record.eligibility}</p></div><p className="text-sm">{record.position.name}</p><p className="text-sm">{record.branch.name}</p><div><p className="text-sm font-semibold">{record.finalScore == null ? "Belum ada" : formatNumber(record.finalScore.toString())}</p><p className="text-xs text-muted-foreground">{record.ratingLabel ?? "Belum dinilai"}</p></div><div className="flex flex-wrap items-center gap-2"><StatusBadge status={record.status} />{hasCapability(user, "reports.export") ? <Button asChild size="sm" variant="ghost"><a href={`/api/reports/individual.pdf?${exportParams}&kpi=${encodeURIComponent(record.id)}`}><FileTextIcon /> PDF</a></Button> : null}</div></div>)}</div></div>}
  </div>;
}
