"use client";

import * as Dialog from "@radix-ui/react-dialog";
import { XIcon } from "@phosphor-icons/react";
import { useRouter } from "next/navigation";
import { createContext, useContext, useRef, useState, type ReactNode } from "react";
import { cn } from "@/lib/utils";

const DialogControlContext = createContext<{ close: () => void } | null>(null);

export function FormDialog({ trigger, title, description, children, size = "medium", defaultOpen = false, closeHref }: {
  trigger?: ReactNode;
  title: string;
  description?: string;
  children: ReactNode;
  size?: "small" | "medium" | "large";
  defaultOpen?: boolean;
  closeHref?: string;
}) {
  const router = useRouter();
  const [open, setOpen] = useState(defaultOpen);
  const titleRef = useRef<HTMLHeadingElement>(null);
  const changeOpen = (nextOpen: boolean) => {
    setOpen(nextOpen);
    if (!nextOpen && closeHref) router.replace(closeHref, { scroll: false });
  };

  return (
    <Dialog.Root open={open} onOpenChange={changeOpen}>
      {trigger ? <Dialog.Trigger asChild>{trigger}</Dialog.Trigger> : null}
      <Dialog.Portal>
        <Dialog.Overlay className="dialog-overlay" />
        <Dialog.Content className={cn("dialog-content", `dialog-${size}`)} onOpenAutoFocus={(event) => { event.preventDefault(); titleRef.current?.focus(); }}>
          <header className="dialog-header">
            <div>
              <Dialog.Title ref={titleRef} tabIndex={-1}>{title}</Dialog.Title>
              {description ? <Dialog.Description>{description}</Dialog.Description> : null}
            </div>
            <Dialog.Close className="dialog-close" aria-label="Tutup dialog"><XIcon size={20} aria-hidden="true" /></Dialog.Close>
          </header>
          <DialogControlContext.Provider value={{ close: () => changeOpen(false) }}>
            {children}
          </DialogControlContext.Provider>
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  );
}

export function DialogCancel({ children = "Batal" }: { children?: ReactNode }) {
  return <Dialog.Close className="dialog-cancel" type="button">{children}</Dialog.Close>;
}

export function useDialogControl() {
  return useContext(DialogControlContext);
}
