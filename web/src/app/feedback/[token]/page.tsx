import { CheckCircleIcon, StorefrontIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import { notFound } from "next/navigation";
import { CustomerFeedbackForm } from "@/components/tickets/customer-feedback-form";
import { Card, CardContent } from "@/components/ui/card";
import { prisma } from "@/lib/prisma";
import { verifyFeedbackToken } from "@/modules/tickets/feedback-token";

export const metadata: Metadata = { title: "Feedback servis" };

export default async function PublicFeedbackPage({ params }: { params: Promise<{ token: string }> }) {
  const { token } = await params;
  const ticketId = verifyFeedbackToken(token);
  if (!ticketId) notFound();
  const ticket = await prisma.serviceTicket.findUnique({ where: { id: ticketId }, include: { branch: { select: { name: true } }, intakeBy: { select: { name: true } }, technician: { select: { name: true } }, feedback: true } });
  if (!ticket || ticket.status !== "DELIVERED" || !ticket.deliveredAt || new Date() > new Date(ticket.deliveredAt.valueOf() + 7 * 86_400_000)) notFound();
  return <main className="grid min-h-dvh place-items-center px-4 py-10"><div className="w-full max-w-lg"><div className="mb-6 flex items-center justify-center gap-3"><span className="grid size-11 place-items-center rounded-xl bg-primary text-primary-foreground"><StorefrontIcon size={24} weight="duotone" /></span><div><p className="font-bold tracking-wide">KPI OPS</p><p className="text-xs text-muted-foreground">{ticket.branch.name}</p></div></div><Card><CardContent className="p-6 sm:p-8"><p className="font-mono text-xs font-semibold text-primary">{ticket.ticketNumber}</p><h1 className="mt-2 text-2xl font-semibold tracking-tight">Bagaimana layanan kami?</h1><p className="mt-2 text-sm leading-6 text-muted-foreground">{ticket.deviceBrand} {ticket.deviceModel} milik {ticket.customerName}. Rating Pelayan dan Teknisi dicatat terpisah.</p>{ticket.feedback ? <div className="mt-7 rounded-xl bg-accent p-5 text-center"><CheckCircleIcon className="mx-auto text-primary" size={34} weight="fill" /><p className="mt-3 font-semibold">Feedback sudah diterima</p><p className="mt-1 text-sm text-muted-foreground">Terima kasih sudah membantu kami memperbaiki layanan.</p></div> : <div className="mt-7"><CustomerFeedbackForm token={token} hasTechnician={Boolean(ticket.technicianEmployeeId)} /></div>}</CardContent></Card></div></main>;
}
