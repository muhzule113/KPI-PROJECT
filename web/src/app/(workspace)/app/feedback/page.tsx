import { ChatCircleDotsIcon, ClockIcon, StarIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { EmptyState } from "@/components/empty-state";
import { PageHeading } from "@/components/page-heading";
import { StatusBadge } from "@/components/status-badge";
import { FeedbackFollowUpForm } from "@/components/tickets/feedback-followup-form";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { formatDate } from "@/lib/format";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { ticketScopeFor } from "@/modules/access/scope";

export const metadata: Metadata = { title: "Feedback pelanggan" };

export default async function FeedbackPage() {
  const user = await requireUser();
  if (!hasCapability(user, "feedback.view")) redirect("/app");
  const ticketScope = ticketScopeFor(user);
  const sevenDaysAgo = new Date(new Date().valueOf() - 7 * 86_400_000);
  const managerial = ["owner_manager", "supervisor", "super_admin"].includes(user.role);
  const [feedbacks, followUps, pendingTickets, assignees] = await Promise.all([
    prisma.customerFeedback.findMany({ where: { ticket: ticketScope }, orderBy: { createdAt: "desc" }, take: 100, include: { ticket: { select: { id: true, ticketNumber: true } }, csEmployee: { select: { name: true } }, technicianEmployee: { select: { name: true } } } }),
    prisma.feedbackFollowUp.findMany({ where: { ticket: ticketScope, assignedEmployeeId: managerial ? undefined : user.employee?.id ?? "__no_access__" }, orderBy: [{ completedAt: "asc" }, { dueAt: "asc" }], take: 50, include: { ticket: { select: { id: true, ticketNumber: true, customerName: true } }, feedback: true, assignedEmployee: { select: { name: true } } } }),
    prisma.serviceTicket.count({ where: { ...ticketScope, status: "DELIVERED", feedback: { is: null }, deliveredAt: { gte: sevenDaysAgo } } }),
    managerial ? prisma.employee.findMany({ where: { status: "ACTIVE", branchId: user.role === "super_admin" ? undefined : user.employee?.branchId, position: { code: "POS-CS" } }, orderBy: { name: "asc" }, include: { branch: { select: { name: true } } } }) : Promise.resolve([]),
  ]);
  const average = feedbacks.length ? feedbacks.reduce((sum, feedback) => sum + feedback.rating, 0) / feedbacks.length : 0;
  const openFollowUps = followUps.filter((followUp) => followUp.status !== "completed").length;
  const assigneeOptions = assignees.map((employee) => ({ id: employee.id, label: `${employee.name} · ${employee.branch.name}` }));

  return <div className="space-y-7"><PageHeading eyebrow="Kualitas layanan" title="Feedback pelanggan" description="Rating Pelayan menjadi sumber CS-01; rating Teknisi disimpan terpisah untuk tindak lanjut." />
    <section className="grid grid-cols-3 gap-3"><Card><CardContent className="p-5"><ChatCircleDotsIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{feedbacks.length}</p><p className="text-xs text-muted-foreground">Feedback terbaru</p></CardContent></Card><Card><CardContent className="p-5"><StarIcon className="text-amber-500" size={24} weight="fill" /><p className="mt-4 text-2xl font-semibold">{average.toFixed(1)}</p><p className="text-xs text-muted-foreground">Rata-rata Pelayan</p></CardContent></Card><Card><CardContent className="p-5"><ClockIcon className="text-primary" size={24} weight="duotone" /><p className="mt-4 text-2xl font-semibold">{openFollowUps}</p><p className="text-xs text-muted-foreground">Follow-up aktif</p></CardContent></Card></section>
    {pendingTickets ? <div role="status" className="rounded-xl border border-amber-300 bg-amber-50 px-4 py-3 text-sm text-amber-950">{pendingTickets} tiket masih dapat menerima feedback. Buka rincian tiket untuk menyalin tautannya.</div> : null}
    <section className="space-y-4"><h2 className="text-base font-semibold">Tugas follow-up</h2>{followUps.length === 0 ? <EmptyState icon={ClockIcon} title="Tidak ada follow-up" description="Feedback dengan rating rendah akan otomatis membuat tugas di sini." /> : followUps.map((followUp) => <Card key={followUp.id}><CardHeader><div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"><div><CardTitle className="text-base">{followUp.ticket.ticketNumber} · {followUp.ticket.customerName}</CardTitle><p className="mt-1 text-xs text-muted-foreground">Ditugaskan ke {followUp.assignedEmployee?.name ?? "belum ada"}{followUp.dueAt ? ` · deadline ${formatDate(followUp.dueAt)}` : ""}</p></div><StatusBadge status={followUp.status} /></div></CardHeader><CardContent><div className="flex flex-wrap gap-2"><Badge variant="secondary">Pelayan {followUp.feedback.rating}/5</Badge>{followUp.feedback.technicianRating ? <Badge variant="secondary">Teknisi {followUp.feedback.technicianRating}/5</Badge> : null}<Button asChild size="sm" variant="link"><Link href={`/app/servis/${followUp.ticket.id}`}>Buka tiket</Link></Button></div>{followUp.status !== "completed" || ["owner_manager", "super_admin"].includes(user.role) ? <FeedbackFollowUpForm followUp={{ id: followUp.id, rowVersion: followUp.rowVersion, status: followUp.status, assignedEmployeeId: followUp.assignedEmployeeId, contactChannel: followUp.contactChannel, outcome: followUp.outcome, responseSummary: followUp.responseSummary }} assignees={assigneeOptions} canDelegate={managerial} /> : <p className="mt-4 text-sm text-muted-foreground">{followUp.responseSummary}</p>}</CardContent></Card>)}</section>
    <section className="space-y-4"><h2 className="text-base font-semibold">Feedback terbaru</h2>{feedbacks.length === 0 ? <EmptyState icon={ChatCircleDotsIcon} title="Belum ada feedback" description="Feedback pelanggan yang valid akan muncul di sini." /> : <div className="grid gap-4 lg:grid-cols-2">{feedbacks.map((feedback) => <Card key={feedback.id}><CardContent className="p-5"><div className="flex items-start justify-between gap-4"><div><p className="font-semibold">{feedback.ticket.ticketNumber}</p><p className="mt-1 text-xs text-muted-foreground">{formatDate(feedback.createdAt)} · Pelayan {feedback.csEmployee?.name ?? "tidak tercatat"}</p></div><Badge>{feedback.rating}/5</Badge></div>{feedback.technicianRating ? <p className="mt-3 text-xs text-muted-foreground">Teknisi {feedback.technicianEmployee?.name ?? "tidak tercatat"}: {feedback.technicianRating}/5</p> : null}{feedback.comments ? <p className="mt-3 text-sm leading-6">{feedback.comments}</p> : null}</CardContent></Card>)}</div>}</section>
  </div>;
}
