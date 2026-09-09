import { ArrowLeftIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { CreateTicketForm } from "@/components/tickets/ticket-forms";
import { Button } from "@/components/ui/button";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";

export const metadata: Metadata = { title: "Tiket servis baru" };

export default async function NewTicketPage() {
  const user = await requireUser();
  if (!hasCapability(user, "tickets.create") || user.employee?.position.code !== "POS-CS") redirect("/app/servis");
  const branches = await prisma.branch.findMany({ where: { isActive: true, id: user.role === "super_admin" ? undefined : user.employee?.branchId }, orderBy: { name: "asc" }, select: { id: true, name: true } });
  return <div className="mx-auto max-w-3xl space-y-6"><Button asChild variant="link"><Link href="/app/servis"><ArrowLeftIcon /> Kembali ke tiket servis</Link></Button><div><p className="text-xs font-semibold uppercase tracking-[0.16em] text-primary">Penerimaan perangkat</p><h1 className="mt-1 text-2xl font-semibold tracking-tight sm:text-3xl">Tiket servis baru</h1><p className="mt-1 text-sm text-muted-foreground">Catat informasi yang diketahui saat perangkat diterima.</p></div><CreateTicketForm branches={branches} /></div>;
}
