"use client";

import { useState } from "react";
import { removeEvidenceAction, saveDailySheetAction, uploadEvidenceAction } from "@/app/(workspace)/app/harian/actions";
import { ActionForm, SubmitButton } from "@/components/action-form";
import { ConfirmAction } from "@/components/confirm-action";
import { IndicatorValueField } from "@/components/indicator-value-field";
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
    {sheet.workStatus === "WORKED" ? <div className="indicator-list">{sheet.items.map((item, index) => <div className="indicator-row" key={item.id}>
      <div className="indicator-copy">
        <span className="indicator-position">Indikator {index + 1}</span>
        <h3>{item.name}</h3>
        <p>{item.description || "Nilai aktual untuk indikator ini."}</p>
        <div className="indicator-target"><span>Target</span><strong>{item.target} {item.unit}</strong></div>
      </div>
      <div className="indicator-result"><span>Nilai</span><strong>{item.value === null ? "Belum diisi" : `${item.value} ${item.unit}`}</strong></div>
    </div>)}</div> : null}
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
      {working ? <div className="indicator-list motion-reveal">{sheet.items.map((item, index) => <div className="indicator-row" key={item.id}>
        <div className="indicator-copy">
          <span className="indicator-position">Indikator {index + 1} dari {sheet.items.length}</span>
          <h3>{item.name}</h3>
          <p>{item.description || "Masukkan nilai aktual untuk indikator ini."}</p>
          <div className="indicator-target"><span>Target</span><strong>{item.target} {item.unit}</strong></div>
        </div>
        <IndicatorValueField itemId={item.id} kind={item.kind} unit={item.unit} defaultValue={item.value} label={item.kind === "RATING" ? "Rating aktual" : "Nilai aktual"} />
      </div>)}</div> : <p className="help motion-reveal">Nilai indikator tidak diperlukan untuk hari Libur, Izin, atau Sakit.</p>}
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
