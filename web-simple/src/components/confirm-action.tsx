import type { ReactNode } from "react";
import { ActionForm, SubmitButton, type FormAction } from "@/components/action-form";
import { DialogCancel, FormDialog } from "@/components/ui/form-dialog";

export function ConfirmAction({ trigger, action, title, description, fields, confirmLabel, variant = "primary" }: {
  trigger: ReactNode;
  action: FormAction;
  title: string;
  description: string;
  fields?: Record<string, string>;
  confirmLabel: string;
  variant?: "primary" | "danger";
}) {
  return (
    <FormDialog trigger={trigger} title={title} description={description} size="small">
      <ActionForm action={action} className="dialog-body" closeOnSuccess>
        {Object.entries(fields ?? {}).map(([name, value]) => <input type="hidden" name={name} value={value} key={name} />)}
        <footer className="dialog-actions">
          <DialogCancel />
          <SubmitButton variant={variant} pendingText="Memproses...">{confirmLabel}</SubmitButton>
        </footer>
      </ActionForm>
    </FormDialog>
  );
}
