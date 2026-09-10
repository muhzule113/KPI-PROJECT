import { CalendarDotsIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { DailySheetForm } from "@/components/daily-sheet-form";
import { EmptyState, PageHeader } from "@/components/page-elements";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { DatePickerField, SelectField } from "@/components/ui/form-controls";
import { prisma } from "@/lib/prisma";
import { todayInMakassar } from "@/lib/date";
import { formatDate } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";
import { canChangeEvidence } from "@/modules/files/evidence";

const validDate = /^\d{4}-\d{2}-\d{2}$/;

export default async function DailyPage({ searchParams }: { searchParams: Promise<{ date?: string; sheet?: string }> }) {
  const user = await requireRole("MANAGER", "SUPERVISOR");
  const query = await searchParams;
  const today = todayInMakassar();
  const date = query.date && validDate.test(query.date) ? query.date : today;
  const entryDate = new Date(`${date}T00:00:00.000Z`);
  const monthlyScope = user.role === "MANAGER"
    ? { managerIdSnapshot: user.employee!.id, subjectRoleSnapshot: "SUPERVISOR" as const }
    : { supervisorIdSnapshot: user.employee!.id, subjectRoleSnapshot: "EMPLOYEE" as const };
  const sheets = await prisma.dailySheet.findMany({
    where: { entryDate, monthlyKpi: monthlyScope },
    orderBy: { monthlyKpi: { employeeNameSnapshot: "asc" } },
    include: {
      monthlyKpi: { include: { items: { orderBy: { sortOrderSnapshot: "asc" } } } },
      values: true,
      evidence: { orderBy: { createdAt: "asc" } },
    },
  });
  const selected = sheets.find((sheet) => sheet.id === query.sheet) ?? sheets[0];
  const completed = sheets.filter((sheet) => ["SUBMITTED", "APPROVED"].includes(sheet.status)).length;
  const future = date > today;

  return <><PageHeader eyebrow="Penilaian harian" title={user.role === "MANAGER" ? "Nilai Supervisor" : "Nilai anggota tim"} description="Pilih tanggal, isi status kerja, lalu masukkan seluruh nilai aktual untuk hari bekerja." />
    <form method="get" className="toolbar"><DatePickerField label="Tanggal penilaian" name="date" defaultValue={date} max={today} required /><Button type="submit" variant="secondary">Tampilkan</Button><span className="help">{completed} dari {sheets.length} lembar sudah dikirim/disetujui</span></form>
    {future ? <div className="notice">Tanggal mendatang tidak dapat dinilai.</div> : null}
    {!sheets.length ? <EmptyState icon={CalendarDotsIcon} title="Tidak ada lembar pada tanggal ini" description="Pastikan periode sudah dibuka dan pegawai berada dalam masa kerja pada tanggal tersebut." /> : <div className="detail-grid">
      {selected && sheets.length > 1 ? <form method="get" className="panel mobile-sheet-picker"><div className="panel-body mobile-sheet-picker-body"><input type="hidden" name="date" value={date} /><SelectField label="Pegawai yang dinilai" name="sheet" defaultValue={selected.id} options={sheets.map((sheet) => ({ value: sheet.id, label: `${sheet.monthlyKpi.employeeNameSnapshot} / ${sheet.monthlyKpi.positionNameSnapshot}` }))} required /><Button type="submit" variant="secondary">Buka lembar</Button></div></form> : null}
      <section className="panel desktop-sheet-list"><div className="panel-header"><div><h2>Daftar pegawai</h2><p>{formatDate(entryDate)}</p></div></div><div className="nav-list selection-list">{sheets.map((sheet) => <Link className={`nav-link selection-link ${selected?.id === sheet.id ? "active" : ""}`} href={`/app/harian?date=${date}&sheet=${sheet.id}`} key={sheet.id}><span className="selection-copy"><strong>{sheet.monthlyKpi.employeeNameSnapshot}</strong><small>{sheet.monthlyKpi.positionNameSnapshot}</small></span><StatusBadge status={sheet.status} /></Link>)}</div></section>
      {selected ? <section className="panel"><div className="panel-header"><div><h2>{selected.monthlyKpi.employeeNameSnapshot}</h2><p>{selected.monthlyKpi.positionNameSnapshot} · {formatDate(selected.entryDate)}</p></div><StatusBadge status={selected.status} /></div><div className="panel-body"><DailySheetForm directApproval={user.role === "MANAGER"} editable={!future && selected.monthlyKpi.status !== "FINALIZED" && (["PENDING", "DRAFT", "REVISION_REQUIRED"].includes(selected.status) || (user.role === "MANAGER" && selected.status === "APPROVED"))} evidenceEditable={!future && canChangeEvidence(selected.status, selected.monthlyKpi.status)} sheet={{ id: selected.id, rowVersion: selected.rowVersion, status: selected.status, workStatus: selected.workStatus, note: selected.note, managerReason: selected.managerReason, items: selected.monthlyKpi.items.map((item) => ({ id: item.id, name: item.nameSnapshot, description: item.descriptionSnapshot, kind: item.kindSnapshot, unit: item.unitSnapshot, target: item.targetSnapshot.toString(), value: selected.values.find((value) => value.monthlyKpiItemId === item.id)?.enteredValue?.toString() ?? null })), evidence: selected.evidence.map((item) => ({ id: item.id, fileName: item.fileName, fileSize: `${Math.ceil(Number(item.fileSize) / 1024)} KB` })) }} /></div></section> : null}
    </div>}
  </>;
}
