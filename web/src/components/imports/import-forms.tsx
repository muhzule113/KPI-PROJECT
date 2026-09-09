"use client";

import { CheckCircleIcon, FileArrowUpIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import { useRouter } from "next/navigation";
import { useActionState, useEffect } from "react";
import { confirmCashierImport, stageCashierImport, type ImportActionState } from "@/app/(workspace)/app/impor/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const initialState: ImportActionState = {};

function Feedback({ state }: { state: ImportActionState }) {
  if (state.error) return <p role="alert" className="rounded-lg bg-destructive/10 px-3 py-2 text-sm text-destructive">{state.error}</p>;
  if (state.success) return <p role="status" className="rounded-lg bg-accent px-3 py-2 text-sm text-primary">{state.success}</p>;
  return null;
}

export function ImportUploadForm({ periods, branches, mappings }: { periods: Array<{ id: string; name: string }>; branches: Array<{ id: string; name: string }>; mappings: Array<{ id: string; name: string }> }) {
  const [state, action, pending] = useActionState(stageCashierImport, initialState);
  const router = useRouter();
  useEffect(() => { if (state.batchId) router.refresh(); }, [router, state.batchId]);
  return <form action={action} className="rounded-2xl border bg-card p-5 sm:p-6"><h2 className="flex items-center gap-2 text-base font-semibold"><FileArrowUpIcon className="text-primary" /> Unggah laporan POS</h2><p className="mt-1 text-xs leading-5 text-muted-foreground">XLSX atau CSV diproses di latar belakang lalu dipreview sebelum masuk KPI. PDF hanya disimpan untuk review manual. Maksimal 10 MB dan 20.000 baris.</p><div className="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3"><div className="space-y-2"><Label htmlFor="importPeriod">Periode OPEN</Label><select id="importPeriod" name="periodId" defaultValue={periods.length === 1 ? periods[0].id : ""} required className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="" disabled>Pilih periode</option>{periods.map((period) => <option key={period.id} value={period.id}>{period.name}</option>)}</select></div><div className="space-y-2"><Label htmlFor="importBranch">Cabang</Label><select id="importBranch" name="branchId" defaultValue={branches.length === 1 ? branches[0].id : ""} required className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="" disabled>Pilih cabang</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></div><div className="space-y-2"><Label htmlFor="importMapping">Mapping kolom</Label><select id="importMapping" name="mappingVersionId" defaultValue={mappings.length === 1 ? mappings[0].id : ""} required className="flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"><option value="" disabled>Pilih mapping aktif</option>{mappings.map((mapping) => <option key={mapping.id} value={mapping.id}>{mapping.name}</option>)}</select></div><div className="space-y-2 sm:col-span-2 xl:col-span-3"><Label htmlFor="importFile">File laporan</Label><Input id="importFile" name="file" type="file" accept=".xlsx,.csv,.pdf,text/csv,application/pdf,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required /></div></div><div className="mt-5 flex flex-wrap items-center gap-3"><Button type="submit" disabled={pending || !periods.length || !branches.length || !mappings.length}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <FileArrowUpIcon />}{pending ? "Mengunggah..." : "Unggah ke antrean"}</Button><span className="text-xs text-muted-foreground">{mappings.length ? "Upload belum mengubah KPI." : "Belum ada mapping aktif. Hubungi Admin KPI."}</span></div><div className="mt-4"><Feedback state={state} /></div></form>;
}

export function ImportConfirmForm({ batchId, warningRows }: { batchId: string; warningRows: number }) {
  const [state, action, pending] = useActionState(confirmCashierImport, initialState);
  const router = useRouter();
  useEffect(() => { if (state.success) router.refresh(); }, [router, state.success]);
  return <form action={action} className="mt-4 rounded-xl border bg-muted/30 p-4"><input type="hidden" name="batchId" value={batchId} />{warningRows > 0 ? <label className="flex min-h-11 cursor-pointer items-center gap-3 text-sm"><input type="checkbox" name="acknowledgeWarnings" className="size-4 accent-primary" required /><span>Saya sudah meninjau {warningRows} peringatan.</span></label> : null}<Button type="submit" size="sm" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <CheckCircleIcon />}{pending ? "Mengonfirmasi..." : "Konfirmasi dan hitung KPI"}</Button><div className="mt-3"><Feedback state={state} /></div></form>;
}
