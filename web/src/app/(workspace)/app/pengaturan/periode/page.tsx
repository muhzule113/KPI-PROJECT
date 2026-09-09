import { ArrowLeftIcon, CalendarDotsIcon, PlusIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { PageHeading } from "@/components/page-heading";
import { PeriodActions, PeriodForm } from "@/components/settings/period-forms";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatDate } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";

export const metadata: Metadata = { title: "Periode KPI" };
const inputDate = (date: Date) => date.toISOString().slice(0, 10);
const inputDateTime = (date: Date) => new Date(date.getTime() + 8 * 60 * 60 * 1000).toISOString().slice(0, 16);

export default async function PeriodSettingsPage() {
  const user = await requireUser();
  if (!hasCapability(user, "kpi.period.manage")) redirect("/app");
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Makassar", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());
  const [branches, periods] = await Promise.all([
    prisma.branch.findMany({ where: { isActive: true }, orderBy: { name: "asc" }, select: { id: true, name: true } }),
    prisma.kpiPeriod.findMany({ orderBy: [{ year: "desc" }, { month: "desc" }], include: { branches: { include: { branch: true } }, _count: { select: { employeeKpis: true } } } }),
  ]);
  return (
    <div className="space-y-7">
      <Button asChild variant="link"><Link href="/app/pengaturan"><ArrowLeftIcon /> Kembali ke pengaturan</Link></Button>
      <PageHeading eyebrow="Administrasi KPI" title="Periode penilaian" description="Atur cakupan, validasi kesiapan, buat snapshot, lalu jalankan publikasi dan penguncian secara berurutan." />
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><PlusIcon className="text-primary" /> Periode baru</CardTitle></CardHeader><CardContent><PeriodForm branches={branches} /></CardContent></Card>
      <section className="space-y-4">
        {periods.map((period) => <Card key={period.id}><CardHeader><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><CardTitle className="flex items-center gap-2 text-base"><CalendarDotsIcon className="text-primary" />{period.name}</CardTitle><p className="mt-1 text-xs text-muted-foreground">{formatDate(period.startDate)} sampai {formatDate(period.endDate)} · {period._count.employeeKpis} snapshot · {period.branches.map((row) => row.branch.name).join(", ")}</p></div><StatusBadge status={period.status} /></div></CardHeader><CardContent>
          <dl className="grid gap-3 text-sm sm:grid-cols-3"><div><dt className="text-xs text-muted-foreground">Batas input</dt><dd className="font-medium">{formatDate(period.submissionDeadline)}</dd></div><div><dt className="text-xs text-muted-foreground">Batas review</dt><dd className="font-medium">{formatDate(period.reviewDeadline)}</dd></div><div><dt className="text-xs text-muted-foreground">Batas approval</dt><dd className="font-medium">{formatDate(period.approvalDeadline)}</dd></div></dl>
          {period.status === "DRAFT" ? <details className="mt-4 rounded-xl border p-4"><summary className="cursor-pointer text-sm font-semibold">Ubah periode Draft</summary><div className="mt-4"><PeriodForm branches={branches} period={{ id: period.id, name: period.name, year: period.year, month: period.month, startDate: inputDate(period.startDate), endDate: inputDate(period.endDate), submissionDeadline: inputDateTime(period.submissionDeadline), reviewDeadline: inputDateTime(period.reviewDeadline), approvalDeadline: inputDateTime(period.approvalDeadline), branchIds: period.branches.map((row) => row.branchId) }} /></div></details> : null}
          <PeriodActions periodId={period.id} status={period.status} startDate={inputDate(period.startDate)} endDate={inputDate(period.endDate)} today={today} />
        </CardContent></Card>)}
      </section>
    </div>
  );
}
