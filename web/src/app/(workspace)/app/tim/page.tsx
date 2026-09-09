import { ArrowRightIcon, UsersThreeIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { EmptyState } from "@/components/empty-state";
import { PageHeading } from "@/components/page-heading";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { formatDate, formatNumber } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { kpiScopeFor } from "@/modules/access/scope";

export const metadata: Metadata = { title: "KPI tim" };

export default async function TeamKpiPage() {
  const user = await requireUser();
  const permitted = ["kpi.supervisor.review", "kpi.manager.approval", "kpi.monitor"].some((capability) => hasCapability(user, capability));
  if (!permitted) redirect("/app");
  const records = await prisma.employeeKpi.findMany({
    where: kpiScopeFor(user),
    orderBy: [{ period: { year: "desc" } }, { period: { month: "desc" } }, { updatedAt: "desc" }],
    take: 100,
    include: { period: true, branch: true, position: true },
  });
  const pending = records.filter((record) => ["SUBMITTED", "UNDER_REVIEW", "PENDING_APPROVAL", "REVISION_REQUIRED"].includes(record.status)).length;

  return (
    <div className="space-y-7">
      <PageHeading eyebrow="Review dan persetujuan" title="KPI tim" description={`${pending} dari ${records.length} KPI memerlukan perhatian dalam cakupan Anda.`} action={<Button asChild><Link href="/app/tim/harian">Penilaian harian</Link></Button>} />
      {records.length === 0 ? <EmptyState icon={UsersThreeIcon} title="Antrean KPI kosong" description="Belum ada KPI tim yang masuk ke cakupan review atau persetujuan Anda." /> : (
        <div className="space-y-3">
          {records.map((record) => (
            <Link key={record.id} href={`/app/tim/${record.id}`} className="group block rounded-2xl outline-none focus-visible:ring-2 focus-visible:ring-ring">
              <Card className="transition-colors group-hover:border-primary/45"><CardContent className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:p-5">
                <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-accent text-sm font-bold text-primary">{record.positionCodeSnapshot.replace("POS-", "")}</span>
                <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2"><p className="truncate font-semibold">{record.employeeNameSnapshot}</p><StatusBadge status={record.status} /></div><p className="mt-1 truncate text-xs text-muted-foreground">{record.position.name} · {record.branch.name} · {record.period.name}</p></div>
                <div className="grid grid-cols-2 gap-5 sm:flex sm:items-center">
                  <div><p className="text-xs text-muted-foreground">Progres</p><p className="mt-1 text-sm font-semibold">{formatNumber(record.progressPercentage.toString())}%</p></div>
                  <div><p className="text-xs text-muted-foreground">Nilai</p><p className="mt-1 text-sm font-semibold">{record.finalScore ? formatNumber(record.finalScore.toString()) : "Belum ada"}</p></div>
                  <div className="hidden text-right text-xs text-muted-foreground lg:block">Diperbarui<br />{formatDate(record.updatedAt)}</div>
                  <ArrowRightIcon className="hidden text-primary sm:block" />
                </div>
              </CardContent></Card>
            </Link>
          ))}
        </div>
      )}
    </div>
  );
}
