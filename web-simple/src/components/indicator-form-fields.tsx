"use client";

import { useState } from "react";
import { saveIndicatorAction } from "@/app/(workspace)/app/pengaturan/actions";
import { SelectField, type SelectOption } from "@/components/ui/form-controls";
import { WizardActionForm } from "@/components/wizard-action-form";

export type IndicatorFormOption = { label: string; threshold?: string; score?: string; sortOrder: number; isActive: boolean };

export type IndicatorFormValues = {
  id?: string;
  code: string;
  name: string;
  description: string;
  kind: string;
  unit: string;
  aggregation: string;
  direction: string;
  target: string;
  failureLimit: string;
  weight: string;
  sortOrder: number;
  categoryOptions: readonly IndicatorFormOption[];
};

const kindOptions: SelectOption[] = [
  { value: "NUMERIC", label: "Angka" },
  { value: "RATING", label: "Rating 1–5" },
  { value: "CHECKBOX", label: "Centang (ya/tidak)" },
  { value: "CATEGORY", label: "Angka + predikat" },
  { value: "SYSTEM", label: "Nilai sistem" },
  { value: "IMPORTED", label: "Data impor" },
];
const aggregationOptions: SelectOption[] = [
  { value: "SUM", label: "Jumlah" },
  { value: "AVERAGE", label: "Rata-rata" },
  { value: "LATEST", label: "Nilai terakhir" },
  { value: "COUNT", label: "Jumlah hari terisi" },
];
const directionOptions: SelectOption[] = [
  { value: "HIGHER", label: "Lebih tinggi lebih baik" },
  { value: "LOWER", label: "Lebih rendah lebih baik" },
  { value: "ZERO_TOLERANCE", label: "Harus nol" },
];
const SERVER_DECIDED_AGGREGATION: Record<string, true> = { RATING: true, CHECKBOX: true };

const defaultLabels = ["Sangat Baik", "Baik", "Cukup", "Kurang", "Sangat Kurang"];
const defaultThresholds = ["60", "70", "80", "90"];

