"use client";

import * as Dialog from "@radix-ui/react-dialog";
import * as Select from "@radix-ui/react-select";
import { CalendarBlankIcon, CaretDownIcon, CaretUpIcon, CheckIcon, XIcon } from "@phosphor-icons/react";
import { DayPicker, type Matcher } from "@daypicker/react";
import { id } from "@daypicker/react/locale";
import { useId, useRef, useState, type InputHTMLAttributes, type ReactNode } from "react";
import { Button } from "@/components/ui/button";
import { dateFromIso, dateToIso, dateWithinBounds } from "@/lib/date-picker";
import { todayInMakassar } from "@/lib/date";
import { SEARCH_MAX_LENGTH } from "@/lib/search";
import { cn, formatDate } from "@/lib/utils";

export type SelectOption = { value: string; label: string };

export function SearchField({ name = "q", label = "Cari", defaultValue = "", placeholder, maxLength = SEARCH_MAX_LENGTH, className }: {
  name?: string;
  label?: string;
  defaultValue?: string;
  placeholder?: string;
  maxLength?: number;
  className?: string;
}) {
  return <label className={cn("field", className)}>
    <span className="field-label">{label}</span>
    <input className="control" type="search" name={name} defaultValue={defaultValue} placeholder={placeholder} maxLength={maxLength} />
  </label>;
}

export function SelectField({ name, label, options, value, defaultValue, onValueChange, placeholder = "Pilih opsi", required, disabled, help, className }: {
  name: string;
  label: string;
  options: readonly SelectOption[];
  value?: string;
  defaultValue?: string;
  onValueChange?: (value: string) => void;
  placeholder?: string;
  required?: boolean;
  disabled?: boolean;
  help?: string;
  className?: string;
}) {
  const idValue = useId();
  const triggerRef = useRef<HTMLButtonElement>(null);
  const initial = defaultValue ?? (required ? options[0]?.value ?? "" : "");
  const [internalValue, setInternalValue] = useState(initial);
  const selected = value === undefined ? internalValue : value;
  const setSelected = (next: string) => {
    if (value === undefined) setInternalValue(next);
    onValueChange?.(next);
  };

  return (
    <div className={cn("field", className)}>
      <label id={`${idValue}-label`}>{label}{required ? <span className="required-mark" aria-hidden="true"> *</span> : null}</label>
      <div className="select-field">
        <Select.Root value={selected} onValueChange={setSelected} disabled={disabled}>
          <Select.Trigger ref={triggerRef} className="control select-trigger" aria-labelledby={`${idValue}-label`} aria-describedby={help ? `${idValue}-help` : undefined} aria-invalid={required && !selected}>
            <Select.Value placeholder={placeholder} />
            <Select.Icon><CaretDownIcon size={17} aria-hidden="true" /></Select.Icon>
          </Select.Trigger>
          <Select.Portal>
            <Select.Content className="select-content" position="popper" sideOffset={6} collisionPadding={12}>
              <Select.ScrollUpButton className="select-scroll"><CaretUpIcon aria-hidden="true" /></Select.ScrollUpButton>
              <Select.Viewport className="select-viewport">
                {options.map((option) => (
                  <Select.Item className="select-item" value={option.value} key={option.value}>
                    <Select.ItemIndicator><CheckIcon size={16} weight="bold" aria-hidden="true" /></Select.ItemIndicator>
                    <Select.ItemText>{option.label}</Select.ItemText>
                  </Select.Item>
                ))}
              </Select.Viewport>
              <Select.ScrollDownButton className="select-scroll"><CaretDownIcon aria-hidden="true" /></Select.ScrollDownButton>
            </Select.Content>
          </Select.Portal>
        </Select.Root>
        {!required && selected && !disabled ? <button className="control-clear" type="button" onClick={() => setSelected("")} aria-label={`Kosongkan ${label}`}><XIcon size={15} aria-hidden="true" /></button> : null}
      </div>
      <input type="hidden" name={name} value={selected} />
      {required ? <input className="validation-proxy" value={selected} onChange={() => undefined} required tabIndex={-1} aria-hidden="true" onInvalid={(event) => { event.preventDefault(); triggerRef.current?.focus(); }} /> : null}
      {help ? <small className="help" id={`${idValue}-help`}>{help}</small> : null}
    </div>
  );
}

