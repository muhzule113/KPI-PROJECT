import { notFound, redirect } from "next/navigation";
import Link from "next/link";
import { ActionForm, SubmitButton } from "@/components/action-form";
import { PageHeader } from "@/components/page-elements";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { DialogCancel, FormDialog } from "@/components/ui/form-dialog";
import { finalizeMonthlyAction, reopenMonthlyAction } from "@/app/(workspace)/app/rekap/actions";
import { prisma } from "@/lib/prisma";
import { todayInMakassar } from "@/lib/date";
import { formatDate, formatNumber } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";
import { canViewMonthly } from "@/modules/access/policy";
import { kpiSubjectFromSnapshot } from "@/modules/kpi/monthly-operations";

export default async function RecapDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const user = await requireRole("ADMIN", "MANAGER", "SUPERVISOR");
  const kpi = await prisma.monthlyKpi.findUnique({ where: { id: (await params).id }, include: { period: true, items: { orderBy: { sortOrderSnapshot: "asc" } }, dailySheets: { orderBy: { entryDate: "asc" } } } });
  if (!kpi) notFound();
  if (!canViewMonthly(user, kpiSubjectFromSnapshot(kpi))) redirect("/akses-ditolak?reason=Hasil tidak berada dalam cakupan Anda");
  const approved = kpi.dailySheets.filter((sheet) => sheet.status === "APPROVED").length;
  const worked = kpi.dailySheets.filter((sheet) => sheet.status === "APPROVED" && sheet.effectiveWorkStatus === "WORKED").length;
  const periodEnded = todayInMakassar() > kpi.period.endDate.toISOString().slice(0, 10);
  const canFinalize = user.role === "MANAGER" && kpi.managerIdSnapshot === user.employeeId && kpi.status !== "FINALIZED" && periodEnded && approved === kpi.dailySheets.length;

  return <><PageHeader eyebrow={kpi.period.name} title={kpi.employeeNameSnapshot} description={`${kpi.employeeNumberSnapshot} · ${kpi.positionNameSnapshot} · ${kpi.branchNameSnapshot}`} actions={<Button asChild variant="secondary"><Link href={`/app/rekap?periodId=${kpi.periodId}`}>Kembali ke rekap</Link></Button>} />
    <div className="detail-grid"><section className="panel"><div className="panel-header"><div><h2>Akumulasi indikator</h2><p>Hanya nilai efektif dari hari Bekerja yang disetujui</p></div><StatusBadge status={kpi.status} /></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Indikator</th><th>Aktual</th><th>Target</th><th>Pencapaian</th><th>Bobot</th><th>Skor</th></tr></thead><tbody>{kpi.items.map((item) => <tr key={item.id}><td><span className="cell-title">{item.nameSnapshot}</span><span className="cell-subtitle">{item.aggregationSnapshot === "SUM" ? "Jumlah" : "Rata-rata"}</span></td><td>{formatNumber(item.actual)} {item.unitSnapshot}</td><td>{formatNumber(item.targetSnapshot)} {item.unitSnapshot}</td><td>{formatNumber(item.achievementPercentage)}%</td><td>{formatNumber(item.weightSnapshot)}%</td><td><strong>{formatNumber(item.weightedScore)}</strong></td></tr>)}</tbody></table></div></section>
      <aside><section className="panel"><div className="panel-body"><p className="eyebrow">Nilai akhir</p><div className="score">{formatNumber(kpi.finalScore)}</div><p className="page-description">{kpi.ratingLabel ?? kpi.noScoreReason ?? "Belum dapat dihitung"}</p><dl className="definition-list sheet-definition"><div><dt>Hari disetujui</dt><dd>{approved}/{kpi.dailySheets.length}</dd></div><div><dt>Hari bekerja</dt><dd>{worked}</dd></div><div><dt>Revisi</dt><dd>{kpi.revisionNumber}</dd></div><div><dt>Finalisasi</dt><dd>{kpi.finalizedAt ? formatDate(kpi.finalizedAt) : "Belum final"}</dd></div></dl></div></section>
        {canFinalize ? <section className="panel"><div className="panel-body"><h2>Finalisasi hasil</h2><p className="help">Setelah final, Pegawai dapat melihat hasil ini.</p><div className="stack-top"><FormDialog trigger={<Button>Finalkan hasil</Button>} title="Finalkan hasil KPI?" description="Nilai akan dikunci dan dapat dilihat oleh Pegawai." size="small"><ActionForm action={finalizeMonthlyAction} className="dialog-body form-stack" closeOnSuccess><input type="hidden" name="monthlyKpiId" value={kpi.id} /><input type="hidden" name="rowVersion" value={kpi.rowVersion} />{worked === 0 ? <div className="field"><label htmlFor="noScoreReason">Alasan tanpa skor</label><textarea className="control" id="noScoreReason" name="noScoreReason" maxLength={1000} required /></div> : <div className="notice">Nilai akhir {formatNumber(kpi.finalScore)} akan ditetapkan sebagai hasil resmi.</div>}<footer className="dialog-actions"><DialogCancel /><SubmitButton>Finalkan hasil</SubmitButton></footer></ActionForm></FormDialog></div></div></section> : null}
        {user.role === "ADMIN" && kpi.status === "FINALIZED" ? <section className="panel"><div className="panel-body"><h2>Buka kembali hasil</h2><p className="help">Gunakan hanya untuk koreksi. Tindakan dan alasannya dicatat.</p><div className="stack-top"><FormDialog trigger={<Button variant="danger">Buka kembali</Button>} title="Buka kembali hasil?" description="Hasil final akan kembali dapat dikoreksi. Alasan wajib dicatat." size="small"><ActionForm action={reopenMonthlyAction} className="dialog-body form-stack" closeOnSuccess><input type="hidden" name="monthlyKpiId" value={kpi.id} /><input type="hidden" name="rowVersion" value={kpi.rowVersion} /><div className="field"><label htmlFor="reason">Alasan</label><textarea className="control" id="reason" name="reason" maxLength={1000} required /></div><footer className="dialog-actions"><DialogCancel /><SubmitButton variant="danger">Buka kembali</SubmitButton></footer></ActionForm></FormDialog></div></div></section> : null}
        {!periodEnded && kpi.status !== "FINALIZED" ? <div className="notice stack-top">Finalisasi tersedia setelah {formatDate(kpi.period.endDate)}.</div> : null}
      </aside></div>
    <section className="panel stack-top"><div className="panel-header"><div><h2>Riwayat hari</h2><p>Status kerja dan persetujuan setiap tanggal</p></div></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Tanggal</th><th>Status kerja</th><th>Lembar</th><th>Catatan Manager</th><th></th></tr></thead><tbody>{kpi.dailySheets.map((sheet) => <tr key={sheet.id}><td>{formatDate(sheet.entryDate)}</td><td><StatusBadge status={sheet.effectiveWorkStatus ?? sheet.workStatus} /></td><td><StatusBadge status={sheet.status} /></td><td>{sheet.managerReason || "Tidak ada catatan"}</td><td>{user.role === "MANAGER" && kpi.subjectRoleSnapshot === "EMPLOYEE" && ["SUBMITTED", "APPROVED"].includes(sheet.status) && kpi.status !== "FINALIZED" ? <Button asChild size="small" variant="secondary"><Link href={`/app/review?sheet=${sheet.id}`}>{sheet.status === "APPROVED" ? "Koreksi" : "Review"}</Link></Button> : null}</td></tr>)}</tbody></table></div></section>
  </>;
}
