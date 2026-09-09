import { MagnifyingGlassIcon, PlusIcon, WrenchIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { EmptyState } from "@/components/empty-state";
import { PageHeading } from "@/components/page-heading";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { formatDate, formatMoney } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { ticketScopeFor } from "@/modules/access/scope";

export const metadata: Metadata = { title: "Servis" };

export default async function ServicePage({ searchParams }: { searchParams: Promise<{ q?: string; status?: string }> }) {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.view")) redirect("/app");
  const filters = await searchParams;
  const query = filters.q?.trim().slice(0, 100) ?? "";
  const validStatuses = ["INTAKE", "DIAGNOSING", "WAITING_CONSENT", "WAITING_SPAREPART", "IN_PROGRESS", "QC_READY", "COMPLETED", "DELIVERED", "CANCELLED", "CANCELLED_UNREPAIRABLE"];
  const status = validStatuses.includes(filters.status ?? "") ? filters.status : "";
  const tickets = await prisma.serviceTicket.findMany({
    where: {
      ...ticketScopeFor(user),
      status: status ? status as "INTAKE" : undefined,
      OR: query ? [{ ticketNumber: { contains: query, mode: "insensitive" } }, { customerName: { contains: query, mode: "insensitive" } }, { deviceBrand: { contains: query, mode: "insensitive" } }, { deviceModel: { contains: query, mode: "insensitive" } }] : undefined,
    },
    orderBy: { updatedAt: "desc" },
    take: 100,
    include: { branch: true, technician: { select: { name: true } } },
  });
  const canCreate = hasCapability(user, "tickets.create") && user.employee?.position.code === "POS-CS";

  return (
    <div className="space-y-7">
      <PageHeading eyebrow="Meja servis" title="Tiket servis" description="Pantau perangkat dari penerimaan sampai diserahkan kembali." action={canCreate ? <Button asChild><Link href="/app/servis/baru"><PlusIcon /> Tiket baru</Link></Button> : undefined} />
      <form className="grid gap-2 rounded-2xl border bg-card p-3 sm:grid-cols-[1fr_14rem_auto]" method="get">
        <div className="relative"><MagnifyingGlassIcon className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted-foreground" /><Input name="q" defaultValue={query} placeholder="Cari nomor, pelanggan, atau perangkat" className="pl-10" /></div>
        <select name="status" defaultValue={status} aria-label="Filter status" className="h-11 rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="">Semua status</option>{validStatuses.map((value) => <option key={value} value={value}>{value.replaceAll("_", " ")}</option>)}</select>
        <Button type="submit" variant="outline">Terapkan</Button>
      </form>
      {tickets.length === 0 ? <EmptyState icon={WrenchIcon} title="Tiket tidak ditemukan" description={query || status ? "Ubah kata pencarian atau filter status." : "Belum ada perangkat yang masuk ke meja servis."} action={canCreate && !query && !status ? <Button asChild><Link href="/app/servis/baru">Buat tiket pertama</Link></Button> : undefined} /> : (
        <div className="overflow-hidden rounded-2xl border bg-card">
          <div className="hidden grid-cols-[1.1fr_1.2fr_1fr_1fr_auto] gap-4 border-b bg-muted/55 px-5 py-3 text-xs font-semibold text-muted-foreground md:grid"><span>Tiket</span><span>Pelanggan</span><span>Perangkat</span><span>Teknisi</span><span>Status</span></div>
          <div className="divide-y">{tickets.map((ticket) => <Link key={ticket.id} href={`/app/servis/${ticket.id}`} className="grid min-h-20 gap-3 p-4 outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring md:grid-cols-[1.1fr_1.2fr_1fr_1fr_auto] md:items-center md:gap-4 md:px-5">
            <div><p className="font-mono text-xs font-semibold text-primary">{ticket.ticketNumber}</p><p className="mt-1 text-xs text-muted-foreground">{formatDate(ticket.createdAt)}</p></div>
            <div><p className="text-sm font-semibold">{ticket.customerName}</p><p className="text-xs text-muted-foreground">{ticket.branch.name}</p></div>
            <div><p className="text-sm">{ticket.deviceBrand} {ticket.deviceModel}</p><p className="text-xs text-muted-foreground">{formatMoney(ticket.finalCost.gt(0) ? ticket.finalCost.toString() : ticket.estimatedCost.toString())}</p></div>
            <p className="text-sm text-muted-foreground">{ticket.technician?.name ?? "Belum ditugaskan"}</p><div><StatusBadge status={ticket.status} /></div>
          </Link>)}</div>
        </div>
      )}
    </div>
  );
}
