import { Prisma, type KpiItemStatus } from "@/generated/prisma/client";
import { recalculateKpi } from "@/modules/kpi/recalculate";
import {
  calculateAttendanceRate,
  calculateWorkLogMetrics,
  EXCUSED_ATTENDANCE_STATUSES,
  sameSystemDetails,
  WORKED_ATTENDANCE_STATUSES,
} from "@/modules/kpi/operational-metrics";

const ATTENDANCE_CODES = new Set(["ADM-05", "KSR-06", "GUD-07", "CS-06"]);
const day = (value: Date) => value.toISOString().slice(0, 10);
const round = (value: number) => Math.round((value + Number.EPSILON) * 100) / 100;
const endExclusive = (value: Date) => new Date(value.valueOf() + 86_400_000);
const average = (values: number[]) => values.length ? round(values.reduce((sum, value) => sum + value, 0) / values.length) : null;
const jsonObject = (value: Prisma.JsonValue | null) => value && !Array.isArray(value) && typeof value === "object" ? value : null;
const hasJson = (value: Prisma.JsonValue | null) => Array.isArray(value) ? value.length > 0 : Boolean(value && typeof value === "object" && Object.keys(value).length);
const requiredQc = ["display", "touch", "charging", "speaker", "mic", "camera", "cellular", "biometric"];

function completeServiceReport(ticket: { diagnosisNotes: string | null; actionNotes: string | null; qcChecklistJson: Prisma.JsonValue | null; technicalEvidenceJson: Prisma.JsonValue | null; resultStatus: string }) {
  const qc = jsonObject(ticket.qcChecklistJson);
  const evidence = Array.isArray(ticket.technicalEvidenceJson) ? ticket.technicalEvidenceJson : [];
  const hasRequiredQc = Boolean(qc && requiredQc.every((key) => typeof qc[key] === "boolean"));
  const needsEvidence = ["success", "unrepairable"].includes(ticket.resultStatus);
  const hasCleanEvidence = evidence.some((entry) => {
    const item = jsonObject(entry);
    return Boolean(item && (!item.file_path || item.scan_status === "clean"));
  });
  return Boolean(ticket.diagnosisNotes && ticket.actionNotes && hasRequiredQc && (!needsEvidence || hasCleanEvidence));
}

async function setActual(
  tx: Prisma.TransactionClient,
  item: { id: string; employeeKpiId: string; actualDecimal: { toString(): string } | null; actualJson: Prisma.JsonValue | null; status: KpiItemStatus },
  value: number | null,
  source: string,
  details: Record<string, string | number | boolean | null>,
  actorId?: string,
) {
  const actualJson = { _system_calculated: true, source, ...details };
  const changed = (item.actualDecimal?.toString() ?? null) !== (value == null ? null : String(value)) || !sameSystemDetails(item.actualJson, actualJson);
  if (!changed) return false;
  const nextStatus = ["VERIFIED", "ASSESSED", "REVISION_REQUIRED"].includes(item.status) ? "DRAFT" : item.status === "NOT_STARTED" ? "DRAFT" : item.status;

  await tx.employeeKpiItem.update({
    where: { id: item.id },
    data: {
      actualDecimal: value,
      actualJson,
      status: nextStatus,
      managerDecision: null,
      managerNote: null,
      managerEvidenceJson: Prisma.JsonNull,
      managerDecidedById: null,
      managerDecidedAt: null,
      rowVersion: { increment: 1 },
    },
  });
  await tx.auditEvent.create({ data: {
    actorType: actorId ? "user" : "system",
    actorId,
    action: "sync_operational_kpi_fact",
    subjectType: "EmployeeKpiItem",
    subjectId: item.id,
    beforeJson: { actualDecimal: item.actualDecimal?.toString() ?? null, actualJson: item.actualJson, status: item.status },
    afterJson: { actualDecimal: value == null ? null : String(value), actualJson, status: nextStatus },
    reason: "Fakta operasional berubah; review item harus dilakukan ulang.",
  } });
  return true;
}

