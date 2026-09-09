"use client";

import { CheckCircleIcon, PaperPlaneTiltIcon, SpinnerGapIcon, WarningCircleIcon } from "@phosphor-icons/react";
import { useActionState } from "react";
import { assessRubric, decideKpi, finishReview, reviewItem, type TeamActionState } from "@/app/(workspace)/app/tim/actions";
import { Button } from "@/components/ui/button";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

const initialState: TeamActionState = {};

function Feedback({ state }: { state: TeamActionState }) {
  if (state.error) return <p role="alert" className="mt-2 text-xs text-destructive">{state.error}</p>;
  if (state.success) return <p role="status" className="mt-2 text-xs text-primary">{state.success}</p>;
  return null;
}

export function ReviewItemForm({ kpiId, itemId, rowVersion, itemVersion }: { kpiId: string; itemId: string; rowVersion: number; itemVersion: number }) {
  const [state, action, pending] = useActionState(reviewItem, initialState);
  return (
    <form action={action} className="mt-4 rounded-xl border bg-muted/35 p-3">
      <input type="hidden" name="kpiId" value={kpiId} /><input type="hidden" name="itemId" value={itemId} /><input type="hidden" name="rowVersion" value={rowVersion} /><input type="hidden" name="itemVersion" value={itemVersion} />
      <Label htmlFor={`reason-${itemId}`}>Catatan penilai</Label>
      <Textarea id={`reason-${itemId}`} name="reason" rows={2} className="mt-2" placeholder="Wajib diisi jika meminta revisi" />
      <div className="mt-3 flex flex-wrap gap-2">
        <Button type="submit" name="decision" value="valid" size="sm" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <CheckCircleIcon />} Terima</Button>
        <Button type="submit" name="decision" value="revision" variant="outline" size="sm" disabled={pending}><WarningCircleIcon /> Minta revisi</Button>
      </div>
      <Feedback state={state} />
    </form>
  );
}

export function RubricForm({ kpiId, itemId, rowVersion, itemVersion, criteria, fulfilled }: { kpiId: string; itemId: string; rowVersion: number; itemVersion: number; criteria: Array<{ id: string; text: string; points: number }>; fulfilled: string[] }) {
  const [state, action, pending] = useActionState(assessRubric, initialState);
  return (
    <form action={action} className="mt-4 rounded-xl border bg-muted/35 p-3">
      <input type="hidden" name="kpiId" value={kpiId} /><input type="hidden" name="itemId" value={itemId} /><input type="hidden" name="rowVersion" value={rowVersion} /><input type="hidden" name="itemVersion" value={itemVersion} />
      <fieldset><legend className="text-xs font-semibold">Kriteria yang terpenuhi</legend><div className="mt-2 space-y-1">
        {criteria.map((criterion) => <label key={criterion.id} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-2 text-sm hover:bg-accent"><input type="checkbox" name="criterion" value={criterion.id} defaultChecked={fulfilled.includes(criterion.id)} className="size-4 accent-primary" /><span className="min-w-0 flex-1">{criterion.text}</span><span className="text-xs text-muted-foreground">{criterion.points} poin</span></label>)}
      </div></fieldset>
      <Button type="submit" size="sm" className="mt-3" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <CheckCircleIcon />}{pending ? "Menyimpan..." : "Simpan penilaian"}</Button>
      <Feedback state={state} />
    </form>
  );
}

export function FinishReviewForm({ kpiId, rowVersion }: { kpiId: string; rowVersion: number }) {
  const [state, action, pending] = useActionState(finishReview, initialState);
  return (
    <form action={action} className="rounded-2xl border bg-card p-5">
      <input type="hidden" name="kpiId" value={kpiId} /><input type="hidden" name="rowVersion" value={rowVersion} />
      <Label htmlFor="review-reason">Catatan akhir review</Label><Textarea id="review-reason" name="reason" rows={3} className="mt-2" placeholder="Wajib diisi jika mengirim permintaan revisi" />
      <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
        <Button type="submit" name="action" value="revision" variant="outline" disabled={pending}><WarningCircleIcon /> Kirim permintaan revisi</Button>
        <Button type="submit" name="action" value="forward" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <PaperPlaneTiltIcon />} Teruskan ke Manajer</Button>
      </div><Feedback state={state} />
    </form>
  );
}

export function ApprovalForm({ kpiId, rowVersion, items }: { kpiId: string; rowVersion: number; items: Array<{ id: string; name: string }> }) {
  const [state, action, pending] = useActionState(decideKpi, initialState);
  return (
    <form action={action} className="rounded-2xl border bg-card p-5">
      <input type="hidden" name="kpiId" value={kpiId} /><input type="hidden" name="rowVersion" value={rowVersion} />
      <p className="text-sm font-semibold">Keputusan Manajer</p><p className="mt-1 text-xs leading-5 text-muted-foreground">Untuk mengembalikan KPI, pilih indikator yang bermasalah dan berikan alasan.</p>
      <fieldset className="mt-3"><legend className="sr-only">Indikator yang dikembalikan</legend><div className="grid gap-1 sm:grid-cols-2">{items.map((item) => <label key={item.id} className="flex min-h-11 cursor-pointer items-center gap-3 rounded-lg px-2 text-sm hover:bg-accent"><input type="checkbox" name="itemId" value={item.id} className="size-4 accent-primary" /><span>{item.name}</span></label>)}</div></fieldset>
      <Label htmlFor="approval-reason" className="mt-3">Catatan keputusan</Label><Textarea id="approval-reason" name="reason" rows={3} className="mt-2" placeholder="Wajib diisi jika dikembalikan" />
      <div className="mt-4 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end"><Button type="submit" name="action" value="return" variant="outline" disabled={pending}><WarningCircleIcon /> Kembalikan</Button><Button type="submit" name="action" value="approve" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <CheckCircleIcon />} Setujui KPI</Button></div>
      <Feedback state={state} />
    </form>
  );
}
