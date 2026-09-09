"use client";

import { ArrowRightIcon, ArrowsClockwiseIcon, CheckCircleIcon, FloppyDiskIcon, SpinnerGapIcon, WarningCircleIcon } from "@phosphor-icons/react";
import { useActionState } from "react";
import { changePeriodStatus, checkReadiness, savePeriod, syncPeriodFacts, type PeriodActionState } from "@/app/(workspace)/app/pengaturan/periode/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

const initialState: PeriodActionState = {};
const selectClass = "flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring/35";

function Feedback({ state }: { state: PeriodActionState }) {
  if (state.error) return <p role="alert" className="mt-3 rounded-lg bg-destructive/10 px-3 py-2 text-xs text-destructive">{state.error}</p>;
  if (state.success) return <p role="status" className="mt-3 rounded-lg bg-accent px-3 py-2 text-xs text-primary">{state.success}</p>;
  return null;
}

type Branch = { id: string; name: string };
type PeriodValues = { id: string; name: string; year: number; month: number; startDate: string; endDate: string; submissionDeadline: string; reviewDeadline: string; approvalDeadline: string; branchIds: string[] };

export function PeriodForm({ branches, period }: { branches: Branch[]; period?: PeriodValues }) {
  const [state, action, pending] = useActionState(savePeriod, initialState);
  const now = new Date();
  const year = period?.year ?? now.getFullYear();
  const month = period?.month ?? now.getMonth() + 1;
  return (
    <form action={action} className="space-y-5">
      {period ? <input type="hidden" name="periodId" value={period.id} /> : null}
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div className="space-y-2 sm:col-span-2"><Label htmlFor={`name-${period?.id ?? "new"}`}>Nama periode</Label><Input id={`name-${period?.id ?? "new"}`} name="name" required minLength={3} maxLength={100} defaultValue={period?.name ?? ""} placeholder="Contoh: September 2026" /></div>
        <div className="space-y-2"><Label htmlFor={`year-${period?.id ?? "new"}`}>Tahun</Label><Input id={`year-${period?.id ?? "new"}`} name="year" type="number" min={2000} max={2100} required defaultValue={year} /></div>
        <div className="space-y-2"><Label htmlFor={`month-${period?.id ?? "new"}`}>Bulan</Label><select id={`month-${period?.id ?? "new"}`} name="month" required defaultValue={month} className={selectClass}>{Array.from({ length: 12 }, (_, index) => <option key={index + 1} value={index + 1}>{new Intl.DateTimeFormat("id-ID", { month: "long" }).format(new Date(2026, index, 1))}</option>)}</select></div>
        <div className="space-y-2"><Label htmlFor={`start-${period?.id ?? "new"}`}>Tanggal mulai</Label><Input id={`start-${period?.id ?? "new"}`} name="startDate" type="date" required defaultValue={period?.startDate} /></div>
        <div className="space-y-2"><Label htmlFor={`end-${period?.id ?? "new"}`}>Tanggal selesai</Label><Input id={`end-${period?.id ?? "new"}`} name="endDate" type="date" required defaultValue={period?.endDate} /></div>
        <div className="space-y-2"><Label htmlFor={`input-deadline-${period?.id ?? "new"}`}>Batas input</Label><Input id={`input-deadline-${period?.id ?? "new"}`} name="submissionDeadline" type="datetime-local" required defaultValue={period?.submissionDeadline} /></div>
        <div className="space-y-2"><Label htmlFor={`review-deadline-${period?.id ?? "new"}`}>Batas review</Label><Input id={`review-deadline-${period?.id ?? "new"}`} name="reviewDeadline" type="datetime-local" required defaultValue={period?.reviewDeadline} /></div>
        <div className="space-y-2 sm:col-span-2"><Label htmlFor={`approval-deadline-${period?.id ?? "new"}`}>Batas approval</Label><Input id={`approval-deadline-${period?.id ?? "new"}`} name="approvalDeadline" type="datetime-local" required defaultValue={period?.approvalDeadline} /></div>
      </div>
      <fieldset><legend className="text-sm font-medium">Cabang peserta</legend><div className="mt-2 grid gap-2 sm:grid-cols-2 lg:grid-cols-3">{branches.map((branch) => <label key={branch.id} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg border px-3 text-sm"><input type="checkbox" name="branchId" value={branch.id} defaultChecked={period?.branchIds.includes(branch.id)} className="size-4 accent-primary" />{branch.name}</label>)}</div></fieldset>
      <div className="flex justify-end"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <FloppyDiskIcon />}{pending ? "Menyimpan..." : period ? "Simpan perubahan" : "Buat periode"}</Button></div>
      <Feedback state={state} />
    </form>
  );
}

const nextByStatus: Record<string, { value: string; label: string }> = {
  DRAFT: { value: "READY", label: "Buat snapshot dan tandai siap" },
  READY: { value: "OPEN", label: "Buka periode" },
  OPEN: { value: "SUBMISSION_CLOSED", label: "Tutup input" },
  SUBMISSION_CLOSED: { value: "IN_REVIEW", label: "Mulai review" },
  IN_REVIEW: { value: "WAITING_APPROVAL", label: "Mulai approval" },
  WAITING_APPROVAL: { value: "PUBLISHED", label: "Publikasikan hasil" },
  PUBLISHED: { value: "LOCKED", label: "Kunci periode" },
};

export function PeriodActions({ periodId, status, startDate, endDate, today }: { periodId: string; status: string; startDate: string; endDate: string; today: string }) {
  const [readiness, readinessAction, checking] = useActionState(checkReadiness, initialState);
  const [transition, transitionAction, changing] = useActionState(changePeriodStatus, initialState);
  const [sync, syncAction, syncing] = useActionState(syncPeriodFacts, initialState);
  const next = nextByStatus[status];
  const throughDate = endDate < today ? endDate : today;
  const syncable = throughDate >= startDate && ["OPEN", "SUBMISSION_CLOSED", "IN_REVIEW", "WAITING_APPROVAL"].includes(status);
  if (!next && ["LOCKED", "CANCELLED"].includes(status)) return null;
  return (
    <div className="mt-4 border-t pt-4">
      {syncable ? <form action={syncAction} className="mb-3 flex flex-wrap items-end gap-2"><input type="hidden" name="periodId" value={periodId} /><div className="space-y-2"><Label htmlFor={`sync-${periodId}`}>Sinkronkan fakta sampai</Label><Input id={`sync-${periodId}`} name="throughDate" type="date" min={startDate} max={throughDate} defaultValue={throughDate} required /></div><Button type="submit" variant="outline" size="sm" disabled={syncing}>{syncing ? <SpinnerGapIcon className="animate-spin" /> : <ArrowsClockwiseIcon />}Sinkronkan</Button><Feedback state={sync} /></form> : null}
      {status === "DRAFT" ? <form action={readinessAction}><input type="hidden" name="periodId" value={periodId} /><Button type="submit" variant="outline" size="sm" disabled={checking}>{checking ? <SpinnerGapIcon className="animate-spin" /> : <CheckCircleIcon />}Cek kesiapan</Button><Feedback state={readiness} /></form> : null}
      {next ? <form action={transitionAction} className="mt-3"><input type="hidden" name="periodId" value={periodId} /><input type="hidden" name="next" value={next.value} /><Button type="submit" size="sm" disabled={changing}>{changing ? <SpinnerGapIcon className="animate-spin" /> : <ArrowRightIcon />}{next.label}</Button><Feedback state={transition} /></form> : null}
      {!["PUBLISHED", "LOCKED", "CANCELLED"].includes(status) ? <details className="mt-3"><summary className="cursor-pointer text-xs font-semibold text-destructive">Batalkan periode</summary><form action={transitionAction} className="mt-3"><input type="hidden" name="periodId" value={periodId} /><input type="hidden" name="next" value="CANCELLED" /><Label htmlFor={`cancel-${periodId}`}>Alasan pembatalan</Label><Textarea id={`cancel-${periodId}`} name="reason" required maxLength={1000} rows={2} className="mt-2" /><Button type="submit" variant="destructive" size="sm" className="mt-2" disabled={changing}><WarningCircleIcon />Batalkan</Button></form></details> : null}
    </div>
  );
}