export async function syncEmployeeOperationalKpis(
  tx: Prisma.TransactionClient,
  employeeId: string,
  occurredAt: Date,
  actorId?: string,
  dailyDates: Date[] = [occurredAt],
) {
  const kpis = await tx.employeeKpi.findMany({
    where: {
      employeeId,
      status: { notIn: ["APPROVED", "LOCKED"] },
      period: {
        startDate: { lte: occurredAt },
        endDate: { gte: occurredAt },
        status: { notIn: ["PUBLISHED", "LOCKED", "CANCELLED"] },
      },
    },
    include: { items: true, period: true },
  });

  for (const kpi of kpis) {
    const [attendances, workLogs, complaints, teamSize, coachedEmployees] = await Promise.all([
      tx.attendance.findMany({
        where: { employeeId, attendanceDate: { gte: kpi.period.startDate, lte: kpi.period.endDate } },
        select: { attendanceDate: true, status: true },
      }),
      tx.adminWorkLog.findMany({
        where: { employeeId, workDate: { gte: kpi.period.startDate, lte: kpi.period.endDate } },
        select: {
          recordsInput: true,
          recordsCorrected: true,
          documentsEligible: true,
          documentsComplete: true,
          reconciliationsTotal: true,
          reconciliationsSuccess: true,
        },
      }),
      tx.complaint.findMany({
        where: { employeeId, complaintDate: { gte: kpi.period.startDate, lte: kpi.period.endDate }, description: { not: "" }, channel: { not: "" } },
        select: { id: true, serviceTicketId: true },
      }),
      tx.employeeKpi.count({ where: { periodId: kpi.periodId, supervisorIdSnapshot: employeeId, employeeId: { not: employeeId } } }),
      tx.coachingLog.findMany({
        where: { supervisorId: employeeId, coachingDate: { gte: kpi.period.startDate, lte: kpi.period.endDate } },
        select: { employeeId: true },
        distinct: ["employeeId"],
      }),
    ]);
    const complaintCount = new Set(complaints.map((complaint) => complaint.serviceTicketId ? `ticket:${complaint.serviceTicketId}` : `complaint:${complaint.id}`)).size;
    const values: Record<string, { value: number | null; source: string; details: Record<string, string | number | boolean | null> }> = {};
    const attendanceRate = calculateAttendanceRate(attendances);
    const workLogMetrics = calculateWorkLogMetrics(workLogs);
    for (const code of ATTENDANCE_CODES) values[code] = { value: attendanceRate, source: "attendance", details: { recorded_days: attendances.length } };
    for (const [code, value] of Object.entries(workLogMetrics)) values[code] = { value, source: "admin_work_log", details: { recorded_days: workLogs.length } };
    values["CS-05"] = { value: complaintCount, source: "complaint", details: { valid_complaints: complaintCount } };
    values["SUP-05"] = { value: teamSize ? round(coachedEmployees.length / teamSize * 100) : null, source: "coaching_log", details: { coached_employees: coachedEmployees.length, team_size: teamSize } };

    const periodEnd = endExclusive(kpi.period.endDate);
    if (kpi.positionCodeSnapshot === "POS-TEK") {
      const [tickets, warrantyRows] = await Promise.all([
        tx.serviceTicket.findMany({ where: { technicianEmployeeId: employeeId, completedAt: { gte: kpi.period.startDate, lt: periodEnd }, status: { in: ["COMPLETED", "DELIVERED"] } } }),
        tx.serviceTicket.findMany({ where: { isWarrantyReturn: true, warrantyReturnedFromTicketId: { not: null }, warrantyReviewStatus: "approved", createdAt: { gte: kpi.period.startDate, lt: periodEnd }, warrantyReturnedFrom: { technicianEmployeeId: employeeId } }, select: { warrantyReturnedFromTicketId: true } }),
      ]);
      const total = tickets.length;
      const eligibleStatuses = new Set(["success", "unrepairable", "customer_declined", "warranty_return"]);
      const unclassified = tickets.filter((ticket) => !eligibleStatuses.has(ticket.resultStatus)).length;
      const returns = new Set(warrantyRows.map((row) => row.warrantyReturnedFromTicketId).filter(Boolean)).size;
      const timed = tickets.filter((ticket) => ticket.completedAt && (ticket.slaDueAt || ticket.estimatedCompletionAt));
      const ontime = timed.filter((ticket) => ticket.completedAt! <= (ticket.slaDueAt ?? ticket.estimatedCompletionAt)!).length;
      const completeReports = tickets.filter(completeServiceReport).length;
      values["TEK-01"] = { value: total || null, source: "service_ticket", details: { completed_tickets: total } };
      values["TEK-02"] = { value: total && !unclassified ? round(tickets.filter((ticket) => ticket.resultStatus === "success").length / total * 100) : null, source: "service_ticket", details: { completed_tickets: total, unclassified_tickets: unclassified } };
      values["TEK-03"] = { value: total && !unclassified ? round(returns / total * 100) : null, source: "warranty_return", details: { completed_tickets: total, warranty_returns: returns, unclassified_tickets: unclassified } };
      values["TEK-04"] = { value: timed.length ? round(ontime / timed.length * 100) : null, source: "service_ticket", details: { timed_tickets: timed.length, ontime_tickets: ontime } };
      values["TEK-07"] = { value: total ? round(completeReports / total * 100) : null, source: "service_ticket", details: { completed_tickets: total, complete_reports: completeReports } };
    }

    if (kpi.positionCodeSnapshot === "POS-CS") {
      const [feedbackRows, intakeTickets] = await Promise.all([
        tx.customerFeedback.findMany({ where: { csEmployeeId: employeeId, ticket: { status: "DELIVERED", deliveredAt: { gte: kpi.period.startDate, lt: periodEnd }, OR: [{ periodId: kpi.periodId }, { periodId: null }] } }, include: { ticket: { select: { deliveredAt: true } } } }),
        tx.serviceTicket.findMany({ where: { intakeByEmployeeId: employeeId, createdAt: { gte: kpi.period.startDate, lt: periodEnd }, OR: [{ periodId: kpi.periodId }, { periodId: null }] } }),
      ]);
      const feedbacks = feedbackRows.filter((feedback) => feedback.ticket.deliveredAt && feedback.createdAt <= new Date(feedback.ticket.deliveredAt.valueOf() + 7 * 86_400_000));
      const followUps = feedbacks.length ? await tx.feedbackFollowUp.findMany({ where: { assignedEmployeeId: employeeId, customerFeedbackId: { in: feedbacks.map((feedback) => feedback.id) }, status: { not: "exception" } } }) : [];
      const satisfied = feedbacks.filter((feedback) => feedback.rating >= 4).length;
      const validIntake = intakeTickets.filter((ticket) => ticket.customerName && ticket.customerPhone && ticket.deviceBrand && ticket.deviceModel && ticket.initialComplaint && ticket.serviceCategory && ticket.serviceComplexity).length;
      const ontimeFollowUps = followUps.filter((followUp) => followUp.status === "completed" && followUp.completedAt && followUp.dueAt && followUp.completedAt <= followUp.dueAt && hasJson(followUp.evidenceJson)).length;
      values["CS-01"] = { value: feedbacks.length ? round(satisfied / feedbacks.length * 100) : null, source: "customer_feedback", details: { feedbacks: feedbacks.length, satisfied_feedbacks: satisfied } };
      values["CS-03"] = { value: intakeTickets.length ? round(validIntake / intakeTickets.length * 100) : null, source: "service_ticket", details: { intake_tickets: intakeTickets.length, valid_tickets: validIntake } };
      values["CS-04"] = { value: followUps.length ? round(ontimeFollowUps / followUps.length * 100) : null, source: "feedback_follow_up", details: { assigned_followups: followUps.length, ontime_followups: ontimeFollowUps } };
    }

    if (kpi.positionCodeSnapshot === "POS-GUD") {
      const [requests, criticalParts, opnames] = await Promise.all([
        tx.sparepartRequest.findMany({ where: { warehouseEmployeeId: employeeId, createdAt: { gte: kpi.period.startDate, lt: periodEnd }, ticket: { branchId: kpi.branchIdSnapshot, OR: [{ periodId: kpi.periodId }, { periodId: null }] } } }),
        tx.sparepart.findMany({ where: { branchId: kpi.branchIdSnapshot, isCritical: true }, select: { stockQuantity: true } }),
        tx.stockOpname.findMany({ where: { periodId: kpi.periodId, branchId: kpi.branchIdSnapshot }, include: { items: true }, orderBy: { completedAt: "desc" } }),
      ]);
      const fulfilled = requests.filter((request) => request.status === "fulfilled");
      const fast = fulfilled.filter((request) => request.fulfilledAt && request.fulfilledAt >= request.requestedAt && request.fulfilledAt.valueOf() - request.requestedAt.valueOf() <= 15 * 60_000).length;
      const completed = opnames.filter((opname) => opname.status === "completed");
      const counted = completed[0]?.items.filter((item) => item.isCounted) ?? [];
      const totalSystem = counted.reduce((sum, item) => sum + item.systemStock, 0);
      const totalDifference = counted.reduce((sum, item) => sum + Math.abs(item.difference), 0);
      values["GUD-01"] = { value: counted.length ? round(counted.filter((item) => item.difference === 0).length / counted.length * 100) : null, source: "stock_opname", details: { counted_items: counted.length } };
      values["GUD-02"] = { value: counted.length && totalSystem > 0 ? round(totalDifference / totalSystem * 100) : null, source: "stock_opname", details: { total_system_stock: totalSystem, absolute_difference: totalDifference } };
      values["GUD-03"] = { value: fulfilled.length ? round(fast / fulfilled.length * 100) : null, source: "sparepart_request", details: { fulfilled_requests: fulfilled.length, within_15_minutes: fast } };
      values["GUD-04"] = { value: criticalParts.length ? round(criticalParts.filter((part) => part.stockQuantity > 0).length / criticalParts.length * 100) : null, source: "sparepart", details: { critical_parts: criticalParts.length, in_stock: criticalParts.filter((part) => part.stockQuantity > 0).length } };
      values["GUD-05"] = { value: opnames.length ? round(completed.length / opnames.length * 100) : null, source: "stock_opname", details: { total_opnames: opnames.length, completed_opnames: completed.length } };
    }

    if (kpi.positionCodeSnapshot === "POS-KSR") {
      const transactions = await tx.cashierTransaction.findMany({ where: { periodId: kpi.periodId, cashierEmployeeId: employeeId, isDuplicate: false, status: { not: "SUPERSEDED" }, batch: { status: { in: ["CONFIRMED", "COMPLETED", "COMPLETED_WITH_WARNINGS"] } } }, include: { batch: { select: { id: true, confirmedAt: true } } } });
      const successful = transactions.filter((transaction) => transaction.status === "SUCCESS").length;
      const timed = transactions.filter((transaction) => transaction.durationSeconds != null);
      const batchMap = new Map(transactions.map((transaction) => [transaction.importBatchId, transaction.batch]));
      const ontimeBatches = [...batchMap.values()].filter((batch) => batch.confirmedAt && batch.confirmedAt <= kpi.period.submissionDeadline).length;
      const difference = transactions.reduce((sum, transaction) => sum + Math.abs(Number(transaction.actualCashAmount) - Number(transaction.systemCashAmount)), 0);
      values["KSR-01"] = { value: transactions.length ? round(successful / transactions.length * 100) : null, source: "cashier_import", details: { transactions: transactions.length, successful_transactions: successful } };
      values["KSR-02"] = { value: transactions.length ? round(difference) : null, source: "cashier_import", details: { transactions: transactions.length, absolute_cash_difference: round(difference) } };
      values["KSR-03"] = { value: batchMap.size ? round(ontimeBatches / batchMap.size * 100) : null, source: "cashier_import", details: { batches: batchMap.size, ontime_batches: ontimeBatches } };
      values["KSR-04"] = { value: timed.length ? round(timed.filter((transaction) => transaction.durationSeconds! <= 180).length / timed.length * 100) : null, source: "cashier_import", details: { timed_transactions: timed.length, within_sla: timed.filter((transaction) => transaction.durationSeconds! <= 180).length } };
    }

    if (kpi.positionCodeSnapshot === "POS-SPV") {
      const teamKpis = await tx.employeeKpi.findMany({ where: { periodId: kpi.periodId, supervisorIdSnapshot: employeeId, employeeId: { not: employeeId } }, include: { items: true } });
      const teamIds = teamKpis.map((teamKpi) => teamKpi.employeeId);
      const complete = teamKpis.length > 0 && teamKpis.every((teamKpi) => ["APPROVED", "LOCKED"].includes(teamKpi.status) && teamKpi.finalScore != null);
      const achievements = teamKpis.flatMap((teamKpi) => teamKpi.items.flatMap((item) => item.achievementPercentage == null ? [] : [Number(item.achievementPercentage)]));
      const teamAttendance = await Promise.all(teamIds.map(async (id) => calculateAttendanceRate(await tx.attendance.findMany({ where: { employeeId: id, attendanceDate: { gte: kpi.period.startDate, lte: kpi.period.endDate } }, select: { attendanceDate: true, status: true } }))));
      const teamComplaints = teamIds.length ? await tx.complaint.findMany({ where: { employeeId: { in: teamIds }, complaintDate: { gte: kpi.period.startDate, lte: kpi.period.endDate } } }) : [];
      const resolvedOntime = teamComplaints.filter((complaint) => complaint.status === "resolved" && complaint.resolvedAt && complaint.slaDeadline && complaint.resolvedAt <= complaint.slaDeadline).length;
      values["SUP-01"] = { value: complete ? average(teamKpis.map((teamKpi) => Number(teamKpi.finalScore))) : null, source: "approved_team_kpis", details: { team_kpis: teamKpis.length, completed_team_kpis: teamKpis.filter((teamKpi) => ["APPROVED", "LOCKED"].includes(teamKpi.status)).length } };
      values["SUP-02"] = { value: complete ? average(achievements) : null, source: "approved_team_kpis", details: { team_items: achievements.length } };
      values["SUP-03"] = { value: complete ? average(teamAttendance.filter((rate): rate is number => rate != null)) : null, source: "approved_team_kpis", details: { team_members_with_attendance: teamAttendance.filter((rate) => rate != null).length } };
      values["SUP-04"] = { value: teamComplaints.length ? round(resolvedOntime / teamComplaints.length * 100) : null, source: "complaint", details: { team_complaints: teamComplaints.length, resolved_ontime: resolvedOntime } };
    }

    if (["POS-ADM", "POS-SPV"].includes(kpi.positionCodeSnapshot)) {
      const submissions = await tx.reportSubmission.findMany({ where: { periodId: kpi.periodId, employeeId } });
      if (kpi.positionCodeSnapshot === "POS-ADM") {
        const daily = submissions.filter((row) => row.reportType === "admin_daily" && (row.submittedAt || row.deadlineAt <= new Date()));
        const monthly = submissions.find((row) => row.reportType === "admin_monthly");
        const parts: Array<[number, number]> = [];
        if (daily.length) parts.push([70, daily.filter((row) => row.isOnTime).length / daily.length * 100]);
        if (monthly && (monthly.submittedAt || monthly.deadlineAt <= new Date())) parts.push([30, monthly.isOnTime ? 100 : 0]);
        const weight = parts.reduce((sum, part) => sum + part[0], 0);
        values["ADM-02"] = { value: weight ? round(parts.reduce((sum, part) => sum + part[0] * part[1], 0) / weight) : null, source: "report_submission", details: { eligible_reports: daily.length + (monthly ? 1 : 0), ontime_reports: submissions.filter((row) => row.isOnTime).length } };
      } else {
        const monthly = submissions.find((row) => row.reportType === "supervisor_monthly");
        values["SUP-07"] = { value: monthly && (monthly.submittedAt || monthly.deadlineAt <= new Date()) ? (monthly.isOnTime ? 100 : 0) : null, source: "report_submission", details: { scheduled_reports: submissions.length, submitted_reports: submissions.filter((row) => row.submittedAt).length } };
      }
    }

    let changed = false;
    for (const item of kpi.items) {
      const next = values[item.definitionCodeSnapshot];
      if (next && await setActual(tx, item, next.value, next.source, next.details, actorId)) changed = true;
    }

    for (const entryDate of dailyDates) {
      const attendance = attendances.find((record) => day(record.attendanceDate) === day(entryDate));
      for (const item of kpi.items.filter((row) => ATTENDANCE_CODES.has(row.definitionCodeSnapshot))) {
        const existing = await tx.kpiDailyEntry.findUnique({ where: { employeeKpiItemId_entryDate: { employeeKpiItemId: item.id, entryDate } } });
        const dailyValue = attendance ? (WORKED_ATTENDANCE_STATUSES.has(attendance.status) ? 100 : EXCUSED_ATTENDANCE_STATUSES.has(attendance.status) ? null : 0) : null;
        const priorStatus = existing?.systemActualJson && !Array.isArray(existing.systemActualJson) && typeof existing.systemActualJson === "object" ? existing.systemActualJson.attendance_status : null;
        if ((existing?.systemActualDecimal?.toString() ?? null) === (dailyValue == null ? null : String(dailyValue)) && priorStatus === (attendance?.status ?? "not_recorded")) continue;
        await tx.kpiDailyEntry.upsert({
          where: { employeeKpiItemId_entryDate: { employeeKpiItemId: item.id, entryDate } },
          create: { employeeKpiItemId: item.id, entryDate, systemActualDecimal: dailyValue, systemActualJson: { attendance_status: attendance?.status ?? "not_recorded", excluded_from_ratio: Boolean(attendance && EXCUSED_ATTENDANCE_STATUSES.has(attendance.status)) }, entryStatus: "submitted" },
          update: { systemActualDecimal: dailyValue, systemActualJson: { attendance_status: attendance?.status ?? "not_recorded", excluded_from_ratio: Boolean(attendance && EXCUSED_ATTENDANCE_STATUSES.has(attendance.status)) }, entryStatus: "submitted", supervisorStatus: "PENDING", supervisorActualDecimal: null, supervisorActualJson: Prisma.JsonNull, supervisorAnswersJson: Prisma.JsonNull, supervisorScorePercentage: null, supervisorNote: null, supervisorAssessedById: null, supervisorAssessedAt: null, managerStatus: "PENDING", managerActualDecimal: null, managerActualJson: Prisma.JsonNull, managerAnswersJson: Prisma.JsonNull, managerScorePercentage: null, managerNote: null, managerAssessedById: null, managerAssessedAt: null, rowVersion: { increment: 1 } },
        });
        changed = true;
      }
    }

    if (changed) {
      if (["VERIFIED", "PENDING_APPROVAL"].includes(kpi.status)) {
        await tx.employeeKpi.update({ where: { id: kpi.id }, data: { status: "UNDER_REVIEW", verifiedAt: null, rowVersion: { increment: 1 } } });
      }
      await recalculateKpi(tx, kpi.id, "operational_sync", actorId);
    }
  }
}
