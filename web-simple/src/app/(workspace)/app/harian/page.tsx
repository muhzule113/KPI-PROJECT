import { CalendarDotsIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { DailySheetForm } from "@/components/daily-sheet-form";
import { EmptyState, PageHeader } from "@/components/page-elements";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { DatePickerField, SearchField, SelectField } from "@/components/ui/form-controls";
import { prisma } from "@/lib/prisma";
import { todayInMakassar } from "@/lib/date";
import { formatDate } from "@/lib/utils";
import { parseSearch } from "@/lib/search";
import { requireRole } from "@/modules/access/current-user";
import { canChangeEvidence } from "@/modules/files/evidence";
import { dailyValueView } from "@/modules/kpi/daily-value-view";

const validDate = /^\d{4}-\d{2}-\d{2}$/;

export default async function DailyPage({ searchParams }: { searchParams: Promise<{ date?: string; sheet?: string; q?: string }> }) {
  const user = await requireRole("MANAGER", "SUPERVISOR");
  const query = await searchParams;
  const today = todayInMakassar();
  const date = query.date && validDate.test(query.date) ? query.date : today;
  const q = parseSearch(query.q);
  const entryDate = new Date(`${date}T00:00:00.000Z`);
  const monthlyScope = user.role === "MANAGER"
    ? { managerIdSnapshot: user.employee!.id, subjectRoleSnapshot: "SUPERVISOR" as const }
    : { supervisorIdSnapshot: user.employee!.id, subjectRoleSnapshot: "EMPLOYEE" as const };
  const sheets = await prisma.dailySheet.findMany({
    where: {
      entryDate,
      monthlyKpi: {
        ...monthlyScope,
        ...(q ? { OR: [
          { employeeNameSnapshot: { contains: q, mode: "insensitive" as const } },
          { employeeNumberSnapshot: { contains: q, mode: "insensitive" as const } },
          { positionNameSnapshot: { contains: q, mode: "insensitive" as const } },
          { branchNameSnapshot: { contains: q, mode: "insensitive" as const } },
        ] } : {}),
      },
    },
    orderBy: { monthlyKpi: { employeeNameSnapshot: "asc" } },
    include: {
      monthlyKpi: { include: { items: { orderBy: { sortOrderSnapshot: "asc" } } } },
      values: true,
      evidence: { orderBy: { createdAt: "asc" } },
    },
  });
  const selected = sheets.find((sheet) => sheet.id === query.sheet) ?? sheets[0];
  const selectedIndex = selected ? sheets.findIndex((sheet) => sheet.id === selected.id) : -1;
  const completed = sheets.filter((sheet) => ["SUBMITTED", "APPROVED"].includes(sheet.status)).length;
  const future = date > today;
  const sheetHref = (sheetId: string) => {
    const params = new URLSearchParams({ date, sheet: sheetId });
    if (q) params.set("q", q);
    return `/app/harian?${params.toString()}`;
  };

  const dailyItems = selected ? dailyValueView(selected.monthlyKpi.items, selected.values) : [];

  return <><PageHeader eyebrow="Penilaian harian" title={user.role === "MANAGER" ? "Nilai Supervisor" : "Nilai anggota tim"} description="Pilih tanggal, isi status kerja, lalu masukkan seluruh nilai aktual untuk hari bekerja." />
    <div className="assessment-context">
      <form method="get" className="toolbar assessment-date-toolbar"><DatePickerField label="Tanggal penilaian" name="date" defaultValue={date} max={today} required /><SearchField label="Cari pegawai" defaultValue={q} placeholder="Nama, nomor, jabatan, atau cabang" /><Button type="submit" variant="secondary">Tampilkan</Button>{q ? <Button asChild variant="ghost"><Link href={`/app/harian?date=${date}`}>Hapus pencarian</Link></Button> : null}<span className="help">{completed} dari {sheets.length} lembar sudah dikirim/disetujui</span></form>
      {selected && sheets.length > 1 ? <form method="get" className="panel mobile-sheet-picker"><div className="panel-body mobile-sheet-picker-body"><input type="hidden" name="date" value={date} />{q ? <input type="hidden" name="q" value={q} /> : null}<SelectField label="Lompat ke pegawai" name="sheet" defaultValue={selected.id} options={sheets.map((sheet) => ({ value: sheet.id, label: `${sheet.monthlyKpi.employeeNameSnapshot} / ${sheet.monthlyKpi.positionNameSnapshot}` }))} required /><Button type="submit" variant="secondary">Buka lembar</Button><div className="employee-pager" aria-label="Navigasi pegawai">
        {selectedIndex > 0 ? <Link className="employee-pager-link" href={sheetHref(sheets[selectedIndex - 1].id)}>Sebelumnya</Link> : <span className="employee-pager-link is-disabled" aria-disabled="true">Sebelumnya</span>}
        <span className="employee-pager-position" aria-live="polite">{selectedIndex + 1} dari {sheets.length}</span>
        {selectedIndex < sheets.length - 1 ? <Link className="employee-pager-link" href={sheetHref(sheets[selectedIndex + 1].id)}>Berikutnya</Link> : <span className="employee-pager-link is-disabled" aria-disabled="true">Berikutnya</span>}
      </div></div></form> : null}
    </div>
    {future ? <div className="notice">Tanggal mendatang tidak dapat dinilai.</div> : null}
    {!sheets.length ? <EmptyState icon={CalendarDotsIcon} title={q ? "Pegawai tidak ditemukan" : "Tidak ada lembar pada tanggal ini"} description={q ? `Tidak ada pegawai yang cocok dengan "${q}" pada tanggal ini.` : "Pastikan periode sudah dibuka dan pegawai berada dalam masa kerja pada tanggal tersebut."} action={q ? <Button asChild variant="secondary"><Link href={`/app/harian?date=${date}`}>Hapus pencarian</Link></Button> : undefined} /> : <div className="detail-grid">
      <section className="panel desktop-sheet-list"><div className="panel-header"><div><h2>Daftar pegawai</h2><p>{formatDate(entryDate)}</p></div></div><div className="nav-list selection-list">{sheets.map((sheet) => <Link className={`nav-link selection-link ${selected?.id === sheet.id ? "active" : ""}`} href={sheetHref(sheet.id)} key={sheet.id}><span className="selection-copy"><strong>{sheet.monthlyKpi.employeeNameSnapshot}</strong><small>{sheet.monthlyKpi.positionNameSnapshot}</small></span><StatusBadge status={sheet.status} /></Link>)}</div></section>
      {selected ? <section className="panel">
        <div className="panel-header"><div><h2>{selected.monthlyKpi.employeeNameSnapshot}</h2><p>{selected.monthlyKpi.positionNameSnapshot} · {formatDate(selected.entryDate)}</p></div><StatusBadge status={selected.status} /></div>
        <div className="panel-body"><DailySheetForm
          key={selected.id}
          directApproval={user.role === "MANAGER"}
          editable={!future && selected.monthlyKpi.status !== "FINALIZED" && (["PENDING", "DRAFT", "REVISION_REQUIRED"].includes(selected.status) || (user.role === "MANAGER" && selected.status === "APPROVED"))}
          evidenceEditable={!future && canChangeEvidence(selected.status, selected.monthlyKpi.status)}
          sheet={{
            id: selected.id,
            rowVersion: selected.rowVersion,
            status: selected.status,
            workStatus: selected.workStatus,
            note: selected.note,
            managerReason: selected.managerReason,
            items: dailyItems,
            evidence: selected.evidence.map((item) => ({ id: item.id, fileName: item.fileName, fileSize: `${Math.ceil(Number(item.fileSize) / 1024)} KB` })),
          }}
        /></div>
      </section> : null}
    </div>}
  </>;
}
