import * as React from "react";
import { cn } from "@/lib/utils";

function Input({ className, type, ...props }: React.ComponentProps<"input">) {
  return <input type={type} data-slot="input" className={cn("control min-h-11 w-full min-w-0", className)} {...props} />;
}

export { Input };
