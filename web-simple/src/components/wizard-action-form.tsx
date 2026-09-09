"use client";

import { ArrowLeftIcon, ArrowRightIcon } from "@phosphor-icons/react";
import { useEffect, useRef, useState, type ReactNode } from "react";
import { ActionForm, SubmitButton, type FormAction } from "@/components/action-form";
import { Button } from "@/components/ui/button";
import { DialogCancel } from "@/components/ui/form-dialog";

type Step = { title: string; description?: string; content: ReactNode };

export function WizardActionForm({ action, steps, submitLabel = "Simpan", pendingText, className = "form-stack" }: {
  action: FormAction;
  steps: readonly Step[];
  submitLabel?: string;
  pendingText?: string;
  className?: string;
}) {
  const [step, setStep] = useState(0);
  const panelRef = useRef<HTMLDivElement>(null);
  const headingRef = useRef<HTMLHeadingElement>(null);

  useEffect(() => {
    headingRef.current?.focus();
  }, [step]);

  const next = () => {
    const controls = panelRef.current?.querySelectorAll<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>("input, select, textarea");
    for (const control of controls ?? []) {
      if (!control.disabled && !control.checkValidity()) {
        control.reportValidity();
        return;
      }
    }
    setStep((current) => Math.min(current + 1, steps.length - 1));
  };

  return (
    <ActionForm action={action} className={className} closeOnSuccess resetOnSuccess>
      <div className="wizard-progress" aria-label={`Langkah ${step + 1} dari ${steps.length}`}>
        {steps.map((item, index) => <span key={item.title} data-active={index <= step}><b>{index + 1}</b><span>{item.title}</span></span>)}
      </div>
      {steps.map((item, index) => (
        <section className="wizard-step" key={item.title} hidden={index !== step} ref={index === step ? panelRef : undefined}>
          <h3 ref={index === step ? headingRef : undefined} tabIndex={-1}>{item.title}</h3>
          {item.description ? <p>{item.description}</p> : null}
          {item.content}
        </section>
      ))}
      <footer className="dialog-actions">
        <DialogCancel />
        {step > 0 ? <Button type="button" variant="secondary" onClick={() => setStep((current) => current - 1)}><ArrowLeftIcon aria-hidden="true" />Kembali</Button> : null}
        {step < steps.length - 1 ? <Button type="button" onClick={next}>Lanjut<ArrowRightIcon aria-hidden="true" /></Button> : <SubmitButton pendingText={pendingText}>{submitLabel}</SubmitButton>}
      </footer>
    </ActionForm>
  );
}
