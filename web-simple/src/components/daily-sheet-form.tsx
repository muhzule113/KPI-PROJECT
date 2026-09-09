"use client";

import { useState } from "react";
import { removeEvidenceAction, saveDailySheetAction, uploadEvidenceAction } from "@/app/(workspace)/app/harian/actions";
import { ActionForm, SubmitButton } from "@/components/action-form";
import { ConfirmAction } from "@/components/confirm-action";
import { Button } from "@/components/ui/button";

type WorkStatus = "WORKED" | "OFF" | "PERMIT" | "SICK";
type Item = { id: string; name: string; description: string | null; kind: "NUMERIC" | "RATING"; unit: string; target: string; value: string | null };
type Evidence = { id: string; fileName: string; fileSize: string };

const workStatusChoices: Array<[WorkStatus, string, string]> = [
  ["WORKED", "Bekerja", "Isi nilai indikator"],
  ["OFF", "Libur", "Tanpa nilai indikator"],
  ["PERMIT", "Izin", "Tanpa nilai indikator"],
  ["SICK", "Sakit", "Tanpa nilai indikator"],
];

export function DailySheetForm({ sheet, editable, evidenceEditable, directApproval }: {
  sheet: {
    id: string;
    rowVersion: number;
    status: string;
    workStatus: WorkStatus | null;
    note: string | null;
    managerReason: string | null;
    items: Item[];
    evidence: Evidence[];
  };
  editable: boolean;
  evidenceEditable: boolean;
  directApproval: boolean;
}) {
  const [workStatus, setWorkStatus] = useState<WorkStatus>(sheet.workStatus ?? "WORKED");
  const working = workStatus === "WORKED";
  const correctingApproved = sheet.status === "APPROVED" && directApproval;

  if (!editable) return <>
    {sheet.managerReason ? <div className="notice"><strong>Catatan Manager:</strong> {sheet.managerReason}</div> : null}
    <dl className="definition-list sheet-definition"><div><dt>Status kerja</dt><dd>{workStatusLabel(sheet.workStatus)}</dd></div><div><dt>Catatan</dt><dd>{sheet.note || "Tidak ada catatan"}</dd></div></dl>
    {sheet.workStatus === "WORKED" ? <div className="indicator-list">{sheet.items.map((item) => <div className="indicator-row" key={item.id}><div><h3>{item.name}</h3><p>{item.description || `${item.kind === "RATING" ? "Rating 1-5" : "Nilai aktual"} / target ${item.target} ${item.unit}`}</p></div><strong>{item.value ?? "Belum diisi"} {item.unit}</strong></div>)}</div> : null}
    <EvidenceList evidence={sheet.evidence} removable={false} />
  </>;

  return <>
    {sheet.managerReason ? <div className="notice"><strong>Catatan Manager:</strong> {sheet.managerReason}</div> : null}
    <ActionForm action={saveDailySheetAction} className="form-stack">
      <input type="hidden" name="sheetId" value={sheet.id} />
      <input type="hidden" name="rowVersion" value={sheet.rowVersion} />
      <fieldset className="field">
        <legend className="field-label">Status kerja</legend>
        <div className="choice-grid">{workStatusChoices.map(([value, label, description]) => <label className="choice-card" key={value}>
          <input type="radio" name="workStatus" value={value} checked={workStatus === value} onChange={() => setWorkStatus(value)} required />
          <strong>{label}</strong><span>{description}</span>
        </label>)}</div>
      </fieldset>
      {working ? <div className="indicator-list motion-reveal">{sheet.items.map((item) => <div className="indicator-row" key={item.id}><div><h3>{item.name}</h3><p>{item.description || "Masukkan nilai aktual."} Target: {item.target} {item.unit}. {item.kind === "RATING" ? "Gunakan rating bulat 1-5." : ""}</p></div><div className="field"><label htmlFor={`value-${item.id}`}>Aktual ({item.unit})</label><input type="hidden" name="itemId" value={item.id} /><input className="control" id={`value-${item.id}`} name="value" type="number" min={item.kind === "RATING" ? 1 : 0} max={item.kind === "RATING" ? 5 : 1000000000} step={item.kind === "RATING" ? 1 : "any"} defaultValue={item.value ?? ""} required /></div></div>)}</div> : <p className="help motion-reveal">Nilai indikator tidak diperlukan untuk hari Libur, Izin, atau Sakit.</p>}
      <div className="field"><label htmlFor="note">Catatan</label><textarea className="control" id="note" name="note" maxLength={1000} defaultValue={sheet.note ?? ""} placeholder={working ? "Wajib jika ada rating di bawah target." : "Keterangan status kerja (opsional)."} /></div>
      {correctingApproved ? <div className="field motion-reveal"><label htmlFor="correctionReason">Alasan koreksi</label><textarea className="control" id="correctionReason" name="correctionReason" maxLength={1000} required /></div> : null}
      <div className="form-actions">
        {!correctingApproved ? <SubmitButton name="intent" value="save" variant="secondary" pendingText="Menyimpan...">Simpan draf</SubmitButton> : null}
        <SubmitButton name="intent" value="submit" pendingText="Mengirim...">{correctingApproved ? "Simpan koreksi" : directApproval ? "Simpan dan setujui" : "Kirim ke Manager"}</SubmitButton>
      </div>
    </ActionForm>
    <section className="evidence-section">
      <h3 className="evidence-heading">Bukti pendukung <span>({sheet.evidence.length}/3)</span></h3>
      <p className="help">Opsional. JPG, PNG, WEBP, atau PDF; maksimal 10 MB per file.</p>
      {evidenceEditable && sheet.evidence.length < 3 ? <ActionForm action={uploadEvidenceAction} className="form-actions"><input type="hidden" name="sheetId" value={sheet.id} /><input className="control evidence-file" type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required /><SubmitButton variant="secondary" pendingText="Memindai...">Unggah dan pindai</SubmitButton></ActionForm> : null}
      <EvidenceList evidence={sheet.evidence} removable={evidenceEditable} />
    </section>
  </>;
}

function EvidenceList({ evidence, removable }: { evidence: Evidence[]; removable: boolean }) {
  if (!evidence.length) return <p className="help evidence-empty">Belum ada bukti pendukung.</p>;
  return <ul className="evidence-list">{evidence.map((item) => <li className="evidence-item" key={item.id}><span><a href={`/api/evidence/${item.id}`}>{item.fileName}</a> / {item.fileSize}</span>{removable ? <ConfirmAction trigger={<Button variant="danger" size="small">Hapus</Button>} action={removeEvidenceAction} title="Hapus evidence?" description={`${item.fileName} akan dihapus permanen dari lembar ini.`} fields={{ evidenceId: item.id }} confirmLabel="Hapus evidence" variant="danger" /> : null}</li>)}</ul>;
}

function workStatusLabel(status: WorkStatus | null) {
  return status ? ({ WORKED: "Bekerja", OFF: "Libur", PERMIT: "Izin", SICK: "Sakit" } as Record<WorkStatus, string>)[status] : "Belum dipilih";
}
