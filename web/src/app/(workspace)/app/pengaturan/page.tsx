import { BuildingsIcon, CalendarDotsIcon, GearSixIcon, IdentificationCardIcon, StackIcon, UsersIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { EmptyState } from "@/components/empty-state";
import { PageHeading } from "@/components/page-heading";
import { StatusBadge } from "@/components/status-badge";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatDate, formatNumber } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { capabilitiesFor } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";

export const metadata: Metadata = { title: "Pengaturan" };

export default async function SettingsPage() {
  const user = await requireUser();
  const caps = capabilitiesFor(user);
  if (!["accounts.manage", "organization.manage", "kpi.catalog.configure", "kpi.assignments.manage", "kpi.period.manage", "imports.configure"].some((capability) => caps.has(capability))) redirect("/app");
  const [userCount, branches, positions, templates, periods] = await Promise.all([
    prisma.user.count({ where: { isActive: true } }),
    prisma.branch.findMany({ orderBy: { name: "asc" }, include: { _count: { select: { employees: true } } } }),
    prisma.position.findMany({ orderBy: { name: "asc" }, include: { _count: { select: { employees: true } } } }),
    prisma.kpiTemplate.findMany({ where: { isActive: true }, orderBy: { name: "asc" }, include: { position: true, versions: { orderBy: { versionNumber: "desc" }, take: 1 } } }),
    prisma.kpiPeriod.findMany({ orderBy: [{ year: "desc" }, { month: "desc" }], take: 8, include: { _count: { select: { employeeKpis: true } } } }),
  ]);

  return (
    <div className="space-y-7">
      <PageHeading eyebrow="Administrasi sistem" title="Pengaturan" description="Kondisi organisasi, katalog KPI, akun, dan periode penilaian." action={<div className="flex flex-wrap gap-2">{(caps.has("organization.manage") || caps.has("accounts.manage")) ? <Button asChild variant="outline"><Link href="/app/pengaturan/organisasi">Organisasi & akun</Link></Button> : null}{(caps.has("kpi.catalog.configure") || caps.has("kpi.assignments.manage")) ? <Button asChild variant="outline"><Link href="/app/pengaturan/kpi">Katalog KPI</Link></Button> : null}{caps.has("imports.configure") ? <Button asChild variant="outline"><Link href="/app/pengaturan/impor">Mapping impor</Link></Button> : null}{caps.has("kpi.period.manage") ? <Button asChild><Link href="/app/pengaturan/periode">Periode KPI</Link></Button> : null}</div>} />
      <section className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Card><CardContent className="p-5"><UsersIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{userCount}</p><p className="text-xs text-muted-foreground">Akun aktif</p></CardContent></Card>
        <Card><CardContent className="p-5"><BuildingsIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{branches.length}</p><p className="text-xs text-muted-foreground">Cabang</p></CardContent></Card>
        <Card><CardContent className="p-5"><IdentificationCardIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{positions.length}</p><p className="text-xs text-muted-foreground">Jabatan</p></CardContent></Card>
        <Card><CardContent className="p-5"><StackIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{templates.length}</p><p className="text-xs text-muted-foreground">Template aktif</p></CardContent></Card>
      </section>

      <div className="grid gap-5 xl:grid-cols-[1.1fr_0.9fr]">
        <Card><CardHeader><div className="flex items-center justify-between gap-3"><CardTitle className="flex items-center gap-2 text-base"><CalendarDotsIcon className="text-primary" /> Periode KPI</CardTitle>{caps.has("kpi.period.manage") ? <Button asChild size="sm" variant="outline"><Link href="/app/pengaturan/periode">Kelola</Link></Button> : null}</div></CardHeader><CardContent>{periods.length ? <div className="divide-y">{periods.map((period) => <div key={period.id} className="flex min-h-20 items-center justify-between gap-4 py-3"><div><p className="text-sm font-semibold">{period.name}</p><p className="mt-1 text-xs text-muted-foreground">{formatDate(period.startDate)} sampai {formatDate(period.endDate)} · {period._count.employeeKpis} KPI</p></div><StatusBadge status={period.status} /></div>)}</div> : <EmptyState icon={CalendarDotsIcon} title="Belum ada periode" description="Periode KPI yang dibuat akan tampil di sini." />}</CardContent></Card>
        <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><StackIcon className="text-primary" /> Template KPI</CardTitle></CardHeader><CardContent>{templates.length ? <div className="divide-y">{templates.map((template) => <div key={template.id} className="py-3"><div className="flex items-start justify-between gap-4"><div><p className="text-sm font-semibold">{template.name}</p><p className="text-xs text-muted-foreground">{template.position.name} · {template.code}</p></div>{template.versions[0] ? <Badge variant={template.versions[0].status === "ACTIVE" ? "default" : "secondary"}>v{template.versions[0].versionNumber} · {formatNumber(template.versions[0].totalWeight.toString())}%</Badge> : <Badge variant="warning">Tanpa versi</Badge>}</div></div>)}</div> : <EmptyState icon={GearSixIcon} title="Belum ada template" description="Katalog KPI perlu dimigrasikan sebelum periode disiapkan." />}</CardContent></Card>
      </div>

      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><BuildingsIcon className="text-primary" /> Organisasi</CardTitle></CardHeader><CardContent><div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">{branches.map((branch) => <div key={branch.id} className="rounded-xl border p-4"><div className="flex items-center justify-between gap-3"><p className="font-semibold">{branch.name}</p><Badge variant={branch.isActive ? "default" : "secondary"}>{branch.isActive ? "Aktif" : "Nonaktif"}</Badge></div><p className="mt-1 text-xs text-muted-foreground">{branch.code} · {branch._count.employees} karyawan</p></div>)}</div></CardContent></Card>
    </div>
  );
}
