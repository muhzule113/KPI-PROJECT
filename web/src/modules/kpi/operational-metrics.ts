export const ATTENDANCE_STATUSES = ["present", "late", "permission", "sick_leave", "absent"] as const;
export const WORKED_ATTENDANCE_STATUSES = new Set(["present", "late"]);
export const EXCUSED_ATTENDANCE_STATUSES = new Set(["permission", "sick_leave"]);

type AttendanceFact = { attendanceDate: Date; status: string };
type WorkLogFact = {
  recordsInput: number;
  recordsCorrected: number;
  documentsEligible: number;
  documentsComplete: number;
  reconciliationsTotal: number;
  reconciliationsSuccess: number;
};

const round = (value: number) => Math.round((value + Number.EPSILON) * 100) / 100;

export function datesThrough(start: Date, end: Date) {
  const dates: Date[] = [];
  for (let cursor = new Date(start); cursor <= end; cursor = new Date(cursor.valueOf() + 86_400_000)) dates.push(cursor);
  return dates;
}

export function sameSystemDetails(current: unknown, next: Record<string, string | number | boolean | null>) {
  if (!current || Array.isArray(current) || typeof current !== "object") return false;
  const sorted = (value: Record<string, unknown>) => Object.entries(value).sort(([left], [right]) => left.localeCompare(right));
  return JSON.stringify(sorted(current as Record<string, unknown>)) === JSON.stringify(sorted(next));
}

export function calculateAttendanceRate(records: AttendanceFact[]) {
  const eligible = records.filter((record) => ![0, 6].includes(record.attendanceDate.getUTCDay()) && !EXCUSED_ATTENDANCE_STATUSES.has(record.status));
  if (!eligible.length) return null;
  return round(eligible.filter((record) => WORKED_ATTENDANCE_STATUSES.has(record.status)).length / eligible.length * 100);
}

export function calculateWorkLogMetrics(logs: WorkLogFact[]) {
  const totals = logs.reduce((sum, row) => ({
    input: sum.input + row.recordsInput,
    corrected: sum.corrected + row.recordsCorrected,
    eligible: sum.eligible + row.documentsEligible,
    complete: sum.complete + row.documentsComplete,
    reconciliations: sum.reconciliations + row.reconciliationsTotal,
    reconciled: sum.reconciled + row.reconciliationsSuccess,
  }), { input: 0, corrected: 0, eligible: 0, complete: 0, reconciliations: 0, reconciled: 0 });

  return {
    "ADM-01": totals.input ? round((totals.input - totals.corrected) / totals.input * 100) : null,
    "ADM-03": totals.eligible ? round(totals.complete / totals.eligible * 100) : null,
    "ADM-04": totals.reconciliations ? round(totals.reconciled / totals.reconciliations * 100) : null,
  } as const;
}
