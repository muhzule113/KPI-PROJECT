"use client";

import { CheckCircleIcon, SpinnerGapIcon, WarningCircleIcon } from "@phosphor-icons/react";
import { useActionState } from "react";
import { approveAllDailyAssessments, saveDailyAssessment, type DailyActionState } from "@/app/(workspace)/app/tim/harian/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

const initialState: DailyActionState = {};

export function DailyAssessmentForm({ entryId, rowVersion, role, kind, ratings, criteria, defaultActual, defaultRating, fulfilled }: {
  entryId: string;
  rowVersion: number;
  role: "supervisor" | "manager";
  kind: "system" | "rating" | "rubric" | "numeric";
  ratings: Array<{ code: string; label: string; score: number }>;
  criteria: Array<{ id: string; text: string; points: number }>;
  defaultActual?: string;
  defaultRating?: string;
  fulfilled: string[];
}) {
  const [state, action, pending] = useActionState(saveDailyAssessment, initialState);
  const suffix = `${role}-${entryId}`;
  return (
    <form action={action} className="mt-4 space-y-4 rounded-xl border bg-muted/30 p-4">
      <input type="hidden" name="entryId" value={entryId} />
      <input type="hidden" name="rowVersion" value={rowVersion} />
      <input type="hidden" name="role" value={role} />
      {kind === "system" ? <p className="text-xs leading-5 text-muted-foreground">Nilai berasal dari modul operasional. Penilai hanya mengonfirmasi atau meminta koreksi sumber.</p> : null}
      {kind === "rating" ? <div className="space-y-2"><Label htmlFor={`rating-${suffix}`}>Predikat hari ini</Label><select id={`rating-${suffix}`} name="ratingCode" required defaultValue={defaultRating ?? ""} className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="" disabled>Pilih predikat</option>{ratings.map((rating) => <option key={rating.code} value={rating.code}>{rating.label} · {rating.score}</option>)}</select></div> : null}
      {kind === "rubric" ? <fieldset><legend className="text-sm font-medium">Kriteria yang terpenuhi</legend><div className="mt-2 space-y-1">{criteria.map((criterion) => <label key={criterion.id} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-2 text-sm hover:bg-accent"><input type="checkbox" name="criterion" value={criterion.id} defaultChecked={fulfilled.includes(criterion.id)} className="size-4 accent-primary" /><span className="min-w-0 flex-1">{criterion.text}</span><span className="text-xs text-muted-foreground">{criterion.points} poin</span></label>)}</div></fieldset> : null}
      {kind === "numeric" ? <div className="space-y-2"><Label htmlFor={`actual-${suffix}`}>Nilai aktual</Label><Input id={`actual-${suffix}`} name="actual" type="number" inputMode="decimal" step="any" defaultValue={defaultActual} required /></div> : null}
      <div className="space-y-2"><Label htmlFor={`note-${suffix}`}>Catatan penilai</Label><Textarea id={`note-${suffix}`} name="note" rows={2} maxLength={1000} placeholder="Wajib untuk revisi, perubahan Manager, atau nilai di bawah target" /></div>
      <div className="flex flex-col-reverse gap-2 sm:flex-row">
        <Button type="submit" name="decision" value="revision_required" variant="outline" disabled={pending}><WarningCircleIcon /> Minta koreksi</Button>
        <Button type="submit" name="decision" value="approved" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <CheckCircleIcon />}{pending ? "Menyimpan..." : "Setujui"}</Button>
      </div>
      {state.error ? <p role="alert" className="text-xs text-destructive">{state.error}</p> : null}
      {state.success ? <p role="status" className="text-xs text-primary">{state.success}</p> : null}
    </form>
  );
}

export function DailyBulkApproveForm({ employeeKpiId, entryDate }: { employeeKpiId: string; entryDate: string }) {
  const [state, action, pending] = useActionState(approveAllDailyAssessments, initialState);
  return (
    <form action={action} className="space-y-2">
      <input type="hidden" name="employeeKpiId" value={employeeKpiId} />
      <input type="hidden" name="entryDate" value={entryDate} />
      <Button type="submit" size="sm" disabled={pending}>
        {pending ? <SpinnerGapIcon className="animate-spin" /> : <CheckCircleIcon />}
        {pending ? "Menyetujui..." : "Setujui semua"}
      </Button>
      {state.error ? <p role="alert" className="max-w-64 text-xs text-destructive">{state.error}</p> : null}
      {state.success ? <p role="status" className="max-w-64 text-xs text-primary">{state.success}</p> : null}
    </form>
  );
}
