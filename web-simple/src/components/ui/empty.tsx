import * as React from "react";
import { cn } from "@/lib/utils";

function Empty({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div data-slot="empty" className={cn("flex min-h-56 flex-col items-center justify-center gap-4 rounded-[var(--radius-panel)] border border-dashed border-border bg-card/70 px-6 py-10 text-center", className)} {...props} />;
}

function EmptyMedia({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div data-slot="empty-media" className={cn("flex size-12 items-center justify-center rounded-2xl border border-primary/20 bg-primary/10 text-primary", className)} {...props} />;
}

function EmptyHeader({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div data-slot="empty-header" className={cn("flex max-w-md flex-col gap-1.5", className)} {...props} />;
}

function EmptyTitle({ className, ...props }: React.HTMLAttributes<HTMLHeadingElement>) {
  return <h2 data-slot="empty-title" className={cn("text-base font-semibold", className)} {...props} />;
}

function EmptyDescription({ className, ...props }: React.HTMLAttributes<HTMLParagraphElement>) {
  return <p data-slot="empty-description" className={cn("text-sm leading-relaxed text-muted-foreground", className)} {...props} />;
}

function EmptyContent({ className, ...props }: React.HTMLAttributes<HTMLDivElement>) {
  return <div data-slot="empty-content" className={cn("flex items-center justify-center gap-2", className)} {...props} />;
}

export { Empty, EmptyMedia, EmptyHeader, EmptyTitle, EmptyDescription, EmptyContent };
