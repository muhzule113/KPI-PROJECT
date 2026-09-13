"use client";

import { useState } from "react";
import { reviewDailySheetAction } from "@/app/(workspace)/app/review/actions";
import { ActionForm, SubmitButton } from "@/components/action-form";
import { IndicatorValueField } from "@/components/indicator-value-field";
import { formatDailyValue, type DailyValueView } from "@/modules/kpi/daily-value-view";

type WorkStatus = "WORKED" | "OFF" | "PERMIT" | "SICK";
type Decision = "APPROVE" | "CORRECT" | "RETURN";

const decisions: Array<[Decision, string, string]> = [
  ["APPROVE", "Setujui", "Tanpa perubahan"],
  ["CORRECT", "Koreksi", "Ubah lalu setujui"],
  ["RETURN", "Kembalikan", "Minta perbaikan"],
];
const workStatuses: Array<[WorkStatus, string, string]> = [
  ["WORKED", "Bekerja", "Nilai indikator berlaku"],
  ["OFF", "Libur", "Tanpa nilai indikator"],
  ["PERMIT", "Izin", "Tanpa nilai indikator"],
  ["SICK", "Sakit", "Tanpa nilai indikator"],
];

export function ReviewForm({ sheet }: { sheet: { id: string; rowVersion: number; status: string; workStatus: WorkStatus; note: string | null; items: DailyValueView[] } }) {
  const approved = sheet.status === "APPROVED";
  const [decision, setDecision] = useState<Decision>(approved ? "CORRECT" : "APPROVE");
  const [workStatus, setWorkStatus] = useState<WorkStatus>(sheet.workStatus);
  const correcting = decision === "CORRECT";
  const availableDecisions = approved ? decisions.filter(([value]) => value === "CORRECT") : decisions;

  return <ActionForm action={reviewDailySheetAction} className="form-stack">
    <input type="hidden" name="sheetId" value={sheet.id} />
    <input type="hidden" name="rowVersion" value={sheet.rowVersion} />
    <dl className="definition-list"><div><dt>Status yang diajukan</dt><dd>{workStatusLabel(sheet.workStatus)}</dd></div><div><dt>Catatan Supervisor</dt><dd>{sheet.note || "Tidak ada catatan"}</dd></div></dl>
    <fieldset className="field">
      <legend className="field-label">Keputusan Manager</legend>
      <div className={`choice-grid decision${approved ? " single" : ""}`}>{availableDecisions.map(([value, label, description]) => <label className="choice-card" key={value}>
        <input type="radio" name="decision" value={value} checked={decision === value} onChange={() => setDecision(value)} required />
        <strong>{label}</strong><span>{description}</span>
      </label>)}</div>
    </fieldset>
    {correcting ? <fieldset className="field motion-reveal">
      <legend className="field-label">Status kerja efektif</legend>
      <div className="choice-grid">{workStatuses.map(([value, label, description]) => <label className="choice-card" key={value}>
        <input type="radio" name="workStatus" value={value} checked={workStatus === value} onChange={() => setWorkStatus(value)} required />
        <strong>{label}</strong><span>{description}</span>
      </label>)}</div>
    </fieldset> : null}
    {(correcting ? workStatus === "WORKED" : sheet.workStatus === "WORKED") ? <div className="indicator-list motion-reveal">{sheet.items.map((item, index) => <div className="indicator-row" key={item.id}>
      <div className="indicator-copy">
        <span className="indicator-position">Indikator {index + 1} dari {sheet.items.length}</span>
        <h3>{item.name}</h3>
        <p>{item.description || "Periksa nilai awal terhadap target indikator."}</p>
        <div className="indicator-target"><span>Target</span><strong>{item.target} {item.unit}</strong></div>
      </div>
      <div className="indicator-entry">
        <div className="indicator-original"><span>Nilai awal</span><strong>{formatDailyValue(item)}</strong></div>
        {correcting ? <IndicatorValueField item={item} label={item.kind === "RATING" ? "Rating koreksi" : item.kind === "CATEGORY" ? "Nilai + predikat koreksi" : item.kind === "CHECKBOX" ? "Keterpenuhan" : "Nilai koreksi"} /> : null}
      </div>
    </div>)}</div> : null}
    {decision !== "APPROVE" ? <div className="field motion-reveal"><label htmlFor="reason">{decision === "RETURN" ? "Alasan pengembalian" : "Alasan koreksi"}</label><textarea className="control" id="reason" name="reason" maxLength={1000} required /></div> : null}
    <div className="form-actions"><SubmitButton variant={decision === "RETURN" ? "danger" : "primary"} pendingText="Menyimpan review...">{decision === "RETURN" ? "Kembalikan lembar" : decision === "CORRECT" ? "Simpan koreksi dan setujui" : "Setujui lembar"}</SubmitButton></div>
  </ActionForm>;
}

function workStatusLabel(status: WorkStatus) {
  return ({ WORKED: "Bekerja", OFF: "Libur", PERMIT: "Izin", SICK: "Sakit" } as Record<WorkStatus, string>)[status];
}
