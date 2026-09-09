"use client";

import { SpinnerGapIcon } from "@phosphor-icons/react";
import { useActionState, useEffect, useRef, type ReactNode } from "react";
import { useFormStatus } from "react-dom";
import { Button } from "@/components/ui/button";
import { useDialogControl } from "@/components/ui/form-dialog";
import { useToast } from "@/components/toast-provider";
import { cn } from "@/lib/utils";

export type ActionState = { error?: string; success?: string };
export type FormAction = (state: ActionState, formData: FormData) => Promise<ActionState>;

export function ActionForm({ action, children, className, closeOnSuccess = false, resetOnSuccess = false }: {
  action: FormAction;
  children: ReactNode;
  className?: string;
  closeOnSuccess?: boolean;
  resetOnSuccess?: boolean;
}) {
  const [state, formAction, pending] = useActionState(action, {});
  const errorRef = useRef<HTMLParagraphElement>(null);
  const formRef = useRef<HTMLFormElement>(null);
  const wasPending = useRef(false);
  const dialog = useDialogControl();
  const notify = useToast();

  useEffect(() => {
    if (pending) {
      wasPending.current = true;
      return;
    }
    if (!wasPending.current) return;
    wasPending.current = false;
    if (state.error) errorRef.current?.focus();
    if (!state.success) return;
    notify(state.success);
    if (resetOnSuccess) formRef.current?.reset();
    if (closeOnSuccess) dialog?.close();
  }, [pending, state.error, state.success, notify, resetOnSuccess, closeOnSuccess, dialog]);

  return (
    <form ref={formRef} action={formAction} className={className} onSubmit={() => { wasPending.current = true; }}>
      {children}
      {state.error ? <p ref={errorRef} className="form-message error" role="alert" tabIndex={-1}>{state.error}</p> : null}
    </form>
  );
}

export function SubmitButton({ children, pendingText = "Menyimpan...", variant = "primary", className, name, value }: {
  children: ReactNode;
  pendingText?: string;
  variant?: "primary" | "secondary" | "danger" | "ghost";
  className?: string;
  name?: string;
  value?: string;
}) {
  const { pending } = useFormStatus();
  return <Button type="submit" name={name} value={value} variant={variant} className={cn(className)} disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" aria-hidden="true" /> : null}{pending ? pendingText : children}</Button>;
}
