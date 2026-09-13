"use client";

import { useEffect, useRef, useState, type FormEvent, type MouseEvent } from "react";
import { removeEvidenceAction, saveDailySheetAction, uploadEvidenceAction } from "@/app/(workspace)/app/harian/actions";
import { ActionForm, SubmitButton } from "@/components/action-form";
import { ConfirmAction } from "@/components/confirm-action";
import { IndicatorValueField } from "@/components/indicator-value-field";
import { formatDailyValue, type DailyValueView } from "@/modules/kpi/daily-value-view";
import { Button } from "@/components/ui/button";

type WorkStatus = "WORKED" | "OFF" | "PERMIT" | "SICK";
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
    items: DailyValueView[];
    evidence: Evidence[];
  };
  editable: boolean;
  evidenceEditable: boolean;
  directApproval: boolean;
}) {
  const [workStatus, setWorkStatus] = useState<WorkStatus>(sheet.workStatus ?? "WORKED");
  const [currentIndicator, setCurrentIndicator] = useState(0);
  const [stepError, setStepError] = useState("");
  const focusStepRef = useRef(false);
  const working = workStatus === "WORKED";
  const correctingApproved = sheet.status === "APPROVED" && directApproval;
  const itemCount = sheet.items.length;
  const lastIndicator = Math.max(0, itemCount - 1);
  const activeIndicator = itemCount ? Math.min(currentIndicator, lastIndicator) : 0;

  useEffect(() => {
    if (!focusStepRef.current || !itemCount) return;
    focusStepRef.current = false;
    const frame = window.requestAnimationFrame(() => {
      document.getElementById(indicatorTitleId(sheet.id, activeIndicator))?.focus();
    });
    return () => window.cancelAnimationFrame(frame);
  }, [activeIndicator, itemCount, sheet.id]);

  const goToIndicator = (next: number) => {
    if (!itemCount) return;
    focusStepRef.current = true;
    setStepError("");
    setCurrentIndicator(Math.max(0, Math.min(next, lastIndicator)));
  };

  const missingIndicator = (form: HTMLFormElement, onlyIndex?: number) => {
    const data = new FormData(form);
    const candidates = onlyIndex === undefined ? sheet.items : [sheet.items[onlyIndex]].filter(Boolean);
    return sheet.items.findIndex((item) => {
      if (!candidates.includes(item) || item.kind === "SYSTEM" || item.kind === "IMPORTED") return false;
      if (data.get(`na:${item.id}`) !== null) return false;
      const value = data.get(`value:${item.id}`);
      return value === null || !String(value).trim();
    });
  };

  const focusIndicatorInput = (index: number) => {
    const row = document.getElementById(indicatorRowId(sheet.id, index));
    row?.querySelector<HTMLElement>('[role="combobox"], input[type="number"], input[type="checkbox"]')?.focus();
  };

  const handleNext = (event: MouseEvent<HTMLButtonElement>) => {
    if (!working || !event.currentTarget.form) {
      goToIndicator(activeIndicator + 1);
      return;
    }
    const missing = missingIndicator(event.currentTarget.form, activeIndicator);
    if (missing !== -1) {
      setStepError("Isi nilai indikator ini atau pilih Tidak berlaku hari ini.");
      focusIndicatorInput(activeIndicator);
      return;
    }
    goToIndicator(activeIndicator + 1);
  };

  const handleSubmitCapture = (event: FormEvent<HTMLDivElement>) => {
    const submitter = (event.nativeEvent as SubmitEvent).submitter as HTMLButtonElement | null;
    if (submitter?.name !== "intent" || submitter.value !== "submit" || !working) return;
    const form = event.target as HTMLFormElement;
    const missing = missingIndicator(form);
    if (missing === -1) return;
    event.preventDefault();
    setStepError("Lengkapi semua indikator sebelum mengirim lembar.");
    if (missing === activeIndicator) {
      window.requestAnimationFrame(() => focusIndicatorInput(missing));
    } else {
      focusStepRef.current = true;
      setCurrentIndicator(missing);
    }
  };

  const indicatorRows = (interactive: boolean) => (
    <div className={interactive ? "indicator-list motion-reveal" : "indicator-list"}>
      {sheet.items.map((item, index) => (
        <div
          className="indicator-row"
          data-mobile-active={index === activeIndicator ? "true" : "false"}
          id={indicatorRowId(sheet.id, index)}
          key={item.id}
          onChangeCapture={() => setStepError("")}
        >
          <div className="indicator-copy">
            <span className="indicator-position">Indikator {index + 1} dari {sheet.items.length}</span>
            <h3 id={indicatorTitleId(sheet.id, index)} tabIndex={-1}>{item.name}</h3>
            <p>{item.description || (interactive ? "Masukkan nilai aktual untuk indikator ini." : "Nilai aktual untuk indikator ini.")}</p>
            <div className="indicator-target"><span>Target</span><strong>{item.target} {item.unit}</strong></div>
          </div>
          {interactive
            ? <IndicatorValueField item={item} label={item.kind === "RATING" ? "Rating aktual" : item.kind === "CATEGORY" ? "Nilai + predikat" : item.kind === "CHECKBOX" ? "Keterpenuhan" : "Nilai aktual"} />
            : <div className="indicator-result"><span>Nilai</span><strong>{formatDailyValue(item)}</strong></div>}
          {interactive && index === activeIndicator && stepError ? <p className="form-message error indicator-step-error" role="alert">{stepError}</p> : null}
        </div>
      ))}
    </div>
  );

  const indicatorStepper = (interactive: boolean) => itemCount ? <div className={`indicator-stepper${interactive ? " indicator-stepper-editable" : ""}`}>
    {!interactive ? <Button type="button" variant="secondary" disabled={activeIndicator === 0} onClick={() => goToIndicator(activeIndicator - 1)}>Sebelumnya</Button> : null}
    <div className="indicator-stepper-progress">
      <span aria-live="polite">Indikator {activeIndicator + 1} dari {itemCount}</span>
      <progress max={itemCount} value={activeIndicator + 1} aria-label={`Progres indikator ${activeIndicator + 1} dari ${itemCount}`} />
    </div>
    {!interactive ? <Button type="button" variant="secondary" disabled={activeIndicator >= lastIndicator} onClick={() => goToIndicator(activeIndicator + 1)}>Berikutnya</Button> : null}
  </div> : null;

  if (!editable) return <>
    {sheet.managerReason ? <div className="notice"><strong>Catatan Manager:</strong> {sheet.managerReason}</div> : null}
    <dl className="definition-list sheet-definition"><div><dt>Status kerja</dt><dd>{workStatusLabel(sheet.workStatus)}</dd></div><div><dt>Catatan</dt><dd>{sheet.note || "Tidak ada catatan"}</dd></div></dl>
    {sheet.workStatus === "WORKED" ? <>{indicatorStepper(false)}{indicatorRows(false)}</> : null}
    {sheet.workStatus !== "WORKED" || activeIndicator === lastIndicator ? <EvidenceList evidence={sheet.evidence} removable={false} /> : null}
  </>;

  return <>
    {sheet.managerReason ? <div className="notice"><strong>Catatan Manager:</strong> {sheet.managerReason}</div> : null}
    <div className="daily-sheet-editor" onSubmitCapture={handleSubmitCapture}>
    <ActionForm action={saveDailySheetAction} className="form-stack">
      <input type="hidden" name="sheetId" value={sheet.id} />
      <input type="hidden" name="rowVersion" value={sheet.rowVersion} />
      <fieldset className="field">
        <legend className="field-label">Status kerja</legend>
        <div className="choice-grid work-status-grid">{workStatusChoices.map(([value, label, description]) => <label className="choice-card" key={value}>
          <input type="radio" name="workStatus" value={value} checked={workStatus === value} onChange={() => { setWorkStatus(value); setStepError(""); }} required />
          <strong>{label}</strong><span>{description}</span>
        </label>)}</div>
      </fieldset>
      {itemCount ? <div className={`daily-indicators${working ? "" : " is-hidden"}`}>{indicatorStepper(true)}{indicatorRows(true)}</div> : null}
      {!working ? <p className="help motion-reveal">Nilai indikator tidak diperlukan untuk hari Libur, Izin, atau Sakit.</p> : null}
      <div className="daily-sheet-closing" data-mobile-visible={!working || !itemCount || activeIndicator === lastIndicator ? "true" : "false"}>
        <div className="field"><label htmlFor="note">Catatan</label><textarea className="control" id="note" name="note" maxLength={1000} defaultValue={sheet.note ?? ""} placeholder={working ? "Wajib jika ada rating di bawah target." : "Keterangan status kerja (opsional)."} /></div>
        {correctingApproved ? <div className="field motion-reveal"><label htmlFor="correctionReason">Alasan koreksi</label><textarea className="control" id="correctionReason" name="correctionReason" maxLength={1000} required /></div> : null}
      </div>
      <div className="form-actions daily-desktop-actions">
        {!correctingApproved ? <SubmitButton name="intent" value="save" variant="secondary" pendingText="Menyimpan...">Simpan draf</SubmitButton> : null}
        <SubmitButton name="intent" value="submit" pendingText="Mengirim...">{correctingApproved ? "Simpan koreksi" : directApproval ? "Simpan dan setujui" : "Kirim ke Manager"}</SubmitButton>
      </div>
      <div className="daily-mobile-dock">
        {working && itemCount > 1 ? <Button type="button" variant="secondary" onClick={() => goToIndicator(activeIndicator - 1)} disabled={activeIndicator === 0}>Kembali</Button> : null}
        {!correctingApproved ? <SubmitButton name="intent" value="save" variant="secondary" pendingText="Menyimpan...">Simpan draf</SubmitButton> : null}
        {working && activeIndicator < lastIndicator ? <Button type="button" onClick={handleNext}>Lanjut</Button> : <SubmitButton name="intent" value="submit" pendingText="Mengirim...">{correctingApproved ? "Simpan koreksi" : directApproval ? "Simpan dan setujui" : "Kirim ke Manager"}</SubmitButton>}
      </div>
    </ActionForm>
    </div>
    {!working || !itemCount || activeIndicator === lastIndicator ? <section className="evidence-section">
      <h3 className="evidence-heading">Bukti pendukung <span>({sheet.evidence.length}/3)</span></h3>
      <p className="help">Opsional. JPG, PNG, WEBP, atau PDF; maksimal 10 MB per file.</p>
      {evidenceEditable && sheet.evidence.length < 3 ? <ActionForm action={uploadEvidenceAction} className="form-actions"><input type="hidden" name="sheetId" value={sheet.id} /><input className="control evidence-file" type="file" name="file" accept=".jpg,.jpeg,.png,.webp,.pdf" required /><SubmitButton variant="secondary" pendingText="Memindai...">Unggah dan pindai</SubmitButton></ActionForm> : null}
      <EvidenceList evidence={sheet.evidence} removable={evidenceEditable} />
    </section> : null}
  </>;
}

function indicatorRowId(sheetId: string, index: number) {
  return `daily-indicator-${sheetId}-${index}`;
}

function indicatorTitleId(sheetId: string, index: number) {
  return `${indicatorRowId(sheetId, index)}-title`;
}

function EvidenceList({ evidence, removable }: { evidence: Evidence[]; removable: boolean }) {
  if (!evidence.length) return <p className="help evidence-empty">Belum ada bukti pendukung.</p>;
  return <ul className="evidence-list">{evidence.map((item) => <li className="evidence-item" key={item.id}><span><a href={`/api/evidence/${item.id}`}>{item.fileName}</a> / {item.fileSize}</span>{removable ? <ConfirmAction trigger={<Button variant="danger" size="small">Hapus</Button>} action={removeEvidenceAction} title="Hapus evidence?" description={`${item.fileName} akan dihapus permanen dari lembar ini.`} fields={{ evidenceId: item.id }} confirmLabel="Hapus evidence" variant="danger" /> : null}</li>)}</ul>;
}

function workStatusLabel(status: WorkStatus | null) {
  return status ? ({ WORKED: "Bekerja", OFF: "Libur", PERMIT: "Izin", SICK: "Sakit" } as Record<WorkStatus, string>)[status] : "Belum dipilih";
}
