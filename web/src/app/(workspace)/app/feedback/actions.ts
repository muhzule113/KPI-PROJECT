"use server";

import { revalidatePath } from "next/cache";
import { z } from "zod";
import { prisma } from "@/lib/prisma";
import { hasCapability } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";
import { canAccessTicket } from "@/modules/access/scope";
import { syncEmployeeOperationalKpis } from "@/modules/kpi/operational-sync";
import { addWeekdays } from "@/modules/tickets/workflow";

export type FollowUpActionState = { error?: string; success?: string };

const schema = z.object({
  followUpId: z.string().min(1),
  rowVersion: z.coerce.number().int().positive(),
  status: z.enum(["contacted", "completed", "no_response", "escalated", "exception"]),
  contactChannel: z.string().trim().max(30).optional(),
  outcome: z.string().trim().min(2).max(30),
  responseSummary: z.string().trim().min(3).max(2000),
  evidenceType: z.string().trim().max(30).optional(),
  evidenceReference: z.string().trim().max(500).optional(),
  assignedEmployeeId: z.string().optional(),
  assignmentReason: z.string().trim().max(1000).optional(),
});

export async function updateFeedbackFollowUp(_: FollowUpActionState, formData: FormData): Promise<FollowUpActionState> {
  const user = await requireUser();
  if (!hasCapability(user, "feedback.followup.manage")) return { error: "Anda tidak berwenang menangani follow-up." };
  const parsed = schema.safeParse(Object.fromEntries(formData));
  if (!parsed.success) return { error: "Status, hasil, dan ringkasan follow-up wajib diisi dengan benar." };
  const data = parsed.data;
  if (["contacted", "completed"].includes(data.status) && !data.contactChannel) return { error: "Kanal kontak wajib dipilih." };
  if (Boolean(data.evidenceType) !== Boolean(data.evidenceReference)) return { error: "Jenis dan referensi evidence harus diisi berpasangan." };
  if (data.status === "completed" && !data.evidenceReference) return { error: "Follow-up selesai wajib memiliki evidence." };
  try {
    await prisma.$transaction(async (tx) => {
      await tx.$queryRaw`SELECT id FROM feedback_follow_ups WHERE id = ${data.followUpId} FOR UPDATE`;
      const followUp = await tx.feedbackFollowUp.findUnique({ where: { id: data.followUpId }, include: { feedback: true, ticket: { include: { intakeBy: { select: { supervisorId: true } }, technician: { select: { supervisorId: true } } } } } });
      if (!followUp || !canAccessTicket(user, followUp.ticket)) throw new Error("Tugas follow-up tidak ditemukan dalam cakupan Anda.");
      const managerial = ["owner_manager", "supervisor", "super_admin"].includes(user.role);
      if (!managerial && followUp.assignedEmployeeId !== user.employee?.id) throw new Error("Tugas follow-up bukan tanggung jawab Anda.");
      if (followUp.status === "completed" && !["owner_manager", "super_admin"].includes(user.role)) throw new Error("Follow-up yang selesai hanya dapat dikoreksi Manager.");
      if (data.status === "no_response" && (!followUp.dueAt || new Date() < addWeekdays(followUp.dueAt, 2))) throw new Error("Status tidak terhubung baru boleh dipakai dua hari kerja setelah deadline.");

      let assignedEmployeeId = followUp.assignedEmployeeId;
      if (data.assignedEmployeeId && data.assignedEmployeeId !== followUp.assignedEmployeeId) {
        if (!managerial || !data.assignmentReason || data.assignmentReason.length < 3) throw new Error("Delegasi hanya dapat dilakukan Supervisor/Manager dan wajib disertai alasan.");
        const assignee = await tx.employee.findFirst({ where: { id: data.assignedEmployeeId, branchId: followUp.ticket.branchId, status: "ACTIVE", position: { code: "POS-CS" } }, select: { id: true, userId: true } });
        if (!assignee) throw new Error("Delegasi harus diberikan kepada Pelayan aktif di cabang yang sama.");
        assignedEmployeeId = assignee.id;
        if (assignee.userId) await tx.systemNotification.create({ data: { userId: assignee.userId, title: "Tugas follow-up feedback", body: "Anda menerima tugas follow-up layanan pelanggan.", type: "feedback_followup_assigned", entityType: "FeedbackFollowUp", entityId: followUp.id, actionUrl: "/app/feedback", dedupeKey: `feedback-followup-assigned:${followUp.id}:${assignee.id}:${followUp.rowVersion + 1}` } });
      }

      const now = new Date();
      const evidence = data.evidenceReference ? [{ type: data.evidenceType!, reference: data.evidenceReference, recorded_at: now.toISOString(), recorded_by_user_id: user.id }] : undefined;
      const updated = await tx.feedbackFollowUp.updateMany({ where: { id: followUp.id, rowVersion: data.rowVersion }, data: { assignedEmployeeId, assignedByUserId: assignedEmployeeId !== followUp.assignedEmployeeId ? user.id : undefined, status: data.status, contactChannel: data.contactChannel || null, outcome: data.outcome, responseSummary: data.responseSummary, firstContactedAt: ["contacted", "completed"].includes(data.status) ? followUp.firstContactedAt ?? now : followUp.firstContactedAt, completedAt: data.status === "completed" ? now : null, completedByUserId: data.status === "completed" ? user.id : null, evidenceJson: evidence, rowVersion: { increment: 1 } } });
      if (updated.count !== 1) throw new Error("Tugas follow-up telah berubah. Muat ulang halaman.");
      const occurredAt = followUp.ticket.deliveredAt ?? followUp.feedback.createdAt;
      for (const employeeId of new Set([followUp.assignedEmployeeId, assignedEmployeeId].filter((id): id is string => Boolean(id)))) await syncEmployeeOperationalKpis(tx, employeeId, occurredAt, user.id);
      await tx.auditEvent.create({ data: { actorId: user.id, action: "update_feedback_follow_up", subjectType: "FeedbackFollowUp", subjectId: followUp.id, beforeJson: { status: followUp.status, assignedEmployeeId: followUp.assignedEmployeeId, rowVersion: followUp.rowVersion }, afterJson: { status: data.status, assignedEmployeeId, rowVersion: followUp.rowVersion + 1, evidence }, reason: data.assignmentReason || data.responseSummary } });
    });
  } catch (error) {
    return { error: error instanceof Error && !error.message.toLowerCase().includes("prisma") ? error.message : "Follow-up tidak dapat disimpan. Muat ulang lalu coba lagi." };
  }
  revalidatePath("/app/feedback");
  return { success: "Follow-up tersimpan dan KPI Pelayan diperbarui." };
}
