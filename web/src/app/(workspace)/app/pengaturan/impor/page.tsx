import { ArrowLeftIcon, ArrowsClockwiseIcon, PlusIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { ImportMappingActionButton, ImportMappingDraftForm, ImportMappingTemplateForm } from "@/components/settings/import-mapping-forms";
import { PageHeading } from "@/components/page-heading";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { CASHIER_MAPPING_FIELDS, type CashierMappingKey } from "@/modules/imports/mapping";

export const metadata: Metadata = { title: "Mapping impor kasir" };

function mappingValues(value: unknown) {
  const object = value !== null && !Array.isArray(value) && typeof value === "object" ? value as Record<string, unknown> : {};
  return Object.fromEntries(CASHIER_MAPPING_FIELDS.map((field) => [field.key, String(object[field.key] ?? "")])) as Record<CashierMappingKey, string>;
}

export default async function ImportMappingSettingsPage() {
  const user = await requireUser();
  if (!hasCapability(user, "imports.configure")) redirect("/app");
  const templates = await prisma.importMappingTemplate.findMany({
    orderBy: { name: "asc" },
    include: { versions: { orderBy: { versionNumber: "desc" }, include: { _count: { select: { batches: true } } } } },
  });

  return <div className="space-y-7">
    <Button asChild variant="link"><Link href="/app/pengaturan"><ArrowLeftIcon /> Kembali ke pengaturan</Link></Button>
    <PageHeading eyebrow="Konfigurasi impor" title="Mapping laporan kasir" description="Versi aktif dipakai saat membuat preview. Mapping yang sudah dipakai batch tetap terkunci sebagai riwayat." />
    <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><PlusIcon className="text-primary" /> Template sumber baru</CardTitle></CardHeader><CardContent><ImportMappingTemplateForm /></CardContent></Card>
    <section className="space-y-4">
      {templates.map((template) => {
        const active = template.versions.find((version) => version.isActive);
        const latest = template.versions[0];
        const draft = latest && !latest.isActive && latest._count.batches === 0 && latest.versionNumber > (active?.versionNumber ?? 0) ? latest : null;
        return <Card key={template.id}><CardHeader><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><CardTitle className="flex items-center gap-2 text-base"><ArrowsClockwiseIcon className="text-primary" /> {template.name}</CardTitle><p className="mt-1 text-xs text-muted-foreground">{template.sourceApplication} · versi aktif {active ? `v${active.versionNumber}` : "belum ada"}</p></div><Badge variant={template.isActive ? "default" : "secondary"}>{template.isActive ? "Template aktif" : "Template nonaktif"}</Badge></div></CardHeader><CardContent className="space-y-5">
          <details className="rounded-xl border p-4"><summary className="cursor-pointer text-sm font-semibold">Ubah template</summary><div className="mt-4 border-t pt-4"><ImportMappingTemplateForm value={{ id: template.id, name: template.name, sourceApplication: template.sourceApplication, description: template.description, isActive: template.isActive }} /></div></details>
          {draft ? <div className="space-y-4 rounded-xl border p-4"><div className="flex flex-wrap items-center justify-between gap-3"><div><p className="font-semibold">Draft v{draft.versionNumber}</p><p className="text-xs text-muted-foreground">Belum dipakai batch dan masih dapat diubah.</p></div><Badge variant="warning">Draft</Badge></div><ImportMappingDraftForm versionId={draft.id} mapping={mappingValues(draft.mappingsJson)} /><ImportMappingActionButton id={draft.id} actionKind="activate" /></div> : <ImportMappingActionButton id={template.id} actionKind="draft" />}
          <div className="overflow-x-auto rounded-xl border"><table className="w-full min-w-[560px] text-left text-sm"><thead className="bg-muted/60 text-xs"><tr><th className="px-3 py-2">Versi</th><th className="px-3 py-2">Status</th><th className="px-3 py-2">Batch</th><th className="px-3 py-2">Keterangan</th></tr></thead><tbody className="divide-y">{template.versions.map((version) => <tr key={version.id}><td className="px-3 py-3 font-medium">v{version.versionNumber}</td><td className="px-3 py-3"><Badge variant={version.isActive ? "default" : version.id === draft?.id ? "warning" : "secondary"}>{version.isActive ? "Aktif" : version.id === draft?.id ? "Draft" : "Riwayat"}</Badge></td><td className="px-3 py-3">{version._count.batches}</td><td className="px-3 py-3 text-xs text-muted-foreground">{version._count.batches ? "Terkunci karena sudah dipakai" : version.isActive ? "Dipakai untuk unggahan baru" : version.id === draft?.id ? "Dapat diedit" : "Versi lama terkunci"}</td></tr>)}</tbody></table></div>
        </CardContent></Card>;
      })}
    </section>
  </div>;
}
