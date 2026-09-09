import { ClockCounterClockwiseIcon, MagnifyingGlassIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { EmptyState } from "@/components/empty-state";
import { PageHeading } from "@/components/page-heading";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { prisma } from "@/lib/prisma";
import { capabilitiesFor } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { presentAuditJson } from "@/modules/audit/presentation";

export const metadata: Metadata = { title: "Audit" };

const syncActions = [
  "daily_kpi_sync_completed",
  "daily_kpi_sync_failed",
  "activate_kpi_template_version",
  "activate_rating_scheme",
  "stage_cashier_import",
  "confirm_cashier_import",
  "period_open",
  "period_calculating",
  "period_published",
  "period_locked",
  "update_reviewer_assignment",
];
const dateTime = new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeStyle: "short", timeZone: "Asia/Makassar" });

export default async function AuditPage({ searchParams }: { searchParams: Promise<{ q?: string; page?: string }> }) {
  const user = await requireUser();
  const capabilities = capabilitiesFor(user);
  if (!capabilities.has("audit.view") && !capabilities.has("audit.sync.view")) redirect("/app");
  const params = await searchParams;
  const q = params.q?.trim().slice(0, 100) ?? "";
  const page = Math.max(1, Number.parseInt(params.page ?? "1", 10) || 1);
  const syncOnly = !capabilities.has("audit.view");
  const where = {
    AND: [
      syncOnly ? { OR: [{ action: { contains: "sync", mode: "insensitive" as const } }, { action: { in: syncActions } }] } : {},
      q ? { OR: [
        { action: { contains: q, mode: "insensitive" as const } },
        { subjectType: { contains: q, mode: "insensitive" as const } },
        { subjectId: { contains: q, mode: "insensitive" as const } },
        { reason: { contains: q, mode: "insensitive" as const } },
      ] } : {},
    ],
  };
  const pageSize = 50;
  const [events, total] = await Promise.all([
    prisma.auditEvent.findMany({ where, orderBy: [{ occurredAt: "desc" }, { id: "desc" }], skip: (page - 1) * pageSize, take: pageSize, include: { actor: { select: { name: true, email: true } } } }),
    prisma.auditEvent.count({ where }),
  ]);
  const pageCount = Math.max(1, Math.ceil(total / pageSize));
  if (page > pageCount && total > 0) redirect(`/app/audit${q ? `?q=${encodeURIComponent(q)}` : ""}`);
  const pageHref = (target: number) => `/app/audit?${new URLSearchParams({ ...(q ? { q } : {}), page: String(target) })}`;

  return <div className="space-y-7">
    <PageHeading eyebrow="Jejak perubahan" title={syncOnly ? "Audit sinkronisasi" : "Audit sistem"} description={syncOnly ? "Aktivitas sinkronisasi, periode, template, dan impor yang boleh dipantau Admin KPI." : "Riwayat read-only untuk perubahan dan aktivitas material di seluruh sistem."} />
    <form method="get" className="flex flex-col gap-3 rounded-2xl border bg-card p-4 sm:flex-row sm:items-end">
      <div className="min-w-0 flex-1 space-y-2"><Label htmlFor="auditSearch">Cari aksi, tipe, ID objek, atau alasan</Label><Input id="auditSearch" name="q" defaultValue={q} maxLength={100} /></div>
      <Button type="submit" variant="outline"><MagnifyingGlassIcon /> Cari</Button>
      {q ? <Button asChild variant="ghost"><Link href="/app/audit">Bersihkan</Link></Button> : null}
    </form>
    <div className="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground"><span>{total} kejadian · halaman {page} dari {pageCount}</span><span>Maksimal {pageSize} kejadian per halaman</span></div>
    {events.length === 0 ? <EmptyState icon={ClockCounterClockwiseIcon} title="Tidak ada kejadian audit" description={q ? "Coba kata pencarian lain." : "Aktivitas material akan tampil di sini."} /> : <div className="space-y-3">{events.map((event) => {
      const before = presentAuditJson(event.beforeJson);
      const after = presentAuditJson(event.afterJson);
      return <Card key={event.id}><CardContent className="p-4 sm:p-5"><div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><Badge variant="outline">{event.action.replaceAll("_", " ")}</Badge><Badge variant="secondary">{event.subjectType}</Badge></div><p className="mt-2 break-all font-mono text-xs text-muted-foreground">{event.subjectId}</p></div><time className="shrink-0 text-xs text-muted-foreground" dateTime={event.occurredAt.toISOString()}>{dateTime.format(event.occurredAt)}</time></div><div className="mt-3 grid gap-2 text-sm sm:grid-cols-2"><p><span className="text-muted-foreground">Pelaku:</span> {event.actor?.name ?? "System"}{event.actor?.email ? ` (${event.actor.email})` : ""}</p><p><span className="text-muted-foreground">IP:</span> {event.ipAddress ?? "—"}</p></div>{event.reason ? <p className="mt-3 rounded-lg bg-muted px-3 py-2 text-sm">{event.reason}</p> : null}{before || after ? <details className="mt-3 rounded-lg border p-3"><summary className="cursor-pointer text-sm font-medium">Lihat before / after</summary><div className="mt-3 grid gap-3 lg:grid-cols-2">{before ? <div><p className="mb-1 text-xs font-semibold text-muted-foreground">Sebelum</p><pre className="max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-md bg-muted p-3 text-xs">{before}</pre></div> : null}{after ? <div><p className="mb-1 text-xs font-semibold text-muted-foreground">Sesudah</p><pre className="max-h-72 overflow-auto whitespace-pre-wrap break-all rounded-md bg-muted p-3 text-xs">{after}</pre></div> : null}</div></details> : null}</CardContent></Card>;
    })}</div>}
    {pageCount > 1 ? <nav aria-label="Paginasi audit" className="flex items-center justify-between"><Button asChild={page > 1} variant="outline" disabled={page <= 1}>{page > 1 ? <Link href={pageHref(page - 1)}>Sebelumnya</Link> : "Sebelumnya"}</Button><Button asChild={page < pageCount} variant="outline" disabled={page >= pageCount}>{page < pageCount ? <Link href={pageHref(page + 1)}>Berikutnya</Link> : "Berikutnya"}</Button></nav> : null}
  </div>;
}
