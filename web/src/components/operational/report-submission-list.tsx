"use client";

import { PaperPlaneTiltIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import { useActionState } from "react";
import { submitReport, type OperationalActionState } from "@/app/(workspace)/app/operasional/actions";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";

type ReportRow = { id: string; type: string; date: string; deadline: string; submittedAt: string | null; isOnTime: boolean | null };

function SubmitButton({ id }: { id: string }) {
  const [state, action, pending] = useActionState<OperationalActionState, FormData>(submitReport, {});
  return <form action={action} className="space-y-1"><input type="hidden" name="reportId" value={id} /><Button size="sm" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <PaperPlaneTiltIcon />}{pending ? "Mengirim..." : "Kirim"}</Button>{state.error ? <p role="alert" className="max-w-64 text-xs text-destructive">{state.error}</p> : null}{state.success ? <p role="status" className="text-xs text-primary">{state.success}</p> : null}</form>;
}

export function ReportSubmissionList({ reports }: { reports: ReportRow[] }) {
  const labels: Record<string, string> = { admin_daily: "Laporan admin harian", admin_monthly: "Laporan admin bulanan", supervisor_monthly: "Rekap Supervisor bulanan" };
  return <div className="divide-y">{reports.map((report) => <div key={report.id} className="flex flex-col gap-3 py-4 first:pt-0 last:pb-0 sm:flex-row sm:items-center sm:justify-between"><div><p className="text-sm font-medium">{labels[report.type] ?? report.type}</p><p className="mt-1 text-xs text-muted-foreground">Tanggal {report.date} · batas {report.deadline}</p></div><div className="flex items-center gap-3">{report.submittedAt ? <Badge variant={report.isOnTime ? "default" : "warning"}>{report.isOnTime ? "Tepat waktu" : "Terlambat"}</Badge> : <SubmitButton id={report.id} />}</div></div>)}</div>;
}