export function IndicatorFormFields({ versionId, indicator }: { versionId: string; indicator?: IndicatorFormValues }) {
  const [kind, setKind] = useState(indicator?.kind ?? "NUMERIC");
  const [unit, setUnit] = useState(indicator?.unit ?? "");
  const [direction, setDirection] = useState(indicator?.direction ?? "HIGHER");
  const [options, setOptions] = useState<IndicatorFormOption[]>(() => {
    const source = indicator?.categoryOptions ?? [];
    return Array.from({ length: 5 }, (_, index) => ({
      label: source[index]?.label ?? defaultLabels[index],
      threshold: source[index]?.threshold ?? (source[index]?.score ? "" : indicator?.unit === "%" && index < 4 ? String((index + 1) * 10 + 50) : ""),
      sortOrder: index + 1,
      isActive: true,
    }));
  });
  const isCategory = kind === "CATEGORY";
  const lockedAggregation = SERVER_DECIDED_AGGREGATION[kind] === true;

  const thresholds = options.slice(0, 4).map((option) => option.threshold?.trim() ? Number(option.threshold) : Number.NaN);
  const preview = thresholds.every((value, index) => Number.isFinite(value) && (index === 0 || value > thresholds[index - 1]))
    ? direction === "HIGHER"
      ? [
        [`< ${thresholds[0]}`, options[4].label],
        [`${thresholds[0]}–<${thresholds[1]}`, options[3].label],
        [`${thresholds[1]}–<${thresholds[2]}`, options[2].label],
        [`${thresholds[2]}–<${thresholds[3]}`, options[1].label],
        [`≥ ${thresholds[3]}`, options[0].label],
      ]
      : [
        [`≤ ${thresholds[0]}`, options[0].label],
        [`> ${thresholds[0]}–≤${thresholds[1]}`, options[1].label],
        [`> ${thresholds[1]}–≤${thresholds[2]}`, options[2].label],
        [`> ${thresholds[2]}–≤${thresholds[3]}`, options[3].label],
        [`> ${thresholds[3]}`, options[4].label],
      ]
    : [];

  const identity = <>
    <input type="hidden" name="versionId" value={versionId} />
    {indicator?.id ? <input type="hidden" name="id" value={indicator.id} /> : null}
    <div className="form-grid">
      <label className="field"><span className="field-label">Kode indikator</span><input className="control" name="code" defaultValue={indicator?.code ?? ""} required /></label>
      <label className="field"><span className="field-label">Nama indikator</span><input className="control" name="name" defaultValue={indicator?.name ?? ""} required /></label>
    </div>
    <label className="field"><span className="field-label">Deskripsi</span><textarea className="control" name="description" maxLength={500} defaultValue={indicator?.description ?? ""} /></label>
    <div className="form-grid">
      <SelectField label="Jenis nilai" name="kind" defaultValue={kind} options={kindOptions} onValueChange={setKind} required />
      <label className="field"><span className="field-label">Satuan</span><input className="control" name="unit" value={unit} onChange={(event) => { const nextUnit = event.target.value; const previousUnit = unit.trim(); setUnit(nextUnit); setOptions((current) => { if (nextUnit.trim() === "%" && current.slice(0, 4).every((option) => !option.threshold)) return current.map((option, index) => index < 4 ? { ...option, threshold: defaultThresholds[index] } : option); if (previousUnit === "%" && nextUnit.trim() !== "%" && current.slice(0, 4).every((option, index) => option.threshold === defaultThresholds[index])) return current.map((option, index) => index < 4 ? { ...option, threshold: "" } : option); return current; }); }} placeholder="%, unit, komplain" required /></label>
    </div>
  </>;

  const calculation = <>
    {lockedAggregation ? <>
      <input type="hidden" name="aggregation" value="AVERAGE" />
      <input type="hidden" name="direction" value="HIGHER" />
      <p className="help">Akumulasi bulanan otomatis Rata-rata dan arah skor Lebih tinggi lebih baik untuk jenis nilai ini.</p>
    </> : <div className="form-grid">
      <SelectField label="Akumulasi bulanan" name="aggregation" defaultValue={indicator?.aggregation ?? "SUM"} options={aggregationOptions} required />
      <SelectField label="Arah skor" name="direction" defaultValue={indicator?.direction ?? "HIGHER"} options={directionOptions} onValueChange={setDirection} required />
    </div>}
    <div className="form-grid">
      {kind === "CHECKBOX"
        ? <><input type="hidden" name="target" value="100" /><p className="field"><span className="field-label">Target</span><span className="help">Indikator centang selalu bertarget 100% hari terpenuhi.</span></p></>
        : <label className="field"><span className="field-label">Target</span><input className="control" name="target" type="number" step="any" min={kind === "RATING" ? 1 : undefined} max={kind === "RATING" ? 5 : undefined} defaultValue={indicator?.target ?? "0"} required /></label>}
      {kind === "RATING" ? <p className="help">Target rating berada pada rentang 1 sampai 5.</p> : null}
      {isCategory ? <p className="help">Target dan arah tetap mengikuti rumus KPI. Predikat hanya label tampilan dari nilai harian.</p> : null}
      {kind === "NUMERIC" || kind === "CATEGORY" || kind === "SYSTEM" || kind === "IMPORTED"
        ? <label className="field"><span className="field-label">Batas gagal (untuk arah lebih rendah lebih baik)</span><input className="control" name="failureLimit" type="number" step="any" defaultValue={indicator?.failureLimit ?? ""} /></label>
        : null}
      <label className="field"><span className="field-label">Bobot (%)</span><input className="control" name="weight" type="number" step="0.01" min={0.01} max={100} defaultValue={indicator?.weight ?? ""} required /></label>
      <label className="field"><span className="field-label">Urutan</span><input className="control" name="sortOrder" type="number" min={1} max={999} defaultValue={indicator?.sortOrder ?? 1} required /></label>
    </div>
    {kind === "SYSTEM" || kind === "IMPORTED" ? <p className="help">Nilai diisi otomatis oleh sistem atau proses impor; Supervisor tidak memasukkannya secara manual.</p> : null}
  </>;

  const categories = <div className="form-stack">
    <p className="help">Supervisor mengetik angka sesuai satuan indikator. Label predikat ditampilkan otomatis berdasarkan empat batas berikut.</p>
    {options.map((option, index) => <div className="form-grid" key={index}>
      <input type="hidden" name="categoryKey" value={index + 1} />
      <label className="field"><span className="field-label">Tingkat {index + 1} · label</span><input className="control" name={`categoryLabel:${index + 1}`} value={option.label} onChange={(event) => setOptions((current) => current.map((candidate, candidateIndex) => candidateIndex === index ? { ...candidate, label: event.target.value } : candidate))} required /></label>
      {index < 4 ? <label className="field"><span className="field-label">Batas minimum</span><input className="control" name={`categoryThreshold:${index + 1}`} type="number" step="0.0001" min={0} max={unit === "%" ? 100 : 1000000000} value={option.threshold ?? ""} onChange={(event) => setOptions((current) => current.map((candidate, candidateIndex) => candidateIndex === index ? { ...candidate, threshold: event.target.value } : candidate))} required /></label> : <p className="help">Rentang terbuka (tanpa batas atas).</p>}
    </div>)}
    {preview.length ? <div className="notice" aria-live="polite"><strong>Preview rentang ({direction === "HIGHER" ? "lebih tinggi lebih baik" : "lebih rendah / nol lebih baik"})</strong><ul>{preview.map(([range, label]) => <li key={range}>{range} {unit} → {label}</li>)}</ul></div> : <p className="help">Isi empat batas berurutan untuk melihat preview rentang.</p>}
  </div>;

  const steps = [
    { title: "Identitas", content: identity },
    { title: "Perhitungan", content: calculation },
    ...(isCategory ? [{ title: "Predikat", description: "Atur lima label dan empat batas rentang.", content: categories }] : []),
  ];

  return <WizardActionForm action={saveIndicatorAction} steps={steps} submitLabel={indicator ? "Simpan perubahan" : "Tambah indikator"} />;
}
