"use client";

import { CheckCircleIcon, SpinnerGapIcon, WarningCircleIcon } from "@phosphor-icons/react";
import { useActionState } from "react";
import { decideKpiCorrection, requestKpiCorrection, type CorrectionActionState } from "@/app/(workspace)/app/koreksi/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

const initialState: CorrectionActionState = {};

function Feedback({ state }: { state: CorrectionActionState }) {
  if (state.error) return <p role="alert" className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">{state.error}</p>;
  if (state.success) return <p role="status" className="rounded-lg bg-accent px-3 py-2 text-sm text-primary">{state.success}</p>;
  return null;
}

export function CorrectionRequestForm({ employeeKpiId, items }: { employeeKpiId: string; items: Array<{ id: string; code: string; name: string; actual: string | null }> }) {
  const [state, action, pending] = useActionState(requestKpiCorrection, initialState);
  return <form action={action} className="mt-4 space-y-4 rounded-xl border bg-muted/30 p-4"><input type="hidden" name="employeeKpiId" value={employeeKpiId} /><div className="grid gap-4 sm:grid-cols-2"><div className="space-y-2"><Label htmlFor={`correction-item-${employeeKpiId}`}>Indikator manual</Label><select id={`correction-item-${employeeKpiId}`} name="itemId" defaultValue="" required className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="" disabled>Pilih indikator</option>{items.map((item) => <option key={item.id} value={item.id}>{item.code} · {item.name} · saat ini {item.actual ?? "kosong"}</option>)}</select></div><div className="space-y-2"><Label htmlFor={`correction-actual-${employeeKpiId}`}>Nilai aktual baru</Label><Input id={`correction-actual-${employeeKpiId}`} name="actual" type="number" inputMode="decimal" step="any" required /></div></div><div className="space-y-2"><Label htmlFor={`correction-reason-${employeeKpiId}`}>Alasan koreksi</Label><Textarea id={`correction-reason-${employeeKpiId}`} name="reason" required minLength={5} maxLength={2000} rows={3} /></div><div className="grid gap-4 sm:grid-cols-2"><div className="space-y-2"><Label htmlFor={`evidence-type-${employeeKpiId}`}>Tipe evidence</Label><Input id={`evidence-type-${employeeKpiId}`} name="evidenceType" required maxLength={30} placeholder="contoh: dokumen" /></div><div className="space-y-2"><Label htmlFor={`evidence-ref-${employeeKpiId}`}>Referensi evidence</Label><Input id={`evidence-ref-${employeeKpiId}`} name="evidenceReference" required maxLength={500} placeholder="nomor atau tautan dokumen" /></div></div><Button type="submit" size="sm" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <WarningCircleIcon />}{pending ? "Mengajukan..." : "Ajukan koreksi"}</Button><Feedback state={state} /></form>;
}

export function CorrectionDecisionForm({ correctionId }: { correctionId: string }) {
  const [state, action, pending] = useActionState(decideKpiCorrection, initialState);
  return <form action={action} className="mt-4 space-y-3 rounded-xl border bg-muted/30 p-4"><input type="hidden" name="correctionId" value={correctionId} /><div className="space-y-2"><Label htmlFor={`correction-decision-${correctionId}`}>Catatan keputusan</Label><Textarea id={`correction-decision-${correctionId}`} name="reason" maxLength={1000} rows={2} placeholder="Wajib bila ditolak" /></div><div className="flex flex-wrap gap-2"><Button type="submit" size="sm" name="decision" value="approved" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <CheckCircleIcon />}Setujui & terapkan</Button><Button type="submit" size="sm" variant="outline" name="decision" value="rejected" disabled={pending}><WarningCircleIcon /> Tolak</Button></div><Feedback state={state} /></form>;
}
