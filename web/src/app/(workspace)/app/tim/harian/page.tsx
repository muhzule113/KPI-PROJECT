import { ArrowLeftIcon, CalendarCheckIcon, TargetIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { DailyAssessmentForm, DailyBulkApproveForm } from "@/components/kpi/daily-assessment-form";
import { EmptyState } from "@/components/empty-state";
import { PageHeading } from "@/components/page-heading";
import { StatusBadge } from "@/components/status-badge";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { formatNumber } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { canManageKpi, canReviewKpi, capabilitiesFor } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { ATTENDANCE_ITEM_CODES, fulfilledCriterionIds, manualRatingOptions, ratingCodeFromJson, rubricCriteria, SYSTEM_SOURCE_TYPES } from "@/modules/kpi/daily-values";

export const metadata: Metadata = { title: "Penilaian harian tim" };

export default async function DailyTeamPage({ searchParams }: { searchParams: Promise<{ date?: string; mode?: string }> }) {
  const user = await requireUser();
  const caps = capabilitiesFor(user);
  const canSupervisor = caps.has("kpi.supervisor.daily");
  const canManager = caps.has("kpi.manager.daily");
  if (!canSupervisor && !canManager) redirect("/app/tim");
  const query = await searchParams;
  const mode = query.mode === "manager" && canManager ? "manager" : query.mode === "supervisor" && canSupervisor ? "supervisor" : canSupervisor ? "supervisor" : "manager";
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Makassar", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
  const selectedDate = query.date && /^\d{4}-\d{2}-\d{2}$/.test(query.date) && query.date <= today ? query.date : today;
  const entryDate = new Date(`${selectedDate}T00:00:00.000Z`);
  const assignment = user.role === "super_admin" ? {} : mode === "manager"
    ? { managerIdSnapshot: user.employee?.id ?? "__no_access__" }
    : { supervisorIdSnapshot: user.employee?.id ?? "__no_access__" };
  const entries = await prisma.kpiDailyEntry.findMany({
    where: {
      entryDate,
      entryStatus: { in: ["submitted", "revision_required"] },
      ...(mode === "supervisor" ? { supervisorStatus: { in: ["PENDING", "REVISION_REQUIRED"] } } : {
        managerStatus: { in: ["PENDING", "REVISION_REQUIRED", "APPROVED"] },
        OR: [
          { item: { employeeKpi: { positionCodeSnapshot: "POS-SPV" } } },
          { supervisorStatus: "APPROVED", item: { employeeKpi: { positionCodeSnapshot: { not: "POS-SPV" } } } },
        ],
      }),
      item: { employeeKpi: { ...assignment, period: { status: { in: ["OPEN", "SUBMISSION_CLOSED", "IN_REVIEW", "WAITING_APPROVAL"] } } } },
    },
    orderBy: [{ item: { employeeKpi: { employeeNameSnapshot: "asc" } } }, { item: { nameSnapshot: "asc" } }],
    include: { item: { include: { employeeKpi: { include: { employee: { include: { position: true, branch: true } }, period: true } } } } },
  });
  const allowedEntries = entries.filter((entry) => mode === "manager" ? canManageKpi(user, entry.item.employeeKpi) : canReviewKpi(user, entry.item.employeeKpi));
  const grouped = new Map<string, typeof allowedEntries>();
  for (const entry of allowedEntries) grouped.set(entry.item.employeeKpiId, [...(grouped.get(entry.item.employeeKpiId) ?? []), entry]);

  return (
    <div className="space-y-7">
      <Button asChild variant="link"><Link href="/app/tim"><ArrowLeftIcon /> Kembali ke KPI tim</Link></Button>
      <PageHeading eyebrow="Tugas harian" title={mode === "manager" ? "Tinjauan harian Manager" : "Penilaian harian Supervisor"} description={`${allowedEntries.length} indikator pada ${grouped.size} karyawan tersedia untuk ${selectedDate}.`} action={<form method="get" className="flex flex-wrap items-end justify-end gap-2"><div><label htmlFor="date" className="sr-only">Tanggal penilaian</label><Input id="date" name="date" type="date" max={today} defaultValue={selectedDate} /></div>{canSupervisor && canManager ? <div><label htmlFor="mode" className="sr-only">Peran penilaian</label><select id="mode" name="mode" defaultValue={mode} className="flex h-11 rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="supervisor">Supervisor</option><option value="manager">Manager</option></select></div> : <input type="hidden" name="mode" value={mode} />}<Button type="submit" variant="outline">Tampilkan</Button></form>} />
      {grouped.size === 0 ? <EmptyState icon={CalendarCheckIcon} title="Tidak ada tugas pada tanggal ini" description="Tugas dibuat dari snapshot periode dan hanya muncul sesuai cadence indikator serta assignment penilai." /> : (
        <div className="space-y-5">
          {[...grouped.entries()].map(([kpiId, rows]) => {
            const kpi = rows[0].item.employeeKpi;
            const canApproveAll = mode === "manager" && kpi.positionCodeSnapshot !== "POS-SPV" && rows.some((entry) => entry.managerStatus === "PENDING" && entry.supervisorStatus === "APPROVED");
            return <Card key={kpiId}>
              <CardHeader><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><CardTitle className="text-base">{kpi.employeeNameSnapshot}</CardTitle><p className="mt-1 text-xs text-muted-foreground">{kpi.employee.position.name} · {kpi.employee.branch.name} · {kpi.period.name}</p></div><div className="flex flex-wrap gap-2">{canApproveAll ? <DailyBulkApproveForm employeeKpiId={kpi.id} entryDate={selectedDate} /> : null}<Button asChild size="sm" variant="outline"><Link href={`/app/tim/${kpi.id}`}>Lihat rekap</Link></Button></div></div></CardHeader>
              <CardContent className="space-y-4">
                {rows.map((entry) => {
                  const item = entry.item;
                  const ratings = manualRatingOptions(item.rubricSnapshot);
                  const criteria = rubricCriteria(item.rubricSnapshot);
                  const system = SYSTEM_SOURCE_TYPES.has(item.sourceTypeSnapshot.toLowerCase()) || ATTENDANCE_ITEM_CODES.has(item.definitionCodeSnapshot);
                  const kind = system ? "system" : ratings.length ? "rating" : item.formulaKeySnapshot === "rubric" ? "rubric" : "numeric";
                  const chosenJson = mode === "manager" ? entry.managerActualJson ?? entry.supervisorActualJson : entry.supervisorActualJson;
                  const chosenAnswers = mode === "manager" ? entry.managerAnswersJson ?? entry.supervisorAnswersJson : entry.supervisorAnswersJson;
                  const chosenActual = mode === "manager" ? entry.managerActualDecimal ?? entry.supervisorActualDecimal ?? entry.employeeActualDecimal : entry.supervisorActualDecimal ?? entry.employeeActualDecimal;
                  const sourceActual = entry.systemActualDecimal ?? item.actualDecimal;
                  const status = mode === "manager" ? entry.managerStatus : entry.supervisorStatus;
                  return <section key={entry.id} className="rounded-xl border p-4 sm:p-5">
                    <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><div className="flex flex-wrap items-center gap-2"><p className="font-semibold">{item.definitionCodeSnapshot} · {item.nameSnapshot}</p><Badge variant="secondary">{item.sourceTypeSnapshot}</Badge></div><p className="mt-1 flex items-center gap-1 text-xs text-muted-foreground"><TargetIcon /> Target {item.targetValueSnapshot ? `${formatNumber(item.targetValueSnapshot.toString())} ${item.targetUnitSnapshot}` : "sesuai rubrik"}{system ? ` · Aktual sumber ${sourceActual !== null ? formatNumber(sourceActual.toString()) : "belum tersedia"}` : ""}</p></div><StatusBadge status={status} /></div>
                    <DailyAssessmentForm entryId={entry.id} rowVersion={entry.rowVersion} role={mode} kind={kind} ratings={ratings} criteria={criteria} defaultActual={chosenActual?.toString()} defaultRating={ratingCodeFromJson(chosenJson)} fulfilled={fulfilledCriterionIds(chosenAnswers)} />
                  </section>;
                })}
              </CardContent>
            </Card>;
          })}
        </div>
      )}
    </div>
  );
}
