import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { currentUser } from "@/modules/access/current-user";
import { kpiScopeFor } from "@/modules/access/scope";
import { canStartKpiExport, createCsv, createPdf, createXlsx, type ReportTable } from "@/modules/reports/kpi-export";
import { rankKpisByPosition } from "@/modules/reports/ranking";
import { parseTypedReportFilename, type TypedReportType } from "@/modules/reports/typed-report";

const legacyFormats = { "kpi.csv": "csv", "kpi.xlsx": "xlsx", "kpi.pdf": "pdf" } as const;
const contentTypes = {
  csv: "text/csv; charset=utf-8",
  xlsx: "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
  pdf: "application/pdf",
} as const;
const date = (value: Date | null) => value?.toISOString() ?? "";
const json = (value: unknown) => value == null ? "" : JSON.stringify(value);

export async function GET(request: Request, { params }: { params: Promise<{ filename: string }> }) {
  const user = await currentUser();
  if (!user) return new Response("Tidak terautentikasi", { status: 401 });
  if (!hasCapability(user, "reports.export")) return new Response("Tidak diizinkan", { status: 403 });
  const { filename } = await params;
  const typed = parseTypedReportFilename(filename);
  const legacyFormat = legacyFormats[filename as keyof typeof legacyFormats];
  if (!typed && !legacyFormat) return new Response("Format tidak didukung", { status: 404 });
  const format = typed?.format ?? legacyFormat;
  const reportType: TypedReportType = typed?.type ?? "period_summary";
  const search = new URL(request.url).searchParams;
  const periodId = search.get("period")?.slice(0, 100);
  const branchId = search.get("branch")?.slice(0, 100) || undefined;
  const positionId = search.get("position")?.slice(0, 100) || undefined;
  const requestedKpiId = search.get("kpi")?.slice(0, 100) || undefined;
  if (!periodId) return new Response("Periode wajib dipilih", { status: 400 });
  const period = await prisma.kpiPeriod.findUnique({ where: { id: periodId } });
  if (!period) return new Response("Periode tidak ditemukan", { status: 404 });

  const records = await prisma.employeeKpi.findMany({
    where: { periodId, ...kpiScopeFor(user), branchIdSnapshot: branchId, positionIdSnapshot: positionId },
    orderBy: [{ branch: { name: "asc" } }, { employeeNameSnapshot: "asc" }],
    include: {
      branch: true,
      position: true,
      items: { orderBy: { definitionCodeSnapshot: "asc" } },
      corrections: { select: { status: true } },
    },
  });
  const baseRows = records.map((record) => [record.id, period.name, record.employeeNumberSnapshot, record.employeeNameSnapshot, record.position.name, record.branch.name, record.status, record.progressPercentage.toString(), record.finalScore?.toString() ?? "", record.ratingLabel ?? "", date(record.submittedAt), date(record.approvedAt), date(record.lockedAt)]);
  let title = `Rekap KPI - ${period.name}`;
  let table: ReportTable;

  if (reportType === "period_summary") {
    table = { headings: ["KPI ID", "Periode", "NIK", "Nama", "Jabatan", "Cabang", "Status", "Progres (%)", "Nilai Akhir", "Predikat", "Diajukan", "Disetujui", "Dikunci"], rows: baseRows };
  } else if (reportType === "position_summary") {
    const groups = new Map<string, typeof records>();
    for (const record of records) groups.set(record.position.name, [...(groups.get(record.position.name) ?? []), record]);
    table = { headings: ["Jabatan", "Jumlah KPI", "Rata-rata Skor"], rows: [...groups].map(([position, rows]) => {
      const scores = rows.flatMap((row) => row.finalScore == null ? [] : [Number(row.finalScore)]);
      return [position, rows.length, scores.length ? (scores.reduce((sum, score) => sum + score, 0) / scores.length).toFixed(2) : ""];
    }) };
    title = `Rekap KPI per Jabatan - ${period.name}`;
  } else if (reportType === "individual") {
    if (!requestedKpiId) return new Response("KPI karyawan wajib dipilih", { status: 400 });
    const record = records.find((item) => item.id === requestedKpiId);
    if (!record) return new Response("KPI tidak ditemukan dalam cakupan akses", { status: 404 });
    table = { headings: ["Kode", "Indikator", "Bobot", "Target", "Unit", "Aktual", "Achievement", "Skor Berbobot", "Sumber"], rows: record.items.map((item) => [item.definitionCodeSnapshot, item.nameSnapshot, item.weightSnapshot.toString(), item.targetValueSnapshot?.toString() ?? json(item.targetJsonSnapshot), item.targetUnitSnapshot, item.actualDecimal?.toString() ?? json(item.actualJson), item.achievementPercentage?.toString() ?? "", item.weightedScore?.toString() ?? "", item.sourceTypeSnapshot]) };
    title = `Detail KPI ${record.employeeNameSnapshot} - ${period.name}`;
  } else if (reportType === "target_actual" || reportType === "below_target") {
    const rows = records.flatMap((record) => record.items
      .filter((item) => reportType !== "below_target" || item.achievementPercentage != null && Number(item.achievementPercentage) < 100)
      .map((item) => [record.employeeNumberSnapshot, record.employeeNameSnapshot, record.position.name, item.definitionCodeSnapshot, item.nameSnapshot, item.targetValueSnapshot?.toString() ?? json(item.targetJsonSnapshot), item.targetUnitSnapshot, item.actualDecimal?.toString() ?? json(item.actualJson), item.achievementPercentage?.toString() ?? ""]));
    table = { headings: ["NIK", "Nama", "Jabatan", "Kode", "Indikator", "Target", "Unit", "Aktual", "Achievement"], rows };
    title = `${reportType === "below_target" ? "KPI di Bawah Target" : "Target vs Aktual KPI"} - ${period.name}`;
  } else if (reportType === "ranking") {
    const groups = rankKpisByPosition(records.map((record) => ({ id: record.id, employeeNumber: record.employeeNumberSnapshot, employeeName: record.employeeNameSnapshot, positionId: record.position.id, positionCode: record.positionCodeSnapshot, positionName: record.position.name, branchName: record.branch.name, status: record.status, eligibility: record.eligibility, finalScore: record.finalScore == null ? null : Number(record.finalScore), items: record.items.map((item) => ({ weight: Number(item.weightSnapshot), achievement: item.achievementPercentage == null ? null : Number(item.achievementPercentage) })), corrections: record.corrections })));
    table = { headings: ["Peringkat", "NIK", "Nama", "Jabatan", "Cabang", "Skor Final", "Tie-break", "Koreksi Berjalan"], rows: [...groups.values()].flatMap((rows) => rows.map((row) => [row.rank, row.employeeNumber, row.employeeName, row.positionName, row.branchName, row.finalScore, row.tieBreakAchievement, row.correctionInProgress ? "Ya" : "Tidak"])) };
    title = `Ranking KPI - ${period.name}`;
  } else if (reportType === "workflow_completeness") {
    table = { headings: ["KPI ID", "NIK", "Nama", "Status", "Progres", "Diajukan", "Disetujui", "Dikunci"], rows: records.map((record) => [record.id, record.employeeNumberSnapshot, record.employeeNameSnapshot, record.status, record.progressPercentage.toString(), date(record.submittedAt), date(record.approvedAt), date(record.lockedAt)]) };
    title = `Kelengkapan Penilaian - ${period.name}`;
  } else if (reportType === "change_history") {
    const kpiIds = records.map((record) => record.id);
    const itemIds = records.flatMap((record) => record.items.map((item) => item.id));
    const events = records.length ? await prisma.auditEvent.findMany({ where: { OR: [{ subjectType: "EmployeeKpi", subjectId: { in: kpiIds } }, { subjectType: "EmployeeKpiItem", subjectId: { in: itemIds } }] }, orderBy: { occurredAt: "asc" }, include: { actor: { select: { name: true } } } }) : [];
    table = { headings: ["Waktu", "Aksi", "Objek", "ID", "Pelaku", "Sebelum", "Sesudah", "Alasan"], rows: events.map((event) => [date(event.occurredAt), event.action, event.subjectType, event.subjectId, event.actor?.name ?? "Sistem", json(event.beforeJson), json(event.afterJson), event.reason ?? ""]) };
    title = `Histori Perubahan KPI - ${period.name}`;
  } else if (reportType === "import_reconciliation") {
    const branchIds = [...new Set(records.map((record) => record.branchIdSnapshot))];
    const batches = branchIds.length ? await prisma.importBatch.findMany({ where: { periodId, branchId: { in: branchIds } }, orderBy: { createdAt: "asc" }, include: { branch: true } }) : [];
    table = { headings: ["Batch", "File", "Cabang", "Mata Uang", "Status", "Total", "Valid", "Peringatan", "Error", "Duplikat"], rows: batches.map((batch) => [batch.id, batch.fileName, batch.branch.name, batch.currency, batch.status, batch.totalRows, batch.validRows, batch.warningRows, batch.errorRows, batch.duplicateRows]) };
    title = `Rekonsiliasi Impor - ${period.name}`;
  } else {
    const employeeIds = [...new Set(records.map((record) => record.employeeId))];
    const coaching = employeeIds.length ? await prisma.coachingLog.findMany({ where: { periodId, employeeId: { in: employeeIds } }, orderBy: { coachingDate: "asc" }, include: { supervisor: true, employee: true } }) : [];
    table = { headings: ["Tanggal", "Supervisor", "Karyawan", "Topik", "Catatan", "Target Tercapai", "Tindak Lanjut"], rows: coaching.map((log) => [log.coachingDate.toISOString().slice(0, 10), log.supervisor.name, log.employee.name, log.topic, log.notes ?? "", log.targetMet == null ? "" : log.targetMet ? "Ya" : "Tidak", log.followUpDate?.toISOString().slice(0, 10) ?? ""]) };
    title = `Coaching dan Action Plan - ${period.name}`;
  }

  const allowed = await prisma.$transaction(async (tx) => {
    await tx.$queryRaw`SELECT id FROM users WHERE id = ${user.id} FOR UPDATE`;
    const recentExports = await tx.auditEvent.count({ where: { actorId: user.id, action: "export_kpi_report", occurredAt: { gte: new Date(Date.now() - 60_000) } } });
    if (!canStartKpiExport(recentExports)) return false;
    await tx.auditEvent.create({ data: { actorId: user.id, action: "export_kpi_report", subjectType: "KpiPeriod", subjectId: period.id, afterJson: { reportType, format, branchId, positionId, kpiId: requestedKpiId }, ipAddress: request.headers.get("x-forwarded-for")?.split(",")[0]?.trim(), userAgent: request.headers.get("user-agent") } });
    return true;
  });
  if (!allowed) return new Response("Terlalu banyak permintaan ekspor. Coba lagi dalam satu menit.", { status: 429, headers: { "Retry-After": "60", "Cache-Control": "no-store" } });
  const body = format === "csv" ? createCsv(table) : format === "xlsx" ? createXlsx(table) : await createPdf(title, user.name, table);
  const downloadName = `${reportType}-${period.year}-${String(period.month).padStart(2, "0")}.${format}`;
  return new Response(typeof body === "string" ? body : Uint8Array.from(body).buffer, { headers: { "Content-Type": contentTypes[format], "Content-Disposition": `attachment; filename="${downloadName}"`, "Cache-Control": "no-store, private", "X-Content-Type-Options": "nosniff" } });
}
