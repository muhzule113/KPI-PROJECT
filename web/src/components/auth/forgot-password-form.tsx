"use client";

import { EnvelopeSimpleIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import Link from "next/link";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { authClient } from "@/lib/auth-client";

export function ForgotPasswordForm() {
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    const data = new FormData(event.currentTarget);
    await authClient.requestPasswordReset({
      email: String(data.get("email") ?? ""),
      redirectTo: `${window.location.origin}/atur-ulang-kata-sandi`,
    });
    setSent(true);
    setBusy(false);
  }

  if (sent) return (
    <div className="text-center">
      <span className="mx-auto grid size-12 place-items-center rounded-xl bg-accent text-primary"><EnvelopeSimpleIcon size={24} weight="duotone" /></span>
      <h1 className="mt-5 text-2xl font-semibold">Periksa email Anda</h1>
      <p className="mt-2 text-sm leading-6 text-muted-foreground">Jika alamat tersebut terdaftar, tautan pemulihan sudah dikirim.</p>
      <Button asChild variant="outline" className="mt-6"><Link href="/login">Kembali ke login</Link></Button>
    </div>
  );

  return (
    <div>
      <p className="text-sm font-semibold text-primary">Pemulihan akses</p>
      <h1 className="mt-1 text-3xl font-semibold tracking-tight">Lupa kata sandi?</h1>
      <p className="mt-2 text-sm leading-6 text-muted-foreground">Masukkan email kerja. Kami akan mengirim tautan pemulihan yang berlaku selama satu jam.</p>
      <form onSubmit={submit} className="mt-8 space-y-5">
        <div className="space-y-2"><Label htmlFor="email">Email</Label><Input id="email" name="email" type="email" autoComplete="email" required autoFocus /></div>
        <Button type="submit" className="w-full" disabled={busy}>{busy ? <SpinnerGapIcon className="animate-spin" /> : null}{busy ? "Mengirim..." : "Kirim tautan pemulihan"}</Button>
        <Button asChild variant="link" className="w-full"><Link href="/login">Kembali ke login</Link></Button>
      </form>
    </div>
  );
}
