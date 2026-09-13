import * as React from "react";
import { cn } from "@/lib/utils";

function FieldGroup({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div data-slot="field-group" className={cn("flex flex-col gap-4", className)} {...props} />;
}

function Field({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div data-slot="field" className={cn("field flex flex-col gap-2", className)} {...props} />;
}

function FieldLabel({ className, ...props }: React.LabelHTMLAttributes<HTMLLabelElement>) {
  return <label data-slot="field-label" className={cn("field-label", className)} {...props} />;
}

function FieldDescription({ className, ...props }: React.HTMLAttributes<HTMLParagraphElement>) {
  return <p data-slot="field-description" className={cn("help", className)} {...props} />;
}

function FieldError({ className, children, ...props }: React.HTMLAttributes<HTMLParagraphElement>) {
  if (!children) return null;
  return <p data-slot="field-error" role="alert" className={cn("form-message error", className)} {...props}>{children}</p>;
}

export { FieldGroup, Field, FieldLabel, FieldDescription, FieldError };
