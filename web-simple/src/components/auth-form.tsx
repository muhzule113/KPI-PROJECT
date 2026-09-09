"use client";

import { EyeIcon, EyeSlashIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { AuthWorkbenchIllustration } from "@/components/illustrations";
import { Button } from "@/components/ui/button";
import { CheckboxField } from "@/components/ui/form-controls";
import { authClient } from "@/lib/auth-client";
import { USERNAME_INPUT_PATTERN } from "@/lib/username";

export function LoginForm() {
  const router = useRouter();
  const search = useSearchParams();
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    setError("");
    const data = new FormData(event.currentTarget);
    const result = await authClient.signIn.username({
      username: String(data.get("username")),
      password: String(data.get("password")),
      rememberMe: data.get("remember") === "on",
    });
    if (result.error) {
      setError(result.error.status === 401 || result.error.status === 422 ? "Username atau kata sandi tidak cocok." : "Login gagal. Coba lagi beberapa saat.");
      setBusy(false);
      return;
    }
    const requested = search.get("next");
    router.replace(requested?.startsWith("/app") ? requested : "/app");
    router.refresh();
  }

  return <>
    <MobileBrand />
    <header><p className="auth-kicker">Selamat datang</p><h1>Masuk ke KPI Harian</h1><p>Gunakan akun kerja yang dibuat oleh Super Admin.</p></header>
    <form onSubmit={submit} className="form-stack">
      <div className="field"><label htmlFor="username">Username</label><input className="control" id="username" name="username" type="text" autoComplete="username" autoCapitalize="none" spellCheck={false} minLength={3} maxLength={50} pattern={USERNAME_INPUT_PATTERN} title="Gunakan 3–50 karakter: huruf, angka, titik, garis bawah, atau tanda hubung." required autoFocus /></div>
      <div className="field">
        <div className="field-heading"><label htmlFor="password">Kata sandi</label><span>Lupa kata sandi? Hubungi Super Admin.</span></div>
        <PasswordInput id="password" name="password" autoComplete="current-password" />
      </div>
      <CheckboxField name="remember" label="Tetap masuk di perangkat ini" />
      {error ? <p className="form-message error" role="alert">{error}</p> : null}
      <Button type="submit" disabled={busy}>{busy ? <SpinnerGapIcon className="animate-spin" aria-hidden="true" /> : null}{busy ? "Memeriksa akun..." : "Masuk"}</Button>
    </form>
  </>;
}

function PasswordInput({ id, name, autoComplete, minLength, maxLength, autoFocus }: {
  id: string;
  name: string;
  autoComplete: string;
  minLength?: number;
  maxLength?: number;
  autoFocus?: boolean;
}) {
  const [visible, setVisible] = useState(false);
  return <div className="password-control">
    <input className="control" id={id} name={name} type={visible ? "text" : "password"} minLength={minLength} maxLength={maxLength} autoComplete={autoComplete} required autoFocus={autoFocus} />
    <button className="password-toggle" type="button" onClick={() => setVisible((value) => !value)} aria-label={visible ? "Sembunyikan kata sandi" : "Tampilkan kata sandi"} aria-pressed={visible}>
      {visible ? <EyeSlashIcon size={20} aria-hidden="true" /> : <EyeIcon size={20} aria-hidden="true" />}
    </button>
  </div>;
}

function MobileBrand() {
  return <div className="auth-mobile-intro">
    <div className="auth-mobile-brand"><span className="brand-mark">K</span><span><strong>KPI Harian</strong><small>Penilaian manual</small></span></div>
    <div className="auth-mobile-vignette"><AuthWorkbenchIllustration compact /></div>
  </div>;
}
