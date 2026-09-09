import { CalendarCheckIcon, ClipboardTextIcon, PackageIcon, SmileyIcon, WarningCircleIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { EmptyState } from "@/components/empty-state";
import { AttendanceForm, CoachingForm, ComplaintForm, StockForms, StockOpnameList, WorkLogForm } from "@/components/operational/operational-forms";
import { ReportSubmissionList } from "@/components/operational/report-submission-list";
import { PageHeading } from "@/components/page-heading";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { formatDate } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { capabilitiesFor } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";

export const metadata: Metadata = { title: "Operasional" };

export default async function OperationsPage({ searchParams }: { searchParams: Promise<{ date?: string }> }) {
  const user = await requireUser();
  const caps = capabilitiesFor(user);
  const showAttendance = caps.has("attendance.manage") || caps.has("attendance.team.manage");
  const showWorkLogs = caps.has("work-logs.manage");
  const showComplaints = caps.has("complaints.manage") || caps.has("complaints.create") || caps.has("complaints.validate");
  const showCoaching = caps.has("coaching.manage");
  const showStock = caps.has("spareparts.manage") || caps.has("stock-opname.manage");
  const showReports = caps.has("reports.submit") && Boolean(user.employee);
  if (!showAttendance && !showWorkLogs && !showComplaints && !showCoaching && !showStock && !showReports) redirect("/app");
  const branchId = ["super_admin", "kpi_admin", "auditor"].includes(user.role) ? undefined : user.employee?.branchId;
  const employeeId = user.employee?.id;
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Makassar", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
  const requestedDate = (await searchParams).date;
  const selectedDate = requestedDate && /^\d{4}-\d{2}-\d{2}$/.test(requestedDate) && requestedDate <= today ? requestedDate : today;
  const selectedDateValue = new Date(`${selectedDate}T00:00:00.000Z`);
  const activePeriod = await prisma.kpiPeriod.findFirst({ where: { status: "OPEN", startDate: { lte: selectedDateValue }, endDate: { gte: selectedDateValue } }, orderBy: { year: "desc" } });

  const rosterWhere = user.role === "super_admin"
    ? {}
    : user.role === "supervisor"
      ? { supervisorIdSnapshot: employeeId ?? "__no_access__" }
      : { managerIdSnapshot: employeeId ?? "__no_access__" };

  const [attendances, workLogs, complaints, coaching, lowStock, stockParts, stockOpnames, stockBranches, roster, selectedWorkLog, complaintEmployees, complaintTickets, reports] = await Promise.all([
    showAttendance ? prisma.attendance.findMany({ where: { branchId }, orderBy: [{ attendanceDate: "desc" }, { createdAt: "desc" }], take: 8, include: { employee: true, branch: true } }) : Promise.resolve([]),
    showWorkLogs ? prisma.adminWorkLog.findMany({ where: { employeeId }, orderBy: { workDate: "desc" }, take: 8, include: { employee: true } }) : Promise.resolve([]),
    showComplaints ? prisma.complaint.findMany({ where: { OR: branchId ? [{ employee: { branchId } }, { ticket: { branchId } }] : undefined }, orderBy: { complaintDate: "desc" }, take: 8, include: { employee: true, ticket: true } }) : Promise.resolve([]),
    showCoaching ? prisma.coachingLog.findMany({ where: { supervisorId: employeeId }, orderBy: { coachingDate: "desc" }, take: 8, include: { employee: true } }) : Promise.resolve([]),
    showStock ? prisma.sparepart.findMany({ where: { branchId, isCritical: true }, orderBy: { stockQuantity: "asc" }, take: 8 }) : Promise.resolve([]),
    showStock ? prisma.sparepart.findMany({ where: { branchId }, orderBy: [{ category: "asc" }, { name: "asc" }], take: 200, include: { branch: { select: { name: true } } } }) : Promise.resolve([]),
    showStock ? prisma.stockOpname.findMany({ where: { branchId, createdById: user.role === "super_admin" ? undefined : user.id }, orderBy: { createdAt: "desc" }, take: 6, include: { branch: { select: { name: true } }, period: { select: { name: true } }, items: { orderBy: { createdAt: "asc" }, include: { sparepart: { select: { code: true, name: true } } } } } }) : Promise.resolve([]),
    showStock ? prisma.branch.findMany({ where: { id: branchId, isActive: true }, orderBy: { name: "asc" }, select: { id: true, name: true } }) : Promise.resolve([]),
    showAttendance && activePeriod ? prisma.employeeKpi.findMany({
      where: { periodId: activePeriod.id, employeeId: { not: employeeId }, ...rosterWhere },
      orderBy: { employeeNameSnapshot: "asc" },
      select: { employee: { select: { id: true, name: true, position: { select: { name: true } }, attendances: { where: { attendanceDate: selectedDateValue }, take: 1, select: { status: true, note: true } } } } },
    }) : Promise.resolve([]),
    showWorkLogs && employeeId ? prisma.adminWorkLog.findUnique({ where: { employeeId_workDate: { employeeId, workDate: selectedDateValue } } }) : Promise.resolve(null),
    showComplaints ? prisma.employee.findMany({ where: { branchId, status: "ACTIVE" }, orderBy: { name: "asc" }, select: { id: true, name: true, position: { select: { name: true } } } }) : Promise.resolve([]),
    showComplaints ? prisma.serviceTicket.findMany({ where: { branchId }, orderBy: { createdAt: "desc" }, take: 50, select: { id: true, ticketNumber: true, customerName: true } }) : Promise.resolve([]),
    showReports ? prisma.reportSubmission.findMany({ where: { employeeId }, orderBy: [{ submittedAt: "asc" }, { deadlineAt: "asc" }], take: 12 }) : Promise.resolve([]),
  ]);
  const attendanceRows = roster.map(({ employee }) => ({ id: employee.id, name: employee.name, position: employee.position.name, status: employee.attendances[0]?.status ?? "", note: employee.attendances[0]?.note ?? "" }));
  const employeeOptions = complaintEmployees.map((employee) => ({ id: employee.id, label: `${employee.name} · ${employee.position.name}` }));
  const ticketOptions = complaintTickets.map((ticket) => ({ id: ticket.id, label: `${ticket.ticketNumber} · ${ticket.customerName}` }));

  return (
    <div className="space-y-7">
      <PageHeading eyebrow="Aktivitas harian" title="Operasional" description="Catat pekerjaan sumber. Sistem memperbarui fakta KPI tanpa input skor manual." action={<form method="get" className="flex items-end gap-2"><div><label htmlFor="date" className="sr-only">Tanggal kerja</label><Input id="date" name="date" type="date" max={today} defaultValue={selectedDate} /></div><Button type="submit" variant="outline">Tampilkan</Button></form>} />
      {!activePeriod ? <div role="status" className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">Tidak ada periode KPI terbuka pada {formatDate(selectedDateValue)}. Form pencatatan dinonaktifkan.</div> : null}
      {activePeriod ? <section className="space-y-5">
        {showAttendance ? <AttendanceForm date={selectedDate} rows={attendanceRows} /> : null}
        {showWorkLogs ? <WorkLogForm date={selectedDate} values={selectedWorkLog ? { recordsInput: selectedWorkLog.recordsInput, recordsCorrected: selectedWorkLog.recordsCorrected, documentsEligible: selectedWorkLog.documentsEligible, documentsComplete: selectedWorkLog.documentsComplete, reconciliationsTotal: selectedWorkLog.reconciliationsTotal, reconciliationsSuccess: selectedWorkLog.reconciliationsSuccess, notes: selectedWorkLog.notes ?? "" } : undefined} /> : null}
        {showComplaints && (caps.has("complaints.create") || caps.has("complaints.manage")) ? <ComplaintForm date={selectedDate} employees={employeeOptions} tickets={ticketOptions} /> : null}
        {showCoaching ? <CoachingForm date={selectedDate} employees={attendanceRows.map((employee) => ({ id: employee.id, label: `${employee.name} · ${employee.position}` }))} /> : null}
        {showStock ? <StockForms branches={stockBranches.map((branch) => ({ id: branch.id, label: branch.name }))} spareparts={stockParts.map((part) => ({ id: part.id, label: `${part.code} · ${part.name} · ${part.branch?.name ?? "Tanpa cabang"} · stok ${part.stockQuantity}` }))} /> : null}
      </section> : null}
      {showStock ? <StockOpnameList opnames={stockOpnames.map((opname) => ({ id: opname.id, code: opname.code, status: opname.status, branch: opname.branch.name, period: opname.period?.name ?? "Tanpa periode", deadline: opname.deadline ? formatDate(opname.deadline) : null, completedAt: opname.completedAt?.toISOString() ?? null, items: opname.items.map((item) => ({ id: item.id, code: item.sparepart.code, name: item.sparepart.name, systemStock: item.systemStock, physicalStock: item.physicalStock, difference: item.difference })) }))} /> : null}
      <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {showAttendance ? <Card><CardContent className="p-5"><CalendarCheckIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{attendances.length}</p><p className="text-xs text-muted-foreground">Absensi terbaru</p></CardContent></Card> : null}
        {showWorkLogs ? <Card><CardContent className="p-5"><ClipboardTextIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{workLogs.length}</p><p className="text-xs text-muted-foreground">Log kerja terbaru</p></CardContent></Card> : null}
        {showComplaints ? <Card><CardContent className="p-5"><WarningCircleIcon className="text-amber-600" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{complaints.filter((item) => item.status === "open").length}</p><p className="text-xs text-muted-foreground">Komplain terbuka</p></CardContent></Card> : null}
        {showStock ? <Card><CardContent className="p-5"><PackageIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{lowStock.length}</p><p className="text-xs text-muted-foreground">Stok kritis</p></CardContent></Card> : null}
      </section>

      <div className="grid gap-5 xl:grid-cols-2">
        {showReports ? <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><ClipboardTextIcon className="text-primary" /> Laporan terjadwal</CardTitle></CardHeader><CardContent>{reports.length ? <ReportSubmissionList reports={reports.map((report) => ({ id: report.id, type: report.reportType, date: formatDate(report.reportDate), deadline: new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Makassar" }).format(report.deadlineAt), submittedAt: report.submittedAt?.toISOString() ?? null, isOnTime: report.isOnTime }))} /> : <EmptyState icon={ClipboardTextIcon} title="Belum ada laporan terjadwal" description="Jadwal dibuat saat snapshot periode KPI disiapkan." />}</CardContent></Card> : null}
        {showAttendance ? <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><CalendarCheckIcon className="text-primary" /> Absensi terbaru</CardTitle></CardHeader><CardContent>{attendances.length ? <div className="divide-y">{attendances.map((item) => <div key={item.id} className="flex min-h-16 items-center justify-between gap-4 py-3"><div><p className="text-sm font-medium">{item.employee.name}</p><p className="text-xs text-muted-foreground">{item.branch.name} · {formatDate(item.attendanceDate)}</p></div><Badge variant={item.status === "present" ? "default" : "warning"}>{item.status}</Badge></div>)}</div> : <EmptyState icon={CalendarCheckIcon} title="Belum ada absensi" description="Data absensi yang dicatat akan tampil di sini." />}</CardContent></Card> : null}
        {showWorkLogs ? <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><ClipboardTextIcon className="text-primary" /> Log kerja administrasi</CardTitle></CardHeader><CardContent>{workLogs.length ? <div className="divide-y">{workLogs.map((item) => <div key={item.id} className="py-3"><div className="flex justify-between gap-4"><p className="text-sm font-medium">{item.employee.name}</p><span className="text-xs text-muted-foreground">{formatDate(item.workDate)}</span></div><p className="mt-1 text-xs text-muted-foreground">{item.recordsInput} input · {item.documentsComplete}/{item.documentsEligible} dokumen lengkap</p></div>)}</div> : <EmptyState icon={ClipboardTextIcon} title="Belum ada log kerja" description="Catatan administrasi harian akan tampil di sini." />}</CardContent></Card> : null}
        {showComplaints ? <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><WarningCircleIcon className="text-amber-600" /> Komplain pelanggan</CardTitle></CardHeader><CardContent>{complaints.length ? <div className="divide-y">{complaints.map((item) => <div key={item.id} className="flex items-start justify-between gap-4 py-3"><div className="min-w-0"><p className="truncate text-sm font-medium">{item.code} · {item.category ?? "Umum"}</p><p className="mt-1 line-clamp-2 text-xs text-muted-foreground">{item.description}</p></div><Badge variant={item.status === "resolved" ? "default" : "warning"}>{item.status}</Badge></div>)}</div> : <EmptyState icon={WarningCircleIcon} title="Tidak ada komplain" description="Komplain yang tercatat akan tampil di sini." />}</CardContent></Card> : null}
        {showCoaching ? <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><SmileyIcon className="text-primary" /> Coaching terbaru</CardTitle></CardHeader><CardContent>{coaching.length ? <div className="divide-y">{coaching.map((item) => <div key={item.id} className="py-3"><div className="flex justify-between gap-4"><p className="text-sm font-medium">{item.employee.name}</p><span className="text-xs text-muted-foreground">{formatDate(item.coachingDate)}</span></div><p className="mt-1 text-xs text-muted-foreground">{item.topic}</p></div>)}</div> : <EmptyState icon={SmileyIcon} title="Belum ada coaching" description="Riwayat coaching tim akan tampil di sini." />}</CardContent></Card> : null}
        {showStock ? <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><PackageIcon className="text-primary" /> Sparepart kritis</CardTitle></CardHeader><CardContent>{lowStock.length ? <div className="divide-y">{lowStock.map((item) => <div key={item.id} className="flex items-center justify-between gap-4 py-3"><div><p className="text-sm font-medium">{item.name}</p><p className="text-xs text-muted-foreground">{item.code} · minimum {item.minStockAlert}</p></div><Badge variant={item.stockQuantity <= item.minStockAlert ? "warning" : "secondary"}>{item.stockQuantity} unit</Badge></div>)}</div> : <EmptyState icon={PackageIcon} title="Tidak ada stok kritis" description="Sparepart yang ditandai kritis akan tampil di sini." />}</CardContent></Card> : null}
      </div>
    </div>
  );
}
