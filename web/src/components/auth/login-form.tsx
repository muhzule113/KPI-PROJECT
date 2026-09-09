"use client";

import { EyeIcon, EyeSlashIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { authClient } from "@/lib/auth-client";

export function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const [showPassword, setShowPassword] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    const data = new FormData(event.currentTarget);
    const requested = searchParams.get("next");
    const destination = requested?.startsWith("/app") ? requested : "/app";
    const result = await authClient.signIn.email({
      email: String(data.get("email") ?? ""),
      password: String(data.get("password") ?? ""),
      rememberMe: Boolean(data.get("remember")),
    });
    if (result.error) {
      setError(result.error.status === 401 ? "Email atau kata sandi tidak cocok." : "Login gagal. Coba kembali beberapa saat lagi.");
      setBusy(false);
      return;
    }
    router.replace(destination);
    router.refresh();
  }

  return (
    <div>
      <div className="mb-8 lg:hidden">
        <p className="text-sm font-bold tracking-[0.16em] text-primary">KPI OPS</p>
      </div>
      <p className="text-sm font-semibold text-primary">Selamat datang</p>
      <h1 className="mt-1 text-3xl font-semibold tracking-tight">Masuk ke akun Anda</h1>
      <p className="mt-2 text-sm leading-6 text-muted-foreground">Gunakan akun kerja yang sudah didaftarkan oleh administrator.</p>

      <form method="post" onSubmit={submit} className="mt-8 space-y-5">
        <div className="space-y-2">
          <Label htmlFor="email">Email</Label>
          <Input id="email" name="email" type="email" inputMode="email" autoComplete="username" placeholder="nama@perusahaan.com" required autoFocus />
        </div>
        <div className="space-y-2">
          <div className="flex items-center justify-between gap-4">
            <Label htmlFor="password">Kata sandi</Label>
            <Link href="/lupa-kata-sandi" className="text-xs font-semibold text-primary underline-offset-4 hover:underline">Lupa kata sandi?</Link>
          </div>
          <div className="relative">
            <Input id="password" name="password" type={showPassword ? "text" : "password"} autoComplete="current-password" className="pr-12" required />
            <button type="button" onClick={() => setShowPassword((value) => !value)} className="absolute inset-y-0 right-0 grid w-11 place-items-center rounded-r-[10px] text-muted-foreground outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring" aria-label={showPassword ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"}>
              {showPassword ? <EyeSlashIcon aria-hidden="true" /> : <EyeIcon aria-hidden="true" />}
            </button>
          </div>
        </div>
        <label className="flex min-h-11 cursor-pointer items-center gap-3 text-sm text-muted-foreground">
          <input name="remember" type="checkbox" className="size-4 rounded border-input accent-primary" /> Tetap masuk di perangkat ini
        </label>
        {error ? <p role="alert" className="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">{error}</p> : null}
        <Button type="submit" className="w-full" disabled={busy}>
          {busy ? <SpinnerGapIcon className="animate-spin" aria-hidden="true" /> : null}{busy ? "Memeriksa akun..." : "Masuk"}
        </Button>
      </form>
    </div>
  );
}
