import { NotePencilIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { EmptyState } from "@/components/empty-state";
import { CorrectionDecisionForm, CorrectionRequestForm } from "@/components/kpi/correction-forms";
import { PageHeading } from "@/components/page-heading";
import { StatusBadge } from "@/components/status-badge";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { Prisma } from "@/generated/prisma/client";
import { formatDate } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { canApproveKpiCorrection, canRequestKpiCorrection, hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { kpiScopeFor } from "@/modules/access/scope";

export const metadata: Metadata = { title: "Koreksi KPI" };

function object(value: Prisma.JsonValue | null) {
  return value !== null && !Array.isArray(value) && typeof value === "object" ? value : {};
}

export default async function CorrectionsPage() {
  const user = await requireUser();
  if (!hasCapability(user, "kpi.correction.request") && !hasCapability(user, "kpi.correction.manage")) redirect("/app");
  const scope = kpiScopeFor(user);
  const [candidateKpis, corrections] = await Promise.all([
    prisma.employeeKpi.findMany({ where: { ...scope, status: "LOCKED", corrections: { none: { status: "PENDING" } }, items: { some: { sourceTypeSnapshot: { notIn: ["system", "import", "cross_role"] } } } }, orderBy: { updatedAt: "desc" }, take: 20, include: { period: true, items: { orderBy: { createdAt: "asc" } } } }),
    prisma.kpiCorrectionRequest.findMany({ where: { employeeKpi: scope }, orderBy: { createdAt: "desc" }, take: 100, include: { requestedBy: { select: { name: true } }, approvedBy: { select: { name: true } }, employeeKpi: { include: { employee: { select: { name: true } }, period: { select: { name: true } } } } } }),
  ]);
  const requestable = candidateKpis.filter((kpi) => canRequestKpiCorrection(user, kpi));

  return <div className="space-y-7"><PageHeading eyebrow="Dual authorization" title="Koreksi KPI terkunci" description="Nilai lama tetap tersimpan. Perubahan baru diterapkan setelah disetujui pihak Manager yang berbeda." />
    {requestable.length ? <section className="space-y-4"><h2 className="text-base font-semibold">Ajukan koreksi</h2>{requestable.map((kpi) => {
      const items = kpi.items.filter((item) => !["system", "import", "cross_role"].includes(item.sourceTypeSnapshot.toLowerCase())).map((item) => ({ id: item.id, code: item.definitionCodeSnapshot, name: item.nameSnapshot, actual: item.actualDecimal?.toString() ?? null }));
      return <Card key={kpi.id}><CardHeader><CardTitle className="text-base">{kpi.employeeNameSnapshot} · {kpi.period.name}</CardTitle></CardHeader><CardContent><div className="flex flex-wrap gap-2"><Badge variant="secondary">Revisi {kpi.revisionNumber}</Badge><StatusBadge status={kpi.status} /></div><CorrectionRequestForm employeeKpiId={kpi.id} items={items} /></CardContent></Card>;
    })}</section> : null}
    <section className="space-y-4"><h2 className="text-base font-semibold">Riwayat dan antrean keputusan</h2>{corrections.length === 0 ? <EmptyState icon={NotePencilIcon} title="Belum ada koreksi" description="KPI yang sudah dikunci dapat diajukan dari halaman ini." /> : corrections.map((correction) => {
      const after = object(correction.afterJson);
      const proposed = Array.isArray(after.items) ? after.items.map((item) => object(item)).filter((item) => typeof item.code === "string") : [];
      const approvable = correction.status === "PENDING" && canApproveKpiCorrection(user, correction);
      return <Card key={correction.id}><CardContent className="p-5 sm:p-6"><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><p className="font-semibold">{correction.employeeKpi.employee.name} · {correction.employeeKpi.period.name}</p><p className="mt-1 text-xs text-muted-foreground">Diajukan {correction.requestedBy.name} pada {formatDate(correction.createdAt)}</p></div><StatusBadge status={correction.status} /></div><p className="mt-4 text-sm leading-6">{correction.reason}</p>{proposed.length ? <div className="mt-3 flex flex-wrap gap-2">{proposed.map((item, index) => <Badge key={`${String(item.code)}-${index}`} variant="outline">{String(item.code)} → {String(item.actual)}</Badge>)}</div> : null}{correction.rejectionReason ? <p className="mt-3 rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">Ditolak: {correction.rejectionReason}</p> : null}{correction.approvedBy ? <p className="mt-3 text-xs text-muted-foreground">Diputuskan oleh {correction.approvedBy.name}{correction.appliedAt ? ` · diterapkan ${formatDate(correction.appliedAt)}` : ""}</p> : null}{approvable ? <CorrectionDecisionForm correctionId={correction.id} /> : correction.status === "PENDING" && correction.requestedById === user.id ? <p className="mt-4 text-xs text-muted-foreground">Menunggu pihak Manager lain untuk dual authorization.</p> : null}</CardContent></Card>;
    })}</section>
  </div>;
}
