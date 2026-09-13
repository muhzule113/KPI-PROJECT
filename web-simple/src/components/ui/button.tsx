import * as React from "react";
import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "@/lib/utils";

const buttonVariants = cva(
  "inline-flex min-h-11 items-center justify-center gap-2 rounded-[var(--radius-control)] px-4 text-sm font-semibold transition-[background-color,border-color,color,box-shadow,transform,opacity] duration-[160ms] ease-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background active:scale-[0.98] disabled:pointer-events-none disabled:transform-none disabled:opacity-50 select-none [&>[data-icon=inline-start]]:-ml-0.5 [&>[data-icon=inline-end]]:-mr-0.5",
  {
    variants: {
      variant: {
        primary: "border border-primary bg-primary text-primary-foreground shadow-[var(--button-shadow)] hover:-translate-y-px hover:bg-primary/90 hover:shadow-[var(--shadow-brand-hover)]",
        secondary: "border border-border bg-card text-foreground shadow-[var(--shadow-inset)] hover:-translate-y-px hover:border-[var(--line-hover)] hover:bg-secondary",
        danger: "border border-destructive/35 bg-card text-destructive shadow-[var(--shadow-inset)] hover:bg-destructive/10",
        ghost: "border border-transparent text-muted-foreground hover:bg-muted hover:text-foreground",
      },
      size: { default: "min-h-11", small: "min-h-11 min-w-[44px] px-3 text-xs rounded-[var(--radius-control)]" },
    },
    defaultVariants: { variant: "primary", size: "default" },
  },
);

export function Button({ className, variant, size, asChild, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
  const Component = asChild ? Slot : "button";
  return <Component className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}

export { buttonVariants };
