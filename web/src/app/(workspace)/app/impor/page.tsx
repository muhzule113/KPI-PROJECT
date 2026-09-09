import { ArrowsClockwiseIcon, CheckCircleIcon, FileArrowUpIcon, WarningCircleIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import { redirect } from "next/navigation";
import { EmptyState } from "@/components/empty-state";
import { ImportConfirmForm, ImportUploadForm } from "@/components/imports/import-forms";
import { ImportStatusRefresher } from "@/components/imports/import-status-refresher";
import { PageHeading } from "@/components/page-heading";
import { StatusBadge } from "@/components/status-badge";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import type { Prisma } from "@/generated/prisma/client";
import { formatDate, formatMoney } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { branchScopeFor } from "@/modules/access/scope";

export const metadata: Metadata = { title: "Impor data" };

function object(value: Prisma.JsonValue | null | undefined) {
  return value !== null && !Array.isArray(value) && typeof value === "object" ? value : {};
}

function objects(value: Prisma.JsonValue | undefined) {
  return Array.isArray(value) ? value.map((item) => object(item)) : [];
}

export default async function ImportsPage() {
  const user = await requireUser();
  if (!hasCapability(user, "imports.configure") && !hasCapability(user, "cashier.import")) redirect("/app");
  const branches = await prisma.branch.findMany({ where: { isActive: true, id: hasCapability(user, "imports.configure") ? undefined : user.employee?.branchId ?? "__no_access__" }, orderBy: { name: "asc" }, select: { id: true, name: true } });
  const [periods, mappings, batches] = await Promise.all([
    prisma.kpiPeriod.findMany({ where: { status: "OPEN", branches: { some: { branchId: { in: branches.map((branch) => branch.id) } } } }, orderBy: [{ year: "desc" }, { month: "desc" }], select: { id: true, name: true } }),
    prisma.importMappingVersion.findMany({ where: { isActive: true, template: { isActive: true } }, orderBy: { createdAt: "desc" }, include: { template: true } }),
    prisma.importBatch.findMany({ where: branchScopeFor(user), orderBy: { createdAt: "desc" }, take: 50, include: { branch: true, period: true, uploader: { select: { name: true } }, mappingVersion: { include: { template: true } } } }),
  ]);
  const completed = batches.filter((batch) => ["COMPLETED", "COMPLETED_WITH_WARNINGS"].includes(batch.status)).length;
  const issueCount = batches.filter((batch) => batch.errorRows > 0 || ["FAILED", "NEEDS_MAPPING", "NEEDS_REVIEW"].includes(batch.status)).length;
  const processing = batches.some((batch) => ["UPLOADED", "SCANNING", "QUEUED", "PARSING", "NORMALIZING", "VALIDATING", "COMMITTING"].includes(batch.status));

  return <div className="space-y-7">
    <PageHeading eyebrow="Data kasir" title="Impor laporan POS" description="Validasi file, tinjau setiap masalah, lalu konfirmasi sebelum transaksi memengaruhi KPI." />
    {processing ? <ImportStatusRefresher /> : null}
    <ImportUploadForm periods={periods} branches={branches} mappings={mappings.map((mapping) => ({ id: mapping.id, name: `${mapping.template.name} · ${mapping.template.sourceApplication} · v${mapping.versionNumber}` }))} />
    <section className="grid grid-cols-2 gap-3 lg:grid-cols-3">
      <Card><CardContent className="p-5"><FileArrowUpIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{batches.length}</p><p className="text-xs text-muted-foreground">Batch terbaru</p></CardContent></Card>
      <Card><CardContent className="p-5"><CheckCircleIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{completed}</p><p className="text-xs text-muted-foreground">Selesai diproses</p></CardContent></Card>
      <Card className="col-span-2 lg:col-span-1"><CardContent className="p-5"><WarningCircleIcon className="text-amber-600" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{issueCount}</p><p className="text-xs text-muted-foreground">Perlu perhatian</p></CardContent></Card>
    </section>
    {batches.length === 0 ? <EmptyState icon={ArrowsClockwiseIcon} title="Belum ada data impor" description="Unggah XLSX atau CSV untuk membuat preview pertama." /> : <div className="space-y-4">{batches.map((batch) => {
      const summary = object(batch.summaryJson);
      const samples = objects(summary.sample_rows as Prisma.JsonValue | undefined);
      const issues = objects(batch.issuesJson ?? undefined);
      return <Card key={batch.id} id={`batch-${batch.id}`}><CardContent className="p-5 sm:p-6"><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div className="min-w-0"><p className="truncate font-semibold">{batch.fileName}</p><p className="mt-1 text-xs text-muted-foreground">{batch.sourceApplication} · {batch.period.name} · {batch.branch.name} · {formatDate(batch.createdAt)}</p><p className="mt-1 font-mono text-[11px] text-muted-foreground">SHA-256 {batch.fileHashSha256?.slice(0, 16) ?? "tidak tersedia"}…</p></div><StatusBadge status={batch.status} /></div><div className="mt-4 flex flex-wrap gap-2"><Badge variant="secondary">{batch.totalRows} baris</Badge><Badge variant="secondary">{batch.validRows} valid</Badge><Badge variant={batch.errorRows ? "destructive" : "secondary"}>{batch.errorRows} error</Badge><Badge variant="secondary">{batch.duplicateRows} duplikat</Badge>{batch.mappingVersion ? <Badge variant="outline">Mapping {batch.mappingVersion.template.name} v{batch.mappingVersion.versionNumber}</Badge> : null}</div>
        {issues.length ? <div className="mt-4 rounded-xl border border-amber-300/60 bg-amber-50/60 p-4"><p className="text-sm font-semibold">Masalah yang ditemukan</p><ul className="mt-2 space-y-1 text-xs text-muted-foreground">{issues.slice(0, 10).map((issue, index) => <li key={`${String(issue.code)}-${index}`}>Baris {String(issue.row ?? "-")}: {String(issue.message ?? "Masalah tidak dikenal")}</li>)}</ul>{issues.length > 10 ? <p className="mt-2 text-xs text-muted-foreground">Dan {issues.length - 10} masalah lainnya.</p> : null}</div> : null}
        {samples.length ? <div className="mt-4 overflow-x-auto rounded-xl border"><table className="w-full min-w-[760px] text-left text-xs"><thead className="bg-muted/60"><tr><th className="px-3 py-2">Transaksi</th><th className="px-3 py-2">Tanggal</th><th className="px-3 py-2">Kasir</th><th className="px-3 py-2">Nominal</th><th className="px-3 py-2">Selisih</th><th className="px-3 py-2">Status</th></tr></thead><tbody className="divide-y">{samples.map((row, index) => <tr key={`${String(row.business_key)}-${index}`}><td className="px-3 py-2 font-mono">{String(row.transaction_number ?? "-")}</td><td className="px-3 py-2">{String(row.transaction_date ?? "-").slice(0, 10)}</td><td className="px-3 py-2">{String(row.cashier_name_raw ?? "-")}</td><td className="px-3 py-2">{typeof row.transaction_amount === "number" ? formatMoney(String(row.transaction_amount)) : "-"}</td><td className="px-3 py-2">{typeof row.cash_difference === "number" ? formatMoney(String(row.cash_difference)) : "-"}</td><td className="px-3 py-2"><Badge variant={row.row_status === "error" ? "destructive" : "secondary"}>{String(row.row_status ?? "-")}</Badge></td></tr>)}</tbody></table></div> : null}
        {batch.status === "READY_FOR_PREVIEW" && batch.errorRows === 0 ? <ImportConfirmForm batchId={batch.id} warningRows={batch.warningRows} /> : null}
      </CardContent></Card>;
    })}</div>}
  </div>;
}
