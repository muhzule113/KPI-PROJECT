export const PERIOD_TRANSITIONS = {
  DRAFT: ["READY", "CANCELLED"],
  READY: ["OPEN", "CANCELLED"],
  OPEN: ["SUBMISSION_CLOSED", "CANCELLED"],
  SUBMISSION_CLOSED: ["IN_REVIEW", "CANCELLED"],
  IN_REVIEW: ["WAITING_APPROVAL", "CANCELLED"],
  WAITING_APPROVAL: ["PUBLISHED", "CANCELLED"],
  PUBLISHED: ["LOCKED"],
  LOCKED: [],
  CANCELLED: [],
} as const;

export const KPI_TRANSITIONS = {
  DRAFT: ["SUBMITTED", "REVISION_REQUIRED"],
  SUBMITTED: ["UNDER_REVIEW", "PENDING_APPROVAL"],
  UNDER_REVIEW: ["REVISION_REQUIRED", "VERIFIED", "PENDING_APPROVAL"],
  REVISION_REQUIRED: ["SUBMITTED", "UNDER_REVIEW", "PENDING_APPROVAL"],
  VERIFIED: ["PENDING_APPROVAL"],
  PENDING_APPROVAL: ["APPROVED", "UNDER_REVIEW"],
  APPROVED: ["LOCKED"],
  LOCKED: [],
} as const;

export function assertPeriodTransition(current: string, next: string) {
  const allowed = PERIOD_TRANSITIONS[current as keyof typeof PERIOD_TRANSITIONS] ?? [];
  if (!(allowed as readonly string[]).includes(next)) {
    throw new Error(`Periode berstatus '${current}' tidak dapat diubah menjadi '${next}'.`);
  }
}

export function assertKpiTransition(current: string, next: string) {
  const allowed = KPI_TRANSITIONS[current as keyof typeof KPI_TRANSITIONS] ?? [];
  if (!(allowed as readonly string[]).includes(next)) {
    throw new Error(`KPI berstatus '${current}' tidak dapat diubah menjadi '${next}'.`);
  }
}

export function assertExpectedVersion(actual: number, expected: number | null | undefined) {
  if (expected != null && actual !== expected) {
    throw new Error("Data telah berubah. Muat ulang sebelum menyimpan kembali.");
  }
}
