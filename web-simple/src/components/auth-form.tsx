"use client";

import { CheckCircleIcon, EyeIcon, EyeSlashIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import Link from "next/link";
import { useRouter, useSearchParams } from "next/navigation";
import { useState } from "react";
import { AuthWorkbenchIllustration } from "@/components/illustrations";
import { Button } from "@/components/ui/button";
import { CheckboxField } from "@/components/ui/form-controls";
import { authClient } from "@/lib/auth-client";

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
    const result = await authClient.signIn.email({
      email: String(data.get("email")),
      password: String(data.get("password")),
      rememberMe: data.get("remember") === "on",
    });
    if (result.error) {
      setError(result.error.status === 401 ? "Email atau kata sandi tidak cocok." : "Login gagal. Coba lagi beberapa saat.");
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
      <div className="field"><label htmlFor="email">Email</label><input className="control" id="email" name="email" type="email" inputMode="email" autoComplete="username" required autoFocus /></div>
      <div className="field">
        <div className="field-heading"><label htmlFor="password">Kata sandi</label><Link href="/lupa-sandi">Lupa sandi?</Link></div>
        <PasswordInput id="password" name="password" autoComplete="current-password" />
      </div>
      <CheckboxField name="remember" label="Tetap masuk di perangkat ini" />
      {error ? <p className="form-message error" role="alert">{error}</p> : null}
      <Button type="submit" disabled={busy}>{busy ? <SpinnerGapIcon className="animate-spin" aria-hidden="true" /> : null}{busy ? "Memeriksa akun..." : "Masuk"}</Button>
    </form>
  </>;
}

export function ForgotPasswordForm() {
  const [busy, setBusy] = useState(false);
  const [sent, setSent] = useState(false);

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy(true);
    const data = new FormData(event.currentTarget);
    await authClient.requestPasswordReset({ email: String(data.get("email")), redirectTo: `${window.location.origin}/reset-sandi` });
    setSent(true);
    setBusy(false);
  }

  if (sent) return <AuthResult title="Periksa email Anda" description="Jika email terdaftar, tautan pemulihan yang berlaku satu jam sudah dikirim."><Button asChild variant="secondary"><Link href="/login">Kembali ke login</Link></Button></AuthResult>;

  return <>
    <MobileBrand />
    <header><p className="auth-kicker">Pemulihan akses</p><h1>Pulihkan akun</h1><p>Masukkan email kerja untuk menerima tautan pengaturan ulang.</p></header>
    <form onSubmit={submit} className="form-stack">
      <div className="field"><label htmlFor="email">Email</label><input className="control" id="email" name="email" type="email" autoComplete="email" required autoFocus /></div>
      <Button type="submit" disabled={busy}>{busy ? <SpinnerGapIcon className="animate-spin" aria-hidden="true" /> : null}{busy ? "Mengirim..." : "Kirim tautan"}</Button>
      <Button asChild variant="ghost"><Link href="/login">Kembali ke login</Link></Button>
    </form>
  </>;
}

export function ResetPasswordForm() {
  const token = useSearchParams().get("token");
  const [busy, setBusy] = useState(false);
  const [done, setDone] = useState(false);
  const [error, setError] = useState("");

  async function submit(event: React.FormEvent<HTMLFormElement>) {
    event.preventDefault();
    const data = new FormData(event.currentTarget);
    const password = String(data.get("password"));
    if (password !== String(data.get("confirmation"))) {
      setError("Konfirmasi kata sandi tidak cocok.");
      return;
    }
    setBusy(true);
    setError("");
    const result = await authClient.resetPassword({ token: token!, newPassword: password });
    if (result.error) setError("Tautan tidak valid atau sudah kedaluwarsa.");
    else setDone(true);
    setBusy(false);
  }

  if (!token) return <AuthResult title="Tautan tidak valid" description="Minta tautan pemulihan yang baru." error><Button asChild><Link href="/lupa-sandi">Minta tautan baru</Link></Button></AuthResult>;
  if (done) return <AuthResult title="Kata sandi diperbarui" description="Silakan masuk menggunakan kata sandi baru."><Button asChild><Link href="/login">Masuk sekarang</Link></Button></AuthResult>;

  return <>
    <MobileBrand />
    <header><p className="auth-kicker">Kata sandi baru</p><h1>Atur ulang akses</h1><p>Gunakan 10-128 karakter.</p></header>
    <form onSubmit={submit} className="form-stack">
      <div className="field"><label htmlFor="password">Kata sandi baru</label><PasswordInput id="password" name="password" autoComplete="new-password" minLength={10} maxLength={128} autoFocus /></div>
      <div className="field"><label htmlFor="confirmation">Ulangi kata sandi</label><PasswordInput id="confirmation" name="confirmation" autoComplete="new-password" minLength={10} maxLength={128} /></div>
      {error ? <p className="form-message error" role="alert">{error}</p> : null}
      <Button type="submit" disabled={busy}>{busy ? <SpinnerGapIcon className="animate-spin" aria-hidden="true" /> : null}{busy ? "Menyimpan..." : "Simpan kata sandi"}</Button>
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

function AuthResult({ title, description, error = false, children }: { title: string; description: string; error?: boolean; children: React.ReactNode }) {
  return <div className="auth-result">
    <MobileBrand />
    <CheckCircleIcon className={error ? "auth-result-icon error" : "auth-result-icon"} size={44} weight="duotone" aria-hidden="true" />
    <h1>{title}</h1><p>{description}</p><div className="auth-result-action">{children}</div>
  </div>;
}
