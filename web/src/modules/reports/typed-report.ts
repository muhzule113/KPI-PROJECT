export const TYPED_REPORTS = [
  { type: "period_summary", label: "Rekap skor per periode", formats: ["xlsx", "pdf"] },
  { type: "position_summary", label: "Rekap per jabatan", formats: ["xlsx", "pdf"] },
  { type: "individual", label: "Detail penilaian karyawan", formats: ["pdf"] },
  { type: "target_actual", label: "Target vs aktual per KPI", formats: ["xlsx"] },
  { type: "ranking", label: "Ranking karyawan", formats: ["pdf"] },
  { type: "below_target", label: "KPI di bawah target", formats: ["xlsx"] },
  { type: "workflow_completeness", label: "Kelengkapan penilaian", formats: ["xlsx"] },
  { type: "change_history", label: "Histori perubahan penilaian", formats: ["xlsx"] },
  { type: "import_reconciliation", label: "Rekonsiliasi impor", formats: ["xlsx"] },
  { type: "coaching_action_plan", label: "Coaching dan action plan", formats: ["pdf"] },
] as const;

export type TypedReportType = (typeof TYPED_REPORTS)[number]["type"];
export type TypedReportFormat = "xlsx" | "pdf";

export function parseTypedReportFilename(filename: string): { type: TypedReportType; format: TypedReportFormat } | null {
  const separator = filename.lastIndexOf(".");
  if (separator < 1) return null;
  const type = filename.slice(0, separator);
  const format = filename.slice(separator + 1);
  const report = TYPED_REPORTS.find((candidate) => candidate.type === type);
  return report?.formats.includes(format as never) ? { type: report.type, format: format as TypedReportFormat } : null;
}
