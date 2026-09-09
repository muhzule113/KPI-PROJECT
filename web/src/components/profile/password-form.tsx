"use client";

import { FloppyDiskIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import { useActionState, useEffect, useRef } from "react";
import { changeOwnPassword, type SecurityActionState } from "@/app/(workspace)/app/profil/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";

const initial: SecurityActionState = {};

export function PasswordForm() {
  const [state, action, pending] = useActionState(changeOwnPassword, initial);
  const form = useRef<HTMLFormElement>(null);
  useEffect(() => { if (state.success) form.current?.reset(); }, [state.success]);

  return (
    <form ref={form} action={action} className="space-y-4">
      <div className="space-y-2"><Label htmlFor="current-password">Kata sandi saat ini</Label><Input id="current-password" name="currentPassword" type="password" autoComplete="current-password" required maxLength={128} /></div>
      <div className="grid gap-4 sm:grid-cols-2">
        <div className="space-y-2"><Label htmlFor="new-password">Kata sandi baru</Label><Input id="new-password" name="newPassword" type="password" autoComplete="new-password" required minLength={10} maxLength={128} /></div>
        <div className="space-y-2"><Label htmlFor="password-confirmation">Ulangi kata sandi baru</Label><Input id="password-confirmation" name="confirmation" type="password" autoComplete="new-password" required minLength={10} maxLength={128} /></div>
      </div>
      <p className="text-xs text-muted-foreground">Minimal 10 karakter. Setelah berhasil, semua sesi selain perangkat ini dicabut.</p>
      {state.error ? <p role="alert" className="text-sm text-destructive">{state.error}</p> : null}
      {state.success ? <p role="status" className="text-sm text-primary">{state.success}</p> : null}
      <Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <FloppyDiskIcon />}{pending ? "Memperbarui..." : "Ubah kata sandi"}</Button>
    </form>
  );
}
