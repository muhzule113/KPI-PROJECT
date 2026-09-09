import { ArrowLeftIcon, FileTextIcon, TargetIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { notFound, redirect } from "next/navigation";
import { StatusBadge } from "@/components/status-badge";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatDate, formatNumber } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { canSeeOwnScore } from "@/modules/access/scope";

export const metadata: Metadata = { title: "Rincian KPI" };

export default async function MyKpiDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const user = await requireUser();
  if (!hasCapability(user, "kpi.self.view") || !user.employee) redirect("/app");
  const { id } = await params;
  const kpi = await prisma.employeeKpi.findFirst({
    where: { id, employeeId: user.employee.id },
    include: { period: true, branch: true, position: true, items: { orderBy: { createdAt: "asc" }, include: { evidences: true } } },
  });
  if (!kpi) notFound();
  const scoreVisible = canSeeOwnScore(kpi.period.status);

  return (
    <div className="space-y-6">
      <Button asChild variant="link"><Link href="/app/kpi-saya"><ArrowLeftIcon /> Kembali ke KPI saya</Link></Button>
      <section className="rounded-2xl border bg-card p-5 sm:p-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div><p className="text-xs font-semibold uppercase tracking-[0.16em] text-primary">{kpi.period.name}</p><h1 className="mt-1 text-2xl font-semibold tracking-tight">{kpi.employeeNameSnapshot}</h1><p className="mt-1 text-sm text-muted-foreground">{kpi.position.name} · {kpi.branch.name}</p></div>
          <StatusBadge status={kpi.status} />
        </div>
        <div className="mt-6 grid gap-4 sm:grid-cols-3">
          <div><p className="text-xs text-muted-foreground">Progres pengisian</p><p className="mt-1 text-xl font-semibold">{formatNumber(kpi.progressPercentage.toString())}%</p></div>
          <div><p className="text-xs text-muted-foreground">Nilai akhir</p><p className="mt-1 text-xl font-semibold">{scoreVisible && kpi.finalScore ? formatNumber(kpi.finalScore.toString()) : "Terkunci"}</p></div>
          <div><p className="text-xs text-muted-foreground">Batas pengajuan</p><p className="mt-1 text-sm font-semibold">{formatDate(kpi.period.submissionDeadline)}</p></div>
        </div>
      </section>

      <div className="grid gap-4 lg:grid-cols-2">
        {kpi.items.map((item, index) => (
          <Card key={item.id}>
            <CardHeader>
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0"><p className="text-xs font-semibold text-primary">Indikator {index + 1}</p><CardTitle className="mt-1 text-base leading-6">{item.nameSnapshot}</CardTitle></div>
                <Badge variant="secondary">Bobot {formatNumber(item.weightSnapshot.toString())}%</Badge>
              </div>
            </CardHeader>
            <CardContent>
              <dl className="grid grid-cols-2 gap-4 text-sm">
                <div><dt className="flex items-center gap-1 text-xs text-muted-foreground"><TargetIcon /> Target</dt><dd className="mt-1 font-semibold">{item.targetValueSnapshot ? formatNumber(item.targetValueSnapshot.toString()) : "Sesuai rubrik"} {item.targetUnitSnapshot}</dd></div>
                <div><dt className="text-xs text-muted-foreground">Aktual</dt><dd className="mt-1 font-semibold">{item.actualDecimal ? formatNumber(item.actualDecimal.toString()) : "Belum diisi"} {item.actualDecimal ? item.targetUnitSnapshot : ""}</dd></div>
                <div><dt className="text-xs text-muted-foreground">Perhitungan</dt><dd className="mt-1"><StatusBadge status={item.calculationStatus} /></dd></div>
                <div><dt className="text-xs text-muted-foreground">Nilai terbobot</dt><dd className="mt-1 font-semibold">{scoreVisible && item.weightedScore ? formatNumber(item.weightedScore.toString()) : "Terkunci"}</dd></div>
              </dl>
              {item.calculationNote ? <p className="mt-4 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">{item.calculationNote}</p> : null}
              {item.evidenceRequiredSnapshot ? <p className="mt-4 flex items-center gap-2 text-xs text-muted-foreground"><FileTextIcon /> Bukti wajib: {item.evidences.some((evidence) => evidence.scanStatus === "clean") ? "sudah siap" : "belum siap"}</p> : null}
              <p className="mt-4 text-xs text-muted-foreground">Sumber: {item.sourceTypeSnapshot.replaceAll("_", " ")}. Nilai diperbarui dari aktivitas operasional atau penilai yang ditugaskan.</p>
            </CardContent>
          </Card>
        ))}
      </div>
    </div>
  );
}
