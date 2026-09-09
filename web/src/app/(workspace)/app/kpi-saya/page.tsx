import { ArrowRightIcon, ChartBarIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { EmptyState } from "@/components/empty-state";
import { PageHeading } from "@/components/page-heading";
import { StatusBadge } from "@/components/status-badge";
import { Card, CardContent } from "@/components/ui/card";
import { formatDate, formatNumber } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { canSeeOwnScore } from "@/modules/access/scope";

export const metadata: Metadata = { title: "KPI saya" };

export default async function MyKpiPage() {
  const user = await requireUser();
  if (!hasCapability(user, "kpi.self.view") || !user.employee) redirect("/app");
  const records = await prisma.employeeKpi.findMany({
    where: { employeeId: user.employee.id },
    orderBy: [{ period: { year: "desc" } }, { period: { month: "desc" } }],
    include: { period: true },
  });

  return (
    <div className="space-y-7">
      <PageHeading eyebrow="Kinerja pribadi" title="KPI saya" description="Pantau fakta pekerjaan dan tahap penilaian KPI Anda." />
      {records.length === 0 ? <EmptyState icon={ChartBarIcon} title="Belum ada penugasan KPI" description="Admin KPI belum menyiapkan penugasan untuk akun Anda." /> : (
        <div className="grid gap-4 xl:grid-cols-2">
          {records.map((record) => {
            const scoreVisible = canSeeOwnScore(record.period.status);
            return (
              <Link key={record.id} href={`/app/kpi-saya/${record.id}`} className="group rounded-2xl outline-none focus-visible:ring-2 focus-visible:ring-ring">
                <Card className="h-full transition-colors group-hover:border-primary/45">
                  <CardContent className="p-5">
                    <div className="flex items-start justify-between gap-3"><div><p className="text-base font-semibold">{record.period.name}</p><p className="mt-1 text-xs text-muted-foreground">{formatDate(record.period.startDate)} sampai {formatDate(record.period.endDate)}</p></div><StatusBadge status={record.status} /></div>
                    <div className="mt-5 flex items-end justify-between gap-4">
                      <div><p className="text-xs text-muted-foreground">Progres</p><p className="mt-1 text-xl font-semibold">{formatNumber(record.progressPercentage.toString())}%</p></div>
                      <div className="text-right"><p className="text-xs text-muted-foreground">Nilai akhir</p><p className="mt-1 text-xl font-semibold">{scoreVisible && record.finalScore ? formatNumber(record.finalScore.toString()) : "Terkunci"}</p></div>
                    </div>
                    <div className="mt-4 h-2 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${Math.min(100, Number(record.progressPercentage))}%` }} /></div>
                    <p className="mt-4 flex items-center justify-end gap-1 text-xs font-semibold text-primary">Buka rincian <ArrowRightIcon /></p>
                  </CardContent>
                </Card>
              </Link>
            );
          })}
        </div>
      )}
    </div>
  );
}
