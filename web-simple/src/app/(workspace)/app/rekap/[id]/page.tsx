import { notFound, redirect } from "next/navigation";
import Link from "next/link";
import { ActionForm, SubmitButton } from "@/components/action-form";
import { DailyHistory } from "@/components/daily-history";
import { PageHeader } from "@/components/page-elements";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { DialogCancel, FormDialog } from "@/components/ui/form-dialog";
import { finalizeMonthlyAction, reopenMonthlyAction } from "@/app/(workspace)/app/rekap/actions";
import { prisma } from "@/lib/prisma";
import { todayInMakassar } from "@/lib/date";
import { formatDate, formatNumber } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";
import { canViewDailyDetail, canViewMonthly } from "@/modules/access/policy";
import { kpiSubjectFromSnapshot } from "@/modules/kpi/monthly-operations";
import { aggregationLabel, kindLabel } from "@/modules/kpi/target-label";

export default async function RecapDetailPage({ params, searchParams }: { params: Promise<{ id: string }>; searchParams: Promise<{ sheet?: string }> }) {
  const user = await requireRole("ADMIN", "MANAGER", "SUPERVISOR");
  const [{ id }, query] = await Promise.all([params, searchParams]);
  const today = todayInMakassar();
  const kpi = await prisma.monthlyKpi.findUnique({ where: { id }, include: { period: true, items: { orderBy: { sortOrderSnapshot: "asc" } }, dailySheets: { where: { entryDate: { lte: new Date(`${today}T00:00:00.000Z`) } }, orderBy: { entryDate: "asc" }, include: { values: true, evidence: { orderBy: { createdAt: "asc" } } } } } });
  if (!kpi) notFound();
  const subject = kpiSubjectFromSnapshot(kpi);
  if (!canViewMonthly(user, subject)) redirect("/akses-ditolak?reason=Hasil tidak berada dalam cakupan Anda");
  const selectedSheet = kpi.dailySheets.find((sheet) => sheet.id === query.sheet);
  const approved = kpi.dailySheets.filter((sheet) => sheet.status === "APPROVED").length;
  const worked = kpi.dailySheets.filter((sheet) => sheet.status === "APPROVED" && sheet.effectiveWorkStatus === "WORKED").length;
  const hasScore = kpi.status === "FINALIZED" || worked > 0;
  const periodEnded = today > kpi.period.endDate.toISOString().slice(0, 10);
  const canFinalize = user.role === "MANAGER" && kpi.managerIdSnapshot === user.employeeId && kpi.status !== "FINALIZED" && periodEnded && approved === kpi.dailySheets.length;

  return <><PageHeader eyebrow={kpi.period.name} title={kpi.employeeNameSnapshot} description={`${kpi.employeeNumberSnapshot} · ${kpi.positionNameSnapshot} · ${kpi.branchNameSnapshot}`} actions={<Button asChild variant="secondary"><Link href={`/app/rekap?periodId=${kpi.periodId}`}>Kembali ke rekap</Link></Button>} />
    <div className="detail-grid"><section className="panel"><div className="panel-header"><div><h2>Akumulasi indikator</h2><p>Hanya nilai efektif dari hari Bekerja yang disetujui</p></div><StatusBadge status={kpi.status} /></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Indikator</th><th>Aktual</th><th>Target</th><th>Pencapaian</th><th>Bobot</th><th>Skor</th></tr></thead><tbody>{kpi.items.map((item) => <tr key={item.id}><td data-label="Indikator"><span className="cell-title">{item.nameSnapshot}</span><span className="cell-subtitle">{kindLabel(item.kindSnapshot)} · {aggregationLabel(item.aggregationSnapshot)}</span></td><td data-label="Aktual">{hasScore ? `${formatNumber(item.actual)} ${item.unitSnapshot}` : "-"}</td><td data-label="Target">{formatNumber(item.targetSnapshot)} {item.unitSnapshot}</td><td data-label="Pencapaian">{hasScore ? `${formatNumber(item.achievementPercentage)}%` : "-"}</td><td data-label="Bobot">{formatNumber(item.weightSnapshot)}%</td><td data-label="Skor"><strong>{hasScore ? formatNumber(item.weightedScore) : "-"}</strong></td></tr>)}</tbody></table></div></section>
      <aside><section className="panel"><div className="panel-body"><p className="eyebrow">{kpi.status === "FINALIZED" ? "Nilai akhir" : "Nilai sementara"}</p><div className="score">{hasScore ? formatNumber(kpi.finalScore) : "-"}</div><p className="page-description">{hasScore ? kpi.ratingLabel ?? kpi.noScoreReason ?? "Belum dapat dihitung" : "Belum dapat dihitung"}</p><dl className="definition-list sheet-definition"><div><dt>Hari disetujui</dt><dd>{approved}/{kpi.dailySheets.length}</dd></div><div><dt>Hari bekerja</dt><dd>{worked}</dd></div><div><dt>Revisi</dt><dd>{kpi.revisionNumber}</dd></div><div><dt>Finalisasi</dt><dd>{kpi.finalizedAt ? formatDate(kpi.finalizedAt) : "Belum final"}</dd></div></dl></div></section>
        {canFinalize ? <section className="panel"><div className="panel-body"><h2>Finalisasi hasil</h2><p className="help">Tetapkan nilai sementara sebagai hasil resmi dan kunci KPI ini.</p><div className="stack-top"><FormDialog trigger={<Button>Finalkan hasil</Button>} title="Finalkan hasil KPI?" description="Nilai sementara akan ditetapkan sebagai hasil resmi dan dikunci." size="small"><ActionForm action={finalizeMonthlyAction} className="dialog-body form-stack" closeOnSuccess><input type="hidden" name="monthlyKpiId" value={kpi.id} /><input type="hidden" name="rowVersion" value={kpi.rowVersion} />{worked === 0 ? <div className="field"><label htmlFor="noScoreReason">Alasan tanpa skor</label><textarea className="control" id="noScoreReason" name="noScoreReason" maxLength={1000} required /></div> : <div className="notice">Nilai akhir {formatNumber(kpi.finalScore)} akan ditetapkan sebagai hasil resmi.</div>}<footer className="dialog-actions"><DialogCancel /><SubmitButton>Finalkan hasil</SubmitButton></footer></ActionForm></FormDialog></div></div></section> : null}
        {user.role === "ADMIN" && kpi.status === "FINALIZED" ? <section className="panel"><div className="panel-body"><h2>Buka kembali hasil</h2><p className="help">Gunakan hanya untuk koreksi. Tindakan dan alasannya dicatat.</p><div className="stack-top"><FormDialog trigger={<Button variant="danger">Buka kembali</Button>} title="Buka kembali hasil?" description="Hasil final akan kembali dapat dikoreksi. Alasan wajib dicatat." size="small"><ActionForm action={reopenMonthlyAction} className="dialog-body form-stack" closeOnSuccess><input type="hidden" name="monthlyKpiId" value={kpi.id} /><input type="hidden" name="rowVersion" value={kpi.rowVersion} /><div className="field"><label htmlFor="reason">Alasan</label><textarea className="control" id="reason" name="reason" maxLength={1000} required /></div><footer className="dialog-actions"><DialogCancel /><SubmitButton variant="danger">Buka kembali</SubmitButton></footer></ActionForm></FormDialog></div></div></section> : null}
        {!periodEnded && kpi.status !== "FINALIZED" ? <div className="notice stack-top">Finalisasi tersedia setelah {formatDate(kpi.period.endDate)}.</div> : null}
      </aside></div>
    <DailyHistory sheets={kpi.dailySheets} items={kpi.items} selectedSheetId={query.sheet} baseHref={`/app/rekap/${kpi.id}`} canViewSelectedDetails={selectedSheet ? canViewDailyDetail(user, subject, selectedSheet.status) : false} reviewEnabled={user.role === "MANAGER" && kpi.subjectRoleSnapshot === "EMPLOYEE"} monthlyFinalized={kpi.status === "FINALIZED"} />
  </>;
}
