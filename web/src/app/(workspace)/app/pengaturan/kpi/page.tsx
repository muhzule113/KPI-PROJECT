import { ArrowLeftIcon, ChartBarIcon, ListChecksIcon, PlusIcon, SlidersHorizontalIcon, UserSwitchIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { ActivateTemplateForm, AssignmentForm, CloneTemplateButton, DefinitionForm, RatingSchemeForm, RemoveItemButton, SchemeButton, TemplateForm, TemplateItemForm, TemplateSchemeForm } from "@/components/settings/kpi-catalog-forms";
import { PageHeading } from "@/components/page-heading";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { Prisma } from "@/generated/prisma/client";
import { formatNumber } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { capabilitiesFor } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { cadence } from "@/modules/kpi/daily-values";

export const metadata: Metadata = { title: "Katalog dan assignment KPI" };

function json(value: Prisma.JsonValue | null) {
  return value !== null && !Array.isArray(value) && typeof value === "object" ? value : {};
}

export default async function KpiSettingsPage() {
  const user = await requireUser(); const caps = capabilitiesFor(user);
  const catalog = caps.has("kpi.catalog.configure"); const assignments = caps.has("kpi.assignments.manage");
  if (!catalog && !assignments && !caps.has("imports.configure")) redirect("/app/pengaturan");
  const [definitions, schemes, templates, positions, employees] = await Promise.all([
    catalog ? prisma.kpiDefinition.findMany({ orderBy: { code: "asc" } }) : Promise.resolve([]),
    catalog ? prisma.kpiRatingScheme.findMany({ orderBy: [{ name: "asc" }, { version: "desc" }], include: { bands: { orderBy: { sortOrder: "asc" } } } }) : Promise.resolve([]),
    catalog ? prisma.kpiTemplate.findMany({ where: { isActive: true }, orderBy: { name: "asc" }, include: { position: true, versions: { orderBy: { versionNumber: "desc" }, include: { ratingScheme: true, items: { orderBy: { sortOrder: "asc" }, include: { definition: true, rubric: { include: { criteria: { orderBy: { sortOrder: "asc" } } } } } } } } } }) : Promise.resolve([]),
    catalog ? prisma.position.findMany({ where: { isActive: true }, orderBy: { name: "asc" } }) : Promise.resolve([]),
    assignments ? prisma.employee.findMany({ where: { status: "ACTIVE" }, orderBy: { name: "asc" }, include: { position: true, branch: true, user: true, supervisor: true } }) : Promise.resolve([]),
  ]);
  const definitionOptions = definitions.filter((row) => row.isActive).map((row) => ({ id: row.id, label: `${row.code} · ${row.name}` }));
  const schemeOptions = schemes.filter((row) => row.isActive).map((row) => ({ id: row.id, label: `${row.name} v${row.version}` }));
  const positionOptions = positions.map((row) => ({ id: row.id, label: `${row.code} · ${row.name}` }));
  const assignmentEmployees = employees.filter((row) => !["POS-OWN", "POS-EXEC"].includes(row.position.code)).map((row) => ({ id: row.id, label: `${row.employeeNumber} · ${row.name} · ${row.branch.name} · saat ini ${row.supervisor?.name ?? "belum ada"}` }));
  const reviewerOptions = employees.filter((row) => row.user?.isActive && ["supervisor", "owner_manager"].includes(row.user.role)).map((row) => ({ id: row.id, label: `${row.name} · ${row.branch.name} · ${row.user!.role}` }));
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Makassar", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());

  return <div className="space-y-7">
    <Button asChild variant="link"><Link href="/app/pengaturan"><ArrowLeftIcon /> Kembali ke pengaturan</Link></Button>
    <PageHeading eyebrow="Konfigurasi" title="Katalog dan assignment KPI" description="Perubahan master memakai draft dan versi. Snapshot periode yang sudah dibuat tidak ikut berubah." />

    {assignments ? <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><UserSwitchIcon className="text-primary" /> Assignment penilai</CardTitle></CardHeader><CardContent><AssignmentForm employees={assignmentEmployees} reviewers={reviewerOptions} today={today} /><p className="mt-4 text-xs leading-5 text-muted-foreground">Perubahan hanya berlaku untuk snapshot periode berikutnya; snapshot yang sudah berjalan tetap memakai assignment lama.</p></CardContent></Card> : null}

    {catalog ? <>
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><PlusIcon className="text-primary" /> Indikator baru</CardTitle></CardHeader><CardContent><DefinitionForm /></CardContent></Card>
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><ListChecksIcon className="text-primary" /> Definisi indikator ({definitions.length})</CardTitle></CardHeader><CardContent className="space-y-2">{definitions.map((definition) => <details key={definition.id} className="rounded-xl border p-4"><summary className="flex cursor-pointer list-none items-start justify-between gap-3"><span><span className="block font-medium">{definition.code} · {definition.name}</span><span className="mt-1 block text-xs text-muted-foreground">{definition.defaultFormula} · {definition.sourceType} · {definition.unit}</span></span><Badge variant={definition.isActive ? "default" : "secondary"}>{definition.isActive ? "Aktif" : "Nonaktif"}</Badge></summary><div className="mt-4 border-t pt-4"><DefinitionForm value={definition} /></div></details>)}</CardContent></Card>

      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><PlusIcon className="text-primary" /> Draft skala predikat baru</CardTitle></CardHeader><CardContent><RatingSchemeForm /></CardContent></Card>
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><SlidersHorizontalIcon className="text-primary" /> Skala predikat ({schemes.length})</CardTitle></CardHeader><CardContent className="space-y-3">{schemes.map((scheme) => <details key={scheme.id} className="rounded-xl border p-4"><summary className="flex cursor-pointer list-none items-center justify-between gap-3"><span className="font-medium">{scheme.name} v{scheme.version}</span><Badge variant={scheme.isActive ? "default" : "warning"}>{scheme.isActive ? "Aktif" : "Draft"}</Badge></summary><div className="mt-4 space-y-4 border-t pt-4">{scheme.isActive ? <SchemeButton schemeId={scheme.id} kind="clone" /> : <><RatingSchemeForm value={{ id: scheme.id, name: scheme.name, description: scheme.description, bands: scheme.bands.map((band) => ({ code: band.code, label: band.label, minScore: band.minScore.toString(), maxScore: band.maxScore.toString(), manualScore: band.manualScore?.toString() ?? "", color: band.color })) }} /><SchemeButton schemeId={scheme.id} kind="activate" /></>}</div></details>)}</CardContent></Card>

      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><PlusIcon className="text-primary" /> Template baru</CardTitle></CardHeader><CardContent><TemplateForm positions={positionOptions} schemes={schemeOptions} /></CardContent></Card>
      <section className="space-y-4">{templates.map((template) => {
        const draft = template.versions.find((version) => version.status === "DRAFT"); const active = template.versions.find((version) => version.status === "ACTIVE");
        return <Card key={template.id}><CardHeader><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><CardTitle className="flex items-center gap-2 text-base"><ChartBarIcon className="text-primary" /> {template.code} · {template.name}</CardTitle><p className="mt-1 text-xs text-muted-foreground">{template.position.name} · aktif {active ? `v${active.versionNumber}` : "belum ada"}</p></div>{active && !draft ? <CloneTemplateButton versionId={active.id} /> : null}</div></CardHeader><CardContent>
          {draft ? <div className="space-y-5"><div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-muted/40 p-4"><div><p className="font-semibold">Draft v{draft.versionNumber}</p><p className="text-xs text-muted-foreground">{draft.items.length} indikator · total bobot {formatNumber(draft.totalWeight.toString())}% · {draft.ratingScheme?.name ?? "tanpa skala"}</p></div><Badge variant={draft.totalWeight.toNumber() === 100 ? "default" : "warning"}>{draft.totalWeight.toNumber() === 100 ? "Bobot siap" : "Bobot belum 100%"}</Badge></div>
            <TemplateSchemeForm versionId={draft.id} schemeId={draft.ratingSchemeId} schemes={schemeOptions} />
            <details className="rounded-xl border p-4"><summary className="cursor-pointer font-medium">Tambah indikator ke draft</summary><div className="mt-4 border-t pt-4"><TemplateItemForm versionId={draft.id} definitions={definitionOptions} /></div></details>
            <div className="space-y-2">{draft.items.map((item) => { const target = json(item.targetJson); const rubricText = item.rubric?.criteria.map((criterion) => `${criterion.criterionText}|${criterion.points}`).join("\n") ?? ""; return <details key={item.id} className="rounded-xl border p-4"><summary className="flex cursor-pointer list-none items-start justify-between gap-3"><span><span className="block font-medium">{item.definition.code} · {item.definition.name}</span><span className="mt-1 block text-xs text-muted-foreground">Bobot {item.weight.toString()}% · target {item.targetValue?.toString() ?? "rubric"} {item.targetUnit} · {item.sourceType}</span></span><Badge variant="secondary">#{item.sortOrder}</Badge></summary><div className="mt-4 space-y-3 border-t pt-4"><TemplateItemForm versionId={draft.id} definitions={definitionOptions} value={{ id: item.id, definitionId: item.kpiDefinitionId, weight: item.weight.toString(), targetValue: item.targetValue?.toString() ?? "", targetUnit: item.targetUnit, formulaKey: item.formulaKey, sourceType: item.sourceType, cadence: cadence(item.targetJson, item.formulaParams), failureLimit: target.failure_limit == null ? "" : String(target.failure_limit), fullScoreLimit: target.full_score_limit == null ? "" : String(target.full_score_limit), evidenceRequired: item.evidenceRequired, isMandatory: item.isMandatory, sortOrder: item.sortOrder, rubricName: item.rubric?.name ?? "", rubricCriteria: rubricText }} /><RemoveItemButton versionId={draft.id} itemId={item.id} /></div></details>; })}</div>
            <ActivateTemplateForm versionId={draft.id} today={today} />
          </div> : <p className="text-sm text-muted-foreground">Tidak ada draft. Buat draft dari versi aktif untuk mengubah konfigurasi.</p>}
        </CardContent></Card>;
      })}</section>
    </> : null}
  </div>;
}
