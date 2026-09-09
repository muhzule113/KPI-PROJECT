export const TICKET_TRANSITIONS: Record<string, readonly string[]> = {
  INTAKE: ["DIAGNOSING", "CANCELLED"],
  DIAGNOSING: ["WAITING_CONSENT", "WAITING_SPAREPART", "IN_PROGRESS", "COMPLETED"],
  WAITING_CONSENT: ["IN_PROGRESS", "COMPLETED"],
  WAITING_SPAREPART: ["IN_PROGRESS", "COMPLETED"],
  IN_PROGRESS: ["WAITING_SPAREPART", "QC_READY", "COMPLETED"],
  QC_READY: ["IN_PROGRESS", "COMPLETED"],
  COMPLETED: ["DELIVERED"],
  CANCELLED_UNREPAIRABLE: [],
  CANCELLED: [],
  DELIVERED: [],
};

export const SERVICE_SLA_WORKDAYS = { light: 1, medium: 3, heavy: 7 } as const;

export function addWeekdays(start: Date, days: number) {
  const result = new Date(start);
  for (let added = 0; added < days;) {
    result.setUTCDate(result.getUTCDate() + 1);
    if (![0, 6].includes(result.getUTCDay())) added += 1;
  }
  return result;
}

export function totalSparepartWaitMinutes(intervals: Array<{ requestedAt: Date; confirmedAt: Date }>) {
  const sorted = [...intervals].sort((left, right) => left.requestedAt.valueOf() - right.requestedAt.valueOf());
  let total = 0;
  let coveredUntil = 0;
  for (const interval of sorted) {
    const start = Math.max(interval.requestedAt.valueOf(), coveredUntil);
    const end = interval.confirmedAt.valueOf();
    if (end > start) total += end - start;
    coveredUntil = Math.max(coveredUntil, end);
  }
  return Math.floor(total / 60_000);
}

const CAPABILITIES: Record<string, string> = {
  "INTAKE:DIAGNOSING": "tickets.claim",
  "INTAKE:CANCELLED": "tickets.create",
  "DIAGNOSING:WAITING_CONSENT": "tickets.progress",
  "DIAGNOSING:WAITING_SPAREPART": "tickets.progress",
  "DIAGNOSING:IN_PROGRESS": "tickets.progress",
  "WAITING_CONSENT:IN_PROGRESS": "tickets.progress",
  "WAITING_SPAREPART:IN_PROGRESS": "tickets.progress",
  "IN_PROGRESS:WAITING_SPAREPART": "tickets.progress",
  "IN_PROGRESS:QC_READY": "tickets.complete",
  "QC_READY:IN_PROGRESS": "tickets.supervise",
  "QC_READY:COMPLETED": "tickets.supervise",
  "COMPLETED:DELIVERED": "tickets.deliver",
};

export function assertTicketTransition(current: string, next: string) {
  if (!(TICKET_TRANSITIONS[current] ?? []).includes(next)) throw new Error(`Status servis '${current}' tidak dapat diubah menjadi '${next}'.`);
}

export function assertTechnicalEvidenceCanBeAdded(status: string) {
  if (["COMPLETED", "DELIVERED", "CANCELLED", "CANCELLED_UNREPAIRABLE"].includes(status)) {
    throw new Error("Evidence teknis tidak dapat ditambahkan setelah tiket final.");
  }
}

export function capabilityForTransition(current: string, next: string) {
  return CAPABILITIES[`${current}:${next}`] ?? "tickets.manage";
}

export function assertTicketTransitionPrerequisites(next: string, ticket: {
  diagnosisNotes: string | null;
  actionNotes: string | null;
  customerConsentStatus: string;
  hasPendingSparepart: boolean;
  hasUnconfirmedSparepart: boolean;
}) {
  if (["COMPLETED", "DELIVERED"].includes(next)) throw new Error("Gunakan formulir penyelesaian atau penyerahan agar data wajib tidak terlewati.");
  if (next === "WAITING_CONSENT" && !ticket.diagnosisNotes?.trim()) throw new Error("Diagnosis wajib disimpan sebelum meminta persetujuan pelanggan.");
  if (next === "WAITING_SPAREPART" && !ticket.hasPendingSparepart) throw new Error("Buat permintaan sparepart resmi terlebih dahulu.");
  if (["IN_PROGRESS", "QC_READY"].includes(next) && ticket.customerConsentStatus !== "approved") throw new Error("Persetujuan pelanggan wajib disetujui sebelum pengerjaan.");
  if (["IN_PROGRESS", "QC_READY"].includes(next) && (ticket.hasPendingSparepart || ticket.hasUnconfirmedSparepart)) throw new Error("Semua permintaan sparepart harus dipenuhi dan dikonfirmasi terlebih dahulu.");
  if (next === "QC_READY" && (!ticket.diagnosisNotes?.trim() || !ticket.actionNotes?.trim())) throw new Error("Diagnosis dan tindakan wajib lengkap sebelum QC.");
}
