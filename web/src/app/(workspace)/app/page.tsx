import {
  ArrowRightIcon,
  ChartLineUpIcon,
  ClipboardTextIcon,
  GaugeIcon,
  WarningCircleIcon,
  WrenchIcon,
} from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { EmptyState } from "@/components/empty-state";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatDate, formatNumber } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { canSeeScore, kpiScopeFor, ticketScopeFor } from "@/modules/access/scope";

export const metadata: Metadata = { title: "Ringkasan" };

function MetricCard({ icon: Icon, label, value, note }: { icon: typeof GaugeIcon; label: string; value: string; note: string }) {
  return (
    <Card className="min-w-0">
      <CardContent className="p-5">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0"><p className="text-sm text-muted-foreground">{label}</p><p className="mt-2 break-words text-xl font-semibold tracking-tight sm:text-2xl">{value}</p></div>
          <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-accent text-primary"><Icon aria-hidden="true" size={21} weight="duotone" /></span>
        </div>
        <p className="mt-3 text-xs text-muted-foreground">{note}</p>
      </CardContent>
    </Card>
  );
}

export default async function DashboardPage() {
  const user = await requireUser();
  const period = await prisma.kpiPeriod.findFirst({
    where: { status: { not: "CANCELLED" } },
    orderBy: [{ year: "desc" }, { month: "desc" }],
  });
  const kpiScope = kpiScopeFor(user);
  const ticketScope = ticketScopeFor(user);
  const hasTickets = hasCapability(user, "tickets.view");
  const actionStatuses = user.role === "owner_manager"
    ? ["PENDING_APPROVAL"] as const
    : user.role === "supervisor"
      ? ["SUBMITTED", "UNDER_REVIEW"] as const
      : user.role === "employee"
        ? ["DRAFT", "REVISION_REQUIRED"] as const
        : ["DRAFT", "REVISION_REQUIRED", "PENDING_APPROVAL"] as const;

  const [kpiCount, attentionCount, score, recentKpis, ticketCount] = period
    ? await Promise.all([
        prisma.employeeKpi.count({ where: { periodId: period.id, ...kpiScope } }),
        prisma.employeeKpi.count({ where: { periodId: period.id, ...kpiScope, status: { in: [...actionStatuses] } } }),
        prisma.employeeKpi.aggregate({ where: { periodId: period.id, ...kpiScope, finalScore: { not: null } }, _avg: { finalScore: true } }),
        prisma.employeeKpi.findMany({
          where: { periodId: period.id, ...kpiScope },
          orderBy: { updatedAt: "desc" },
          take: 5,
          select: { id: true, employeeNameSnapshot: true, positionCodeSnapshot: true, progressPercentage: true, finalScore: true, ratingLabel: true, status: true, updatedAt: true },
        }),
        hasTickets
          ? prisma.serviceTicket.count({ where: { ...ticketScope, status: { notIn: ["DELIVERED", "CANCELLED", "CANCELLED_UNREPAIRABLE"] } } })
          : Promise.resolve(0),
      ])
    : [0, 0, { _avg: { finalScore: null } }, [], 0] as const;

  const scoreVisible = period ? canSeeScore(user, period.status) : false;
  const kpiTarget = user.role === "employee" ? "/app/kpi-saya" : "/app/tim";

  return (
    <div className="space-y-7">
      <section className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <p className="text-sm font-medium text-primary">Halo, {user.name.split(" ")[0]}</p>
          <h1 className="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Ringkasan pekerjaan</h1>
          <p className="mt-1 text-sm text-muted-foreground">{period ? `${period.name} · ${formatDate(period.startDate)} sampai ${formatDate(period.endDate)}` : "Belum ada periode KPI aktif."}</p>
        </div>
        {period ? <StatusBadge status={period.status} /> : null}
      </section>

      <section aria-label="Statistik utama" className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <MetricCard icon={ClipboardTextIcon} label="KPI dalam cakupan" value={String(kpiCount)} note={period?.name ?? "Periode belum dibuat"} />
        <MetricCard icon={ChartLineUpIcon} label="Rata-rata nilai" value={scoreVisible && score._avg.finalScore ? formatNumber(score._avg.finalScore.toString()) : "Belum tersedia"} note={scoreVisible ? "Dari KPI yang sudah dihitung" : "Terlihat setelah hasil diterbitkan"} />
        <MetricCard icon={WarningCircleIcon} label="Perlu tindakan" value={String(attentionCount)} note="Berdasarkan tahap alur Anda" />
        <MetricCard icon={WrenchIcon} label="Servis aktif" value={hasTickets ? String(ticketCount) : "Tidak tersedia"} note={hasTickets ? "Belum diserahkan atau dibatalkan" : "Sesuai akses jabatan"} />
      </section>

      <Card>
        <CardHeader className="flex-row items-center justify-between gap-4">
          <div><CardTitle>KPI terbaru</CardTitle><p className="mt-1 text-sm text-muted-foreground">Perubahan terakhir dalam cakupan Anda.</p></div>
          {period && recentKpis.length ? <Button asChild variant="outline" size="sm"><Link href={kpiTarget}>Lihat semua <ArrowRightIcon /></Link></Button> : null}
        </CardHeader>
        <CardContent>
          {!period || recentKpis.length === 0 ? (
            <EmptyState icon={GaugeIcon} title="Belum ada KPI" description="KPI akan tampil setelah periode dan penugasan disiapkan oleh Admin KPI." />
          ) : (
            <div className="divide-y">
              {recentKpis.map((kpi) => (
                <Link key={kpi.id} href={`${kpiTarget}/${kpi.id}`} className="flex min-h-20 items-center gap-4 py-3 outline-none hover:bg-muted/45 focus-visible:ring-2 focus-visible:ring-ring sm:px-2">
                  <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-muted text-sm font-bold text-muted-foreground">{kpi.positionCodeSnapshot.replace("POS-", "")}</span>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-semibold">{kpi.employeeNameSnapshot}</p>
                    <div className="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-muted-foreground">
                      <span>{formatNumber(kpi.progressPercentage.toString())}% lengkap</span>
                      <span>Diperbarui {formatDate(kpi.updatedAt)}</span>
                    </div>
                  </div>
                  <div className="hidden text-right sm:block">
                    <p className="text-sm font-semibold">{scoreVisible && kpi.finalScore ? formatNumber(kpi.finalScore.toString()) : "Nilai terkunci"}</p>
                    <p className="text-xs text-muted-foreground">{scoreVisible ? kpi.ratingLabel ?? "Belum dinilai" : "Menunggu publikasi"}</p>
                  </div>
                  <StatusBadge status={kpi.status} />
                </Link>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
