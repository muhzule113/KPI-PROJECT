import { ChecksIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { Pagination } from "@/components/pagination";
import { EmptyState, PageHeader } from "@/components/page-elements";
import { ReviewForm } from "@/components/review-form";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { DatePickerField, SearchField, SelectField } from "@/components/ui/form-controls";
import { prisma } from "@/lib/prisma";
import { getPageCount, PAGINATION_PAGE_SIZE, parsePage } from "@/lib/pagination";
import { parseSearch } from "@/lib/search";
import { formatDate } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";
import { dailyValueView } from "@/modules/kpi/daily-value-view";

const validDate = /^\d{4}-\d{2}-\d{2}$/;

export default async function ReviewPage({ searchParams }: { searchParams: Promise<{ date?: string; sheet?: string; page?: string; q?: string }> }) {
  const user = await requireRole("MANAGER");
  const query = await searchParams;
  const date = query.date && validDate.test(query.date) ? query.date : "";
  const q = parseSearch(query.q);
  const scope = { managerIdSnapshot: user.employee!.id, subjectRoleSnapshot: "EMPLOYEE" as const };
  const queueWhere = {
    status: "SUBMITTED" as const,
    monthlyKpi: {
      ...scope,
      ...(q ? { OR: [
        { employeeNameSnapshot: { contains: q, mode: "insensitive" as const } },
        { employeeNumberSnapshot: { contains: q, mode: "insensitive" as const } },
        { positionNameSnapshot: { contains: q, mode: "insensitive" as const } },
        { branchNameSnapshot: { contains: q, mode: "insensitive" as const } },
      ] } : {}),
    },
    ...(date ? { entryDate: new Date(`${date}T00:00:00.000Z`) } : {}),
  };
  const queueCount = await prisma.dailySheet.count({ where: queueWhere });
  const totalPages = getPageCount(queueCount);
  const page = Math.min(parsePage(query.page), totalPages);
  const queue = await prisma.dailySheet.findMany({
    where: queueWhere,
    orderBy: [{ entryDate: "asc" }, { monthlyKpi: { employeeNameSnapshot: "asc" } }],
    skip: (page - 1) * PAGINATION_PAGE_SIZE,
    take: PAGINATION_PAGE_SIZE,
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
  const pickerOptions = [
    ...(selected && !queue.some((sheet) => sheet.id === selected.id) ? [{ value: selected.id, label: `${selected.monthlyKpi.employeeNameSnapshot} / ${formatDate(selected.entryDate)}` }] : []),
    ...queue.map((sheet) => ({ value: sheet.id, label: `${sheet.monthlyKpi.employeeNameSnapshot} / ${formatDate(sheet.entryDate)}` })),
  ];
  const dailyItems = selected ? dailyValueView(selected.monthlyKpi.items, selected.values) : [];
  const reviewHref = ({ date: nextDate, page: nextPage, q: nextQ, sheet }: { date?: string; page?: number; q?: string; sheet?: string }) => {
    const params = new URLSearchParams();
    if (nextDate) params.set("date", nextDate);
    if (nextPage) params.set("page", String(nextPage));
    if (nextQ) params.set("q", nextQ);
    if (sheet) params.set("sheet", sheet);
    return `/app/review${params.toString() ? `?${params.toString()}` : ""}`;
  };

  return <><PageHeader eyebrow="Manager" title="Review penilaian staf" description="Periksa nilai awal dari Supervisor. Setujui, koreksi dengan alasan, atau kembalikan untuk diperbaiki." />
    <form method="get" className="toolbar"><DatePickerField label="Filter tanggal (opsional)" name="date" defaultValue={date} /><SearchField label="Cari pegawai" defaultValue={q} placeholder="Nama, nomor, jabatan, atau cabang" /><Button type="submit" variant="secondary">Terapkan filter</Button>{date ? <Button asChild variant="ghost"><Link href={reviewHref({ q })}>Hapus filter</Link></Button> : null}{q ? <Button asChild variant="ghost"><Link href={reviewHref({ date })}>Hapus pencarian</Link></Button> : null}<span className="help">{queueCount} lembar menunggu review</span></form>
    {!queue.length && !selected ? <EmptyState icon={ChecksIcon} title={q ? "Tidak ada hasil pencarian" : "Tidak ada antrean review"} description={q ? `Tidak ada lembar yang cocok dengan "${q}".` : "Lembar yang dikirim Supervisor akan muncul di sini. Anda tetap dapat mengoreksi lembar yang sudah disetujui melalui rincian rekap."} action={q ? <Button asChild variant="secondary"><Link href={reviewHref({ date })}>Hapus pencarian</Link></Button> : undefined} /> : <div className="detail-grid">
      {pickerOptions.length > 1 || !selected || totalPages > 1 ? <form method="get" className="panel mobile-sheet-picker"><div className="panel-body mobile-sheet-picker-body"><input type="hidden" name="date" value={date} />{q ? <input type="hidden" name="q" value={q} /> : null}<input type="hidden" name="page" value={String(page)} /><SelectField label="Lembar yang direview" name="sheet" defaultValue={selected?.id ?? pickerOptions[0]?.value} options={pickerOptions} required /><Button type="submit" variant="secondary">Buka lembar</Button></div><Pagination page={page} totalPages={totalPages} hrefForPage={(nextPage) => reviewHref({ date, page: nextPage, q })} /></form> : null}
      <section className="panel desktop-sheet-list"><div className="panel-header"><div><h2>Antrean</h2><p>Urut dari tanggal terlama</p></div></div>{queue.length ? <div className="nav-list selection-list">{queue.map((sheet) => <Link className={`nav-link selection-link ${selected?.id === sheet.id ? "active" : ""}`} href={reviewHref({ sheet: sheet.id, page, date, q })} key={sheet.id}><span className="selection-copy"><strong>{sheet.monthlyKpi.employeeNameSnapshot}</strong><small>{formatDate(sheet.entryDate)}</small></span><StatusBadge status={sheet.status} /></Link>)}</div> : <div className="panel-body"><p className="help">Antrean pada filter ini kosong.</p></div>}<Pagination page={page} totalPages={totalPages} hrefForPage={(nextPage) => reviewHref({ date, page: nextPage, q })} /></section>
      {selected ? <section className="panel">
        <div className="panel-header"><div><h2>{selected.monthlyKpi.employeeNameSnapshot}</h2><p>{selected.monthlyKpi.positionNameSnapshot} · {formatDate(selected.entryDate)}</p></div><StatusBadge status={selected.status} /></div>
        <div className="panel-body">
          <ReviewForm sheet={{
            id: selected.id,
            rowVersion: selected.rowVersion,
            status: selected.status,
            workStatus: selected.workStatus!,
            note: selected.note,
            items: dailyItems,
          }} />
          {selected.evidence.length ? <section className="evidence-section"><h3 className="evidence-heading">Bukti pendukung</h3><ul className="evidence-list">{selected.evidence.map((item) => <li className="evidence-item" key={item.id}><a href={`/api/evidence/${item.id}`}>{item.fileName}</a><span>{Math.ceil(Number(item.fileSize) / 1024)} KB</span></li>)}</ul></section> : null}
        </div>
      </section> : null}
    </div>}
  </>;
}
