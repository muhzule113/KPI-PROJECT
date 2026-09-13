import * as React from "react";
import { Slot } from "@radix-ui/react-slot";
import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "@/lib/utils";

const buttonVariants = cva(
  "inline-flex min-h-11 items-center justify-center gap-2 rounded-[9px] px-4 text-sm font-semibold transition-[background-color,border-color,color,box-shadow,transform] duration-150 ease-out focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--accent)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--canvas)] active:translate-y-px disabled:pointer-events-none disabled:transform-none disabled:opacity-50",
  {
    variants: {
      variant: {
        primary: "border border-[var(--accent)] bg-[var(--accent)] text-[var(--primary-foreground)] shadow-[var(--button-shadow)] hover:-translate-y-px hover:bg-[var(--primary-dark)]",
        secondary: "border border-[var(--line-strong)] bg-[var(--surface)] text-[var(--ink)] hover:border-[var(--muted)] hover:bg-[var(--surface-soft)]",
        danger: "border border-[var(--danger)] bg-[var(--surface)] text-[var(--danger)] hover:bg-[var(--danger-soft)]",
        ghost: "text-[var(--muted)] hover:bg-[var(--surface-soft)] hover:text-[var(--ink)]",
      },
      size: { default: "min-h-11", small: "min-h-11 min-w-11 px-3 text-xs" },
    },
    defaultVariants: { variant: "primary", size: "default" },
  },
);

export function Button({ className, variant, size, asChild, ...props }: React.ButtonHTMLAttributes<HTMLButtonElement> & VariantProps<typeof buttonVariants> & { asChild?: boolean }) {
  const Component = asChild ? Slot : "button";
  return <Component className={cn(buttonVariants({ variant, size }), className)} {...props} />;
}

export { buttonVariants };
