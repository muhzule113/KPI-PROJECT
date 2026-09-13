"use client";

import { useId, useState } from "react";
import { CheckboxField, SelectField } from "@/components/ui/form-controls";
import type { DailyValueView } from "@/modules/kpi/daily-value-view";
import { resolveCategoryBand } from "@/modules/kpi/category-options";

const ratingOptions = [1, 2, 3, 4, 5].map((value) => ({ value: String(value), label: `Rating ${value}` }));
const booleanOptions = [
  { value: "1", label: "Ya / terpenuhi" },
  { value: "0", label: "Tidak / tidak terpenuhi" },
];

export function IndicatorValueField({ item, label }: { item: DailyValueView; label: string }) {
  const inputId = useId();
  const [notApplicable, setNotApplicable] = useState(item.status === "NOT_APPLICABLE");
  const [categoryValue, setCategoryValue] = useState(item.value ?? "");

  if (item.kind === "SYSTEM" || item.kind === "IMPORTED") {
    return (
      <div className="indicator-value-field">
        <input type="hidden" name="itemId" value={item.id} />
        <p className="help">Nilai diisi otomatis oleh sistem pada saat perhitungan bulanan.</p>
      </div>
    );
  }

  return (
    <div className="indicator-value-field">
      <input type="hidden" name="itemId" value={item.id} />

      {item.kind === "CATEGORY" ? (
        <div className="field">
          <label htmlFor={inputId}>{label}</label>
          <div className="value-input-shell">
            <input
              className="control"
              id={inputId}
              name={`value:${item.id}`}
              type="number"
              inputMode="decimal"
              min={0}
              max={item.unit === "%" ? 100 : 1000000000}
              step="any"
              value={categoryValue}
              onChange={(event) => setCategoryValue(event.target.value)}
              disabled={notApplicable}
            />
            <span className="value-unit" aria-hidden="true">{item.unit}</span>
          </div>
          <p className="help" aria-live="polite">
            {categoryValue.trim() && Number.isFinite(Number(categoryValue)) && item.bands.length
              ? resolveCategoryBand(categoryValue, item.bands, item.direction)?.label ?? "Rentang belum tersedia"
              : ""}
          </p>
        </div>
      ) : item.kind === "RATING" ? (
        <SelectField
          className="indicator-rating-field"
          label={label}
          name={`value:${item.id}`}
          options={ratingOptions}
          defaultValue={item.value ?? ""}
          placeholder="Pilih rating 1 sampai 5"
          disabled={notApplicable}
        />
      ) : item.kind === "CHECKBOX" ? (
        <SelectField
          label={label}
          name={`value:${item.id}`}
          options={booleanOptions}
          defaultValue={item.value ?? ""}
          placeholder="Pilih terpenuhi atau tidak"
          disabled={notApplicable}
        />
      ) : (
        <div className="field">
          <label htmlFor={inputId}>{label}</label>
          <div className="value-input-shell">
            <input
              className="control"
              id={inputId}
              name={`value:${item.id}`}
              type="number"
              inputMode="decimal"
              min={0}
              max={item.unit === "%" ? 100 : 1000000000}
              step="any"
              defaultValue={item.value ?? ""}
              disabled={notApplicable}
            />
            <span className="value-unit" aria-hidden="true">{item.unit}</span>
          </div>
        </div>
      )}

      <CheckboxField
        label="Tidak berlaku hari ini"
        description="Biarkan kosong bila penilaian belum diisi."
        name={`na:${item.id}`}
        defaultChecked={notApplicable}
        onChange={(event) => setNotApplicable(event.target.checked)}
      />
    </div>
  );
}
