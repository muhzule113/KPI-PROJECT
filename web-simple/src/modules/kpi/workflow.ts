export type ActorRole = "ADMIN" | "MANAGER" | "SUPERVISOR" | "EMPLOYEE";
export type SubjectRole = "SUPERVISOR" | "EMPLOYEE";
export type WorkStatus = "WORKED" | "OFF" | "PERMIT" | "SICK";
export type DailySheetStatus = "PENDING" | "DRAFT" | "SUBMITTED" | "REVISION_REQUIRED" | "APPROVED";
export type MonthlyResultStatus = "IN_PROGRESS" | "READY" | "FINALIZED" | "REOPENED";

export function submitDailySheet(input: {
  actorRole: ActorRole;
  subjectRole: SubjectRole;
  currentStatus: DailySheetStatus;
  workStatus: WorkStatus | null;
  valuesComplete: boolean;
}): "SUBMITTED" | "APPROVED" {
  if (!["PENDING", "DRAFT", "REVISION_REQUIRED"].includes(input.currentStatus)) {
    throw new Error("Lembar harian tidak dapat dikirim pada status saat ini.");
  }
  if (!input.workStatus) throw new Error("Status kerja harian wajib dipilih.");
  if (input.workStatus === "WORKED" && !input.valuesComplete) throw new Error("Seluruh indikator wajib diisi untuk hari bekerja.");

  const authorized = input.subjectRole === "SUPERVISOR"
    ? input.actorRole === "MANAGER"
    : input.actorRole === "SUPERVISOR";
  if (!authorized) throw new Error("Anda tidak berwenang mengirim lembar harian ini.");

  return input.subjectRole === "SUPERVISOR" ? "APPROVED" : "SUBMITTED";
}

export function reviewDailySheet(input: {
  currentStatus: DailySheetStatus;
  decision: "APPROVE" | "CORRECT" | "RETURN";
  changed: boolean;
  reason?: string;
  allowApproved?: boolean;
}): "APPROVED" | "REVISION_REQUIRED" {
  if (input.currentStatus !== "SUBMITTED" && !(input.allowApproved && input.currentStatus === "APPROVED")) {
    throw new Error("Lembar tidak sedang menunggu review Manager.");
  }
  const reason = input.reason?.trim();
  if (input.decision === "RETURN" && !reason) throw new Error("Alasan pengembalian wajib diisi.");
  if (input.decision === "CORRECT" && (!input.changed || !reason)) throw new Error("Koreksi Manager dan alasannya wajib diisi.");
  if (input.decision === "APPROVE" && input.changed) throw new Error("Gunakan keputusan koreksi ketika nilai diubah.");
  return input.decision === "RETURN" ? "REVISION_REQUIRED" : "APPROVED";
}

export function effectiveDailyValue(enteredValue: string, managerValue: string | null) {
  return managerValue ?? enteredValue;
}

export function finalizeReadiness(input: {
  today: string;
  periodEndDate: string;
  sheetStatuses: readonly DailySheetStatus[];
  workedDays: number;
  calculationStatuses: readonly ("PENDING" | "CALCULATED" | "UNSCORABLE")[];
  noScoreReason?: string;
}): { ok: true; withoutScore?: true } | { ok: false; reason: string } {
  if (input.today <= input.periodEndDate) return { ok: false, reason: "Periode belum berakhir." };
  if (input.sheetStatuses.length === 0 || input.sheetStatuses.some((status) => status !== "APPROVED")) {
    return { ok: false, reason: "Masih ada lembar harian yang belum disetujui Manager." };
  }
  if (input.workedDays === 0) {
    return input.noScoreReason?.trim()
      ? { ok: true, withoutScore: true }
      : { ok: false, reason: "Alasan wajib diisi jika tidak ada hari bekerja." };
  }
  if (input.calculationStatuses.length === 0 || input.calculationStatuses.some((status) => status !== "CALCULATED")) {
    return { ok: false, reason: "Seluruh indikator bulanan harus dapat dihitung." };
  }
  return { ok: true };
}

export function reopenMonthlyResult(input: {
  actorRole: ActorRole;
  currentStatus: MonthlyResultStatus;
  reason?: string;
}): "REOPENED" {
  if (input.actorRole !== "ADMIN") throw new Error("Hanya Super Admin yang dapat membuka kembali hasil final.");
  if (input.currentStatus !== "FINALIZED") throw new Error("Hanya hasil final yang dapat dibuka kembali.");
  if (!input.reason?.trim()) throw new Error("Alasan membuka kembali hasil wajib diisi.");
  return "REOPENED";
}
