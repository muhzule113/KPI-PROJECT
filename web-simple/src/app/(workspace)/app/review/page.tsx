import { ChecksIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { EmptyState, PageHeader } from "@/components/page-elements";
import { ReviewForm } from "@/components/review-form";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { DatePickerField } from "@/components/ui/form-controls";
import { prisma } from "@/lib/prisma";
import { formatDate } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";

const validDate = /^\d{4}-\d{2}-\d{2}$/;

export default async function ReviewPage({ searchParams }: { searchParams: Promise<{ date?: string; sheet?: string }> }) {
  const user = await requireRole("MANAGER");
  const query = await searchParams;
  const date = query.date && validDate.test(query.date) ? query.date : "";
  const scope = { managerIdSnapshot: user.employee!.id, subjectRoleSnapshot: "EMPLOYEE" as const };
  const queue = await prisma.dailySheet.findMany({
    where: { status: "SUBMITTED", monthlyKpi: scope, ...(date ? { entryDate: new Date(`${date}T00:00:00.000Z`) } : {}) },
    orderBy: [{ entryDate: "asc" }, { monthlyKpi: { employeeNameSnapshot: "asc" } }],
    include: { monthlyKpi: true },
  });
  const selectedId = query.sheet ?? queue[0]?.id;
  const selected = selectedId ? await prisma.dailySheet.findFirst({
    where: { id: selectedId, status: { in: ["SUBMITTED", "APPROVED"] }, monthlyKpi: scope },
    include: {
      monthlyKpi: { include: { items: { orderBy: { sortOrderSnapshot: "asc" } } } },
      values: true,
      evidence: { orderBy: { createdAt: "asc" } },
    },
  }) : null;

  return <><PageHeader eyebrow="Manager" title="Review penilaian staf" description="Periksa nilai awal dari Supervisor. Setujui, koreksi dengan alasan, atau kembalikan untuk diperbaiki." />
    <form method="get" className="toolbar"><DatePickerField label="Filter tanggal (opsional)" name="date" defaultValue={date} /><Button type="submit" variant="secondary">Terapkan filter</Button>{date ? <Button asChild variant="ghost"><Link href="/app/review">Hapus filter</Link></Button> : null}<span className="help">{queue.length} lembar menunggu review</span></form>
    {!queue.length && !selected ? <EmptyState icon={ChecksIcon} title="Tidak ada antrean review" description="Lembar yang dikirim Supervisor akan muncul di sini. Anda tetap dapat mengoreksi lembar yang sudah disetujui melalui rincian rekap." /> : <div className="detail-grid">
      <section className="panel"><div className="panel-header"><div><h2>Antrean</h2><p>Urut dari tanggal terlama</p></div></div>{queue.length ? <div className="nav-list selection-list">{queue.map((sheet) => <Link className={`nav-link selection-link ${selected?.id === sheet.id ? "active" : ""}`} href={`/app/review?${date ? `date=${date}&` : ""}sheet=${sheet.id}`} key={sheet.id}><span className="selection-copy"><strong>{sheet.monthlyKpi.employeeNameSnapshot}</strong><small>{formatDate(sheet.entryDate)}</small></span><StatusBadge status={sheet.status} /></Link>)}</div> : <div className="panel-body"><p className="help">Antrean pada filter ini kosong.</p></div>}</section>
      {selected ? <section className="panel"><div className="panel-header"><div><h2>{selected.monthlyKpi.employeeNameSnapshot}</h2><p>{selected.monthlyKpi.positionNameSnapshot} · {formatDate(selected.entryDate)}</p></div><StatusBadge status={selected.status} /></div><div className="panel-body"><ReviewForm sheet={{ id: selected.id, rowVersion: selected.rowVersion, status: selected.status, workStatus: selected.workStatus!, note: selected.note, items: selected.monthlyKpi.items.map((item) => ({ id: item.id, name: item.nameSnapshot, description: item.descriptionSnapshot, kind: item.kindSnapshot, unit: item.unitSnapshot, target: item.targetSnapshot.toString(), value: selected.values.find((value) => value.monthlyKpiItemId === item.id)?.enteredValue?.toString() ?? null })) }} />{selected.evidence.length ? <section className="evidence-section"><h3 className="evidence-heading">Bukti pendukung</h3><ul className="evidence-list">{selected.evidence.map((item) => <li className="evidence-item" key={item.id}><a href={`/api/evidence/${item.id}`}>{item.fileName}</a><span>{Math.ceil(Number(item.fileSize) / 1024)} KB</span></li>)}</ul></section> : null}</div></section> : null}
    </div>}
  </>;
}
