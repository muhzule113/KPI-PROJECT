"use client";

import { useId } from "react";
import { SelectField } from "@/components/ui/form-controls";

type IndicatorKind = "NUMERIC" | "RATING";

const ratingOptions = [1, 2, 3, 4, 5].map((value) => ({
  value: String(value),
  label: `Rating ${value}`,
}));

export function IndicatorValueField({ itemId, kind, unit, defaultValue, label }: {
  itemId: string;
  kind: IndicatorKind;
  unit: string;
  defaultValue: string | null;
  label: string;
}) {
  const inputId = useId();

  return (
    <div className="indicator-value-field">
      <input type="hidden" name="itemId" value={itemId} />
      {kind === "RATING" ? (
        <SelectField
          className="indicator-rating-field"
          label={label}
          name="value"
          options={ratingOptions}
          defaultValue={defaultValue ?? ""}
          placeholder="Pilih rating 1 sampai 5"
          required
        />
      ) : (
        <div className="field">
          <label htmlFor={inputId}>{label}</label>
          <div className="value-input-shell">
            <input
              className="control"
              id={inputId}
              name="value"
              type="number"
              inputMode="decimal"
              min={0}
              max={unit === "%" ? 100 : 1000000000}
              step="any"
              defaultValue={defaultValue ?? ""}
              required
            />
            <span className="value-unit" aria-hidden="true">{unit}</span>
          </div>
        </div>
      )}
    </div>
  );
}
