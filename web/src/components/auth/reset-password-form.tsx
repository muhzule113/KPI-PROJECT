"use client";

import { CheckCircleIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { authClient } from "@/lib/auth-client";

export function ResetPasswordForm() {
  const token = useSearchParams().get("token");
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!token) return;
    const data = new FormData(event.currentTarget);
    const password = String(data.get("password") ?? "");
    const confirmation = String(data.get("confirmation") ?? "");
    if (password !== confirmation) return setError("Konfirmasi kata sandi tidak cocok.");
    setBusy(true);
    setError("");
    const result = await authClient.resetPassword({ newPassword: password, token });
    if (result.error) setError("Tautan tidak valid atau sudah kedaluwarsa.");
    else setDone(true);
    setBusy(false);
  }

  if (!token) return <div><h1 className="text-2xl font-semibold">Tautan tidak valid</h1><p className="mt-2 text-sm text-muted-foreground">Minta tautan pemulihan yang baru.</p><Button asChild className="mt-6"><Link href="/lupa-kata-sandi">Minta tautan baru</Link></Button></div>;
  if (done) return <div className="text-center"><CheckCircleIcon className="mx-auto text-primary" size={48} weight="duotone" /><h1 className="mt-5 text-2xl font-semibold">Kata sandi diperbarui</h1><p className="mt-2 text-sm text-muted-foreground">Anda dapat masuk menggunakan kata sandi baru.</p><Button asChild className="mt-6"><Link href="/login">Masuk sekarang</Link></Button></div>;

  return (
    <div>
      <p className="text-sm font-semibold text-primary">Pemulihan akses</p>
      <h1 className="mt-1 text-3xl font-semibold tracking-tight">Buat kata sandi baru</h1>
      <p className="mt-2 text-sm text-muted-foreground">Gunakan sedikitnya 10 karakter.</p>
      <form onSubmit={submit} className="mt-8 space-y-5">
        <div className="space-y-2"><Label htmlFor="password">Kata sandi baru</Label><Input id="password" name="password" type="password" minLength={10} maxLength={128} autoComplete="new-password" required autoFocus /></div>
        <div className="space-y-2"><Label htmlFor="confirmation">Ulangi kata sandi</Label><Input id="confirmation" name="confirmation" type="password" minLength={10} maxLength={128} autoComplete="new-password" required /></div>
        {error ? <p role="alert" className="text-sm text-destructive">{error}</p> : null}
        <Button type="submit" className="w-full" disabled={busy}>{busy ? <SpinnerGapIcon className="animate-spin" /> : null}{busy ? "Menyimpan..." : "Simpan kata sandi"}</Button>
      </form>
    </div>
  );
}
