"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { syncEmployeeOperationalKpis } from "@/modules/kpi/operational-sync";
import { addWeekdays } from "@/modules/tickets/workflow";
import { verifyFeedbackToken } from "@/modules/tickets/feedback-token";

export type CustomerFeedbackState = { error?: string; success?: string };

const optionalRating = z.preprocess((value) => value === "" ? undefined : value, z.coerce.number().int().min(1).max(5).optional());
const schema = z.object({ token: z.string().min(1).max(500), rating: z.coerce.number().int().min(1).max(5), technicianRating: optionalRating, comments: z.string().trim().max(2000).optional() });

export async function submitCustomerFeedback(_: CustomerFeedbackState, formData: FormData): Promise<CustomerFeedbackState> {
  const parsed = schema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Pilih rating 1–5 dan periksa panjang komentar." };
  const ticketId = verifyFeedbackToken(parsed.data.token);
  if (!ticketId) return { error: "Tautan feedback tidak valid atau sudah kedaluwarsa." };
  try {
    await prisma.$transaction(async (tx) => {
      await tx.$queryRaw`SELECT id FROM service_tickets WHERE id = ${ticketId} FOR UPDATE`;
      const ticket = await tx.serviceTicket.findUnique({ where: { id: ticketId }, include: { feedback: true, intakeBy: { include: { position: true } } } });
      if (!ticket || ticket.status !== "DELIVERED" || !ticket.deliveredAt) throw new Error("Feedback belum tersedia untuk tiket ini.");
      if (ticket.feedback) throw new Error("Feedback untuk tiket ini sudah pernah dikirim.");
      if (Date.now() > ticket.deliveredAt.valueOf() + 7 * 86_400_000) throw new Error("Masa pengisian feedback tujuh hari sudah berakhir.");
      if (ticket.technicianEmployeeId && parsed.data.technicianRating === undefined) throw new Error("Rating Teknisi wajib dipilih.");
      if (!ticket.technicianEmployeeId && parsed.data.technicianRating !== undefined) throw new Error("Tiket ini tidak memiliki Teknisi yang dapat dinilai.");
      const feedback = await tx.customerFeedback.create({ data: { serviceTicketId: ticket.id, csEmployeeId: ticket.intakeByEmployeeId, technicianEmployeeId: ticket.technicianEmployeeId, customerName: ticket.customerName, rating: parsed.data.rating, technicianRating: parsed.data.technicianRating, comments: parsed.data.comments || null, feedbackChannel: "signed_web_link" } });
      const needsFollowUp = feedback.rating <= 2 || feedback.technicianRating !== null && feedback.technicianRating <= 2;
      if (needsFollowUp) {
        const assignee = ticket.intakeBy?.status === "ACTIVE" && ticket.intakeBy.position.code === "POS-CS" ? ticket.intakeBy : null;
        const followUp = await tx.feedbackFollowUp.create({ data: { customerFeedbackId: feedback.id, serviceTicketId: ticket.id, assignedEmployeeId: assignee?.id, status: assignee ? "pending" : "exception", dueAt: assignee ? addWeekdays(feedback.createdAt, 1) : null, responseSummary: assignee ? null : "Tidak ada Pelayan aktif untuk menerima tugas follow-up." } });
        if (assignee?.userId) await tx.systemNotification.create({ data: { userId: assignee.userId, title: "Feedback perlu ditindaklanjuti", body: "Ada feedback layanan rendah yang perlu dihubungi sebelum deadline.", type: "feedback_followup_created", entityType: "FeedbackFollowUp", entityId: followUp.id, actionUrl: "/app/feedback", dedupeKey: `feedback-followup:${followUp.id}:${assignee.userId}` } });
        await tx.auditEvent.create({ data: { actorType: "system", action: "create_feedback_follow_up", subjectType: "FeedbackFollowUp", subjectId: followUp.id, afterJson: { status: followUp.status, assignedEmployeeId: followUp.assignedEmployeeId, dueAt: followUp.dueAt } } });
      }
      await tx.auditEvent.create({ data: { actorType: "customer", action: "create_customer_feedback", subjectType: "CustomerFeedback", subjectId: feedback.id, afterJson: { serviceTicketId: ticket.id, rating: feedback.rating, technicianRating: feedback.technicianRating, feedbackChannel: feedback.feedbackChannel } } });
      if (ticket.intakeByEmployeeId) await syncEmployeeOperationalKpis(tx, ticket.intakeByEmployeeId, ticket.deliveredAt, undefined);
    });
  } catch (error) {
    return { error: error instanceof Error && !error.message.toLowerCase().includes("prisma") ? error.message : "Feedback tidak dapat disimpan. Coba lagi." };
  }
  revalidatePath(`/feedback/${parsed.data.token}`);
  revalidatePath("/app/feedback");
  return { success: "Terima kasih. Feedback Anda sudah tersimpan." };
}
