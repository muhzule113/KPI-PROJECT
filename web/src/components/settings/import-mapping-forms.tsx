"use client";

import { CopyIcon, FloppyDiskIcon, RocketLaunchIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import { useRouter } from "next/navigation";
import { useActionState, useEffect } from "react";
import { activateImportMappingVersion, saveImportMappingDraft, saveImportMappingTemplate, startImportMappingDraft, type MappingActionState } from "@/app/(workspace)/app/pengaturan/impor/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { CASHIER_MAPPING_FIELDS, type CashierMappingKey } from "@/modules/imports/mapping";

const initial: MappingActionState = {};
const selectClass = "flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring";

function Feedback({ state }: { state: MappingActionState }) {
  if (state.error) return <p role="alert" className="text-sm text-destructive">{state.error}</p>;
  if (state.success) return <p role="status" className="text-sm text-primary">{state.success}</p>;
  return null;
}

function useRefreshOnSuccess(state: MappingActionState) {
  const router = useRouter();
  useEffect(() => { if (state.success) router.refresh(); }, [router, state.success]);
}

type TemplateValue = { id: string; name: string; sourceApplication: string; description: string | null; isActive: boolean };

export function ImportMappingTemplateForm({ value }: { value?: TemplateValue }) {
  const [state, action, pending] = useActionState(saveImportMappingTemplate, initial);
  useRefreshOnSuccess(state);
  const suffix = value?.id ?? "new";
  return <form action={action} className="space-y-4">
    <input type="hidden" name="templateId" value={value?.id ?? ""} />
    <div className="grid gap-4 sm:grid-cols-2">
      <div className="space-y-2"><Label htmlFor={`mapping-name-${suffix}`}>Nama template</Label><Input id={`mapping-name-${suffix}`} name="name" required minLength={3} maxLength={120} defaultValue={value?.name ?? ""} placeholder="Contoh: POS utama" /></div>
      <div className="space-y-2"><Label htmlFor={`mapping-source-${suffix}`}>Kode aplikasi sumber</Label><Input id={`mapping-source-${suffix}`} name="sourceApplication" required minLength={2} maxLength={60} pattern="[A-Za-z0-9_-]+" defaultValue={value?.sourceApplication ?? "POS_SYSTEM"} /></div>
      <div className="space-y-2"><Label htmlFor={`mapping-description-${suffix}`}>Deskripsi</Label><Textarea id={`mapping-description-${suffix}`} name="description" rows={2} maxLength={1000} defaultValue={value?.description ?? ""} /></div>
      <div className="space-y-2"><Label htmlFor={`mapping-active-${suffix}`}>Status template</Label><select id={`mapping-active-${suffix}`} name="isActive" className={selectClass} defaultValue={String(value?.isActive ?? true)}><option value="true">Aktif</option><option value="false">Nonaktif</option></select></div>
    </div>
    <Feedback state={state} />
    <Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <FloppyDiskIcon />}{pending ? "Menyimpan..." : value ? "Simpan template" : "Buat template"}</Button>
  </form>;
}

export function ImportMappingDraftForm({ versionId, mapping }: { versionId: string; mapping: Record<CashierMappingKey, string> }) {
  const [state, action, pending] = useActionState(saveImportMappingDraft, initial);
  useRefreshOnSuccess(state);
  return <form action={action} className="space-y-4">
    <input type="hidden" name="versionId" value={versionId} />
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
      {CASHIER_MAPPING_FIELDS.map((field) => <div key={field.key} className="space-y-2"><Label htmlFor={`${versionId}-${field.key}`}>{field.label}</Label><Input id={`${versionId}-${field.key}`} name={field.key} required maxLength={150} defaultValue={mapping[field.key]} /></div>)}
    </div>
    <p className="text-xs leading-5 text-muted-foreground">Isi dengan nama header persis seperti pada XLSX/CSV. Setiap header wajib unik.</p>
    <Feedback state={state} />
    <Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <FloppyDiskIcon />}{pending ? "Menyimpan..." : "Simpan draft"}</Button>
  </form>;
}

export function ImportMappingActionButton({ id, actionKind }: { id: string; actionKind: "draft" | "activate" }) {
  const fn = actionKind === "draft" ? startImportMappingDraft : activateImportMappingVersion;
  const [state, action, pending] = useActionState(fn, initial);
  useRefreshOnSuccess(state);
  return <form action={action} className="space-y-2">
    <input type="hidden" name={actionKind === "draft" ? "templateId" : "versionId"} value={id} />
    <Button type="submit" size="sm" variant={actionKind === "draft" ? "outline" : "default"} disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : actionKind === "draft" ? <CopyIcon /> : <RocketLaunchIcon />}{actionKind === "draft" ? "Buat draft baru" : "Aktifkan draft"}</Button>
    <Feedback state={state} />
  </form>;
}
