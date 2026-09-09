import { ArrowLeftIcon, ClipboardTextIcon, TargetIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { notFound } from "next/navigation";
import { ApprovalForm, FinishReviewForm, ReviewItemForm, RubricForm } from "@/components/kpi/team-action-forms";
import { StatusBadge } from "@/components/status-badge";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { Prisma } from "@/generated/prisma/client";
import { formatDate, formatNumber } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { canManageKpi, canReviewKpi, hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";

export const metadata: Metadata = { title: "Review KPI" };

function rubricCriteria(value: Prisma.JsonValue | null) {
  if (!value || Array.isArray(value) || typeof value !== "object" || !Array.isArray(value.criteria)) return [];
  return value.criteria.flatMap((criterion) => {
    if (!criterion || Array.isArray(criterion) || typeof criterion !== "object") return [];
    const id = criterion.id;
    const text = criterion.criterion_text;
    const points = Number(criterion.points);
    return (typeof id === "string" || typeof id === "number") && typeof text === "string" && Number.isFinite(points) ? [{ id: String(id), text, points }] : [];
  });
}

export default async function TeamKpiDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const user = await requireUser();
  const { id } = await params;
  const kpi = await prisma.employeeKpi.findUnique({
    where: { id },
    include: {
      period: true,
      branch: true,
      position: true,
      items: { orderBy: { createdAt: "asc" }, include: { evidences: true, assessments: { orderBy: { createdAt: "desc" }, take: 1, include: { answers: true } } } },
      reviews: { orderBy: { updatedAt: "desc" }, take: 1, include: { reviewer: { select: { name: true } } } },
      approvals: { orderBy: { createdAt: "desc" }, take: 3, include: { approver: { select: { name: true } } } },
    },
  });
  if (!kpi) notFound();
  const reviewer = canReviewKpi(user, kpi);
  const manager = canManageKpi(user, kpi);
  const monitor = hasCapability(user, "kpi.monitor");
  if (!reviewer && !manager && !monitor) notFound();
  const managerDirect = manager && kpi.positionCodeSnapshot === "POS-SPV";
  const canAssessItems = (reviewer && ["SUBMITTED", "UNDER_REVIEW", "REVISION_REQUIRED"].includes(kpi.status)) || (managerDirect && ["SUBMITTED", "UNDER_REVIEW", "REVISION_REQUIRED", "VERIFIED", "PENDING_APPROVAL"].includes(kpi.status));
  const canFinishReview = reviewer && ["SUBMITTED", "UNDER_REVIEW", "REVISION_REQUIRED", "VERIFIED"].includes(kpi.status);
  const canDecide = manager && (kpi.status === "PENDING_APPROVAL" || (managerDirect && ["SUBMITTED", "UNDER_REVIEW", "REVISION_REQUIRED", "VERIFIED"].includes(kpi.status)));

  return (
    <div className="space-y-6">
      <Button asChild variant="link"><Link href="/app/tim"><ArrowLeftIcon /> Kembali ke KPI tim</Link></Button>
      <section className="rounded-2xl border bg-card p-5 sm:p-6">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
          <div><p className="text-xs font-semibold uppercase tracking-[0.16em] text-primary">{kpi.period.name}</p><h1 className="mt-1 text-2xl font-semibold tracking-tight">{kpi.employeeNameSnapshot}</h1><p className="mt-1 text-sm text-muted-foreground">{kpi.position.name} · {kpi.branch.name}</p></div><StatusBadge status={kpi.status} />
        </div>
        <div className="mt-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
          <div><p className="text-xs text-muted-foreground">Progres</p><p className="mt-1 text-xl font-semibold">{formatNumber(kpi.progressPercentage.toString())}%</p></div>
          <div><p className="text-xs text-muted-foreground">Nilai</p><p className="mt-1 text-xl font-semibold">{kpi.finalScore ? formatNumber(kpi.finalScore.toString()) : "Belum ada"}</p></div>
          <div><p className="text-xs text-muted-foreground">Predikat</p><p className="mt-1 text-sm font-semibold">{kpi.ratingLabel ?? "Belum ada"}</p></div>
          <div><p className="text-xs text-muted-foreground">Revisi</p><p className="mt-1 text-sm font-semibold">{kpi.revisionNumber} kali</p></div>
        </div>
      </section>

      <div className="grid gap-4 xl:grid-cols-2">
        {kpi.items.map((item, index) => {
          const criteria = rubricCriteria(item.rubricSnapshot);
          const fulfilled = item.assessments[0]?.answers.filter((answer) => answer.isFulfilled).map((answer) => String(answer.criterionId)) ?? [];
          return (
            <Card key={item.id}>
              <CardHeader><div className="flex items-start justify-between gap-3"><div className="min-w-0"><p className="text-xs font-semibold text-primary">Indikator {index + 1}</p><CardTitle className="mt-1 text-base leading-6">{item.nameSnapshot}</CardTitle></div><StatusBadge status={item.status} /></div></CardHeader>
              <CardContent>
                <dl className="grid grid-cols-2 gap-4 text-sm">
                  <div><dt className="flex items-center gap-1 text-xs text-muted-foreground"><TargetIcon /> Target</dt><dd className="mt-1 font-semibold">{item.targetValueSnapshot ? formatNumber(item.targetValueSnapshot.toString()) : "Rubrik"} {item.targetUnitSnapshot}</dd></div>
                  <div><dt className="text-xs text-muted-foreground">Aktual</dt><dd className="mt-1 font-semibold">{item.actualDecimal ? formatNumber(item.actualDecimal.toString()) : "Belum ada"}</dd></div>
                  <div><dt className="text-xs text-muted-foreground">Bobot</dt><dd className="mt-1"><Badge variant="secondary">{formatNumber(item.weightSnapshot.toString())}%</Badge></dd></div>
                  <div><dt className="text-xs text-muted-foreground">Nilai terbobot</dt><dd className="mt-1 font-semibold">{item.weightedScore ? formatNumber(item.weightedScore.toString()) : "Belum ada"}</dd></div>
                </dl>
                {item.calculationNote ? <p className="mt-4 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">{item.calculationNote}</p> : null}
                {canAssessItems && item.formulaKeySnapshot === "rubric" ? <RubricForm kpiId={kpi.id} itemId={item.id} rowVersion={kpi.rowVersion} itemVersion={item.rowVersion} criteria={criteria} fulfilled={fulfilled} /> : null}
                {canAssessItems && item.formulaKeySnapshot !== "rubric" ? <ReviewItemForm kpiId={kpi.id} itemId={item.id} rowVersion={kpi.rowVersion} itemVersion={item.rowVersion} /> : null}
              </CardContent>
            </Card>
          );
        })}
      </div>

      {canFinishReview ? <FinishReviewForm kpiId={kpi.id} rowVersion={kpi.rowVersion} /> : null}
      {canDecide ? <ApprovalForm kpiId={kpi.id} rowVersion={kpi.rowVersion} items={kpi.items.map((item) => ({ id: item.id, name: item.nameSnapshot }))} /> : null}

      {(kpi.reviews[0] || kpi.approvals.length) ? <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><ClipboardTextIcon /> Riwayat keputusan</CardTitle></CardHeader><CardContent className="space-y-3 text-sm">
        {kpi.reviews[0] ? <div className="flex items-start justify-between gap-4"><div><p className="font-medium">Review oleh {kpi.reviews[0].reviewer.name}</p><p className="text-xs text-muted-foreground">{kpi.reviews[0].notes ?? "Tanpa catatan"}</p></div><span className="text-xs text-muted-foreground">{formatDate(kpi.reviews[0].updatedAt)}</span></div> : null}
        {kpi.approvals.map((approval) => <div key={approval.id} className="flex items-start justify-between gap-4 border-t pt-3"><div><p className="font-medium">{approval.action === "approved" ? "Disetujui" : "Dikembalikan"} oleh {approval.approver.name}</p><p className="text-xs text-muted-foreground">{approval.reason ?? "Tanpa catatan"}</p></div><span className="text-xs text-muted-foreground">{formatDate(approval.createdAt)}</span></div>)}
      </CardContent></Card> : null}
    </div>
  );
}