export function CheckboxField({ label, description, className, ...props }: Omit<InputHTMLAttributes<HTMLInputElement>, "type"> & { label: ReactNode; description?: string }) {
  return (
    <label className={cn("check-field", className)}>
      <input type="checkbox" {...props} />
      <span className="check-box" aria-hidden="true"><CheckIcon size={15} weight="bold" /></span>
      <span className="check-copy"><strong>{label}</strong>{description ? <small>{description}</small> : null}</span>
    </label>
  );
}

export function DatePickerField({ name, label, defaultValue = "", value, onValueChange, min, max, required, disabled, help, className }: {
  name: string;
  label: string;
  defaultValue?: string;
  value?: string;
  onValueChange?: (value: string) => void;
  min?: string;
  max?: string;
  required?: boolean;
  disabled?: boolean;
  help?: string;
  className?: string;
}) {
  const idValue = useId();
  const triggerRef = useRef<HTMLButtonElement>(null);
  const [open, setOpen] = useState(false);
  const [internalValue, setInternalValue] = useState(defaultValue);
  const selectedValue = value === undefined ? internalValue : value;
  const selected = dateFromIso(selectedValue);
  const minDate = dateFromIso(min);
  const maxDate = dateFromIso(max);
  const todayValue = todayInMakassar();
  const disabledDays = [minDate ? { before: minDate } : null, maxDate ? { after: maxDate } : null].filter(Boolean) as Matcher[];
  const setSelected = (next: string) => {
    if (value === undefined) setInternalValue(next);
    onValueChange?.(next);
  };

  return (
    <div className={cn("field", className)}>
      <label id={`${idValue}-label`}>{label}{required ? <span className="required-mark" aria-hidden="true"> *</span> : null}</label>
      <Dialog.Root open={open} onOpenChange={setOpen}>
        <Dialog.Trigger asChild>
          <button ref={triggerRef} type="button" className="control date-trigger" disabled={disabled} aria-labelledby={`${idValue}-label`} aria-describedby={help ? `${idValue}-help` : undefined} data-invalid={required && !selectedValue}>
            <CalendarBlankIcon size={18} aria-hidden="true" />
            <span data-placeholder={!selectedValue}>{selectedValue ? formatDate(`${selectedValue}T00:00:00.000Z`, { dateStyle: "long" }) : "Pilih tanggal"}</span>
          </button>
        </Dialog.Trigger>
        <Dialog.Portal>
          <Dialog.Overlay className="dialog-overlay calendar-overlay" />
          <Dialog.Content className="calendar-dialog" onOpenAutoFocus={(event) => event.preventDefault()}>
            <header className="dialog-header calendar-header">
              <div>
                <Dialog.Title>Pilih {label.toLowerCase()}</Dialog.Title>
                <Dialog.Description>Gunakan tombol panah untuk berpindah tanggal.</Dialog.Description>
              </div>
              <Dialog.Close className="dialog-close" aria-label="Tutup kalender"><XIcon size={20} aria-hidden="true" /></Dialog.Close>
            </header>
            <DayPicker
              animate
              autoFocus
              mode="single"
              locale={id}
              timeZone="Asia/Makassar"
              selected={selected}
              defaultMonth={selected ?? maxDate ?? dateFromIso(todayValue)}
              startMonth={minDate}
              endMonth={maxDate}
              disabled={disabledDays}
              onSelect={(day) => {
                if (!day) return;
                setSelected(dateToIso(day));
                setOpen(false);
                triggerRef.current?.focus();
              }}
            />
            <footer className="calendar-actions">
              <Button type="button" variant="ghost" onClick={() => setSelected("")} disabled={required || !selectedValue}>Kosongkan</Button>
              <Button type="button" variant="secondary" onClick={() => { setSelected(todayValue); setOpen(false); }} disabled={!dateWithinBounds(todayValue, min, max)}>Hari ini</Button>
            </footer>
          </Dialog.Content>
        </Dialog.Portal>
      </Dialog.Root>
      <input type="hidden" name={name} value={selectedValue} />
      {required ? <input className="validation-proxy" value={selectedValue} onChange={() => undefined} required tabIndex={-1} aria-hidden="true" onInvalid={(event) => { event.preventDefault(); triggerRef.current?.focus(); }} /> : null}
      {help ? <small className="help" id={`${idValue}-help`}>{help}</small> : null}
    </div>
  );
}
