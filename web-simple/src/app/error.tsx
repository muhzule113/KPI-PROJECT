"use client";

import { WarningCircleIcon } from "@phosphor-icons/react";
import { StateVignette } from "@/components/illustrations";
import { Button } from "@/components/ui/button";

export default function ErrorPage({ reset }: { error: Error & { digest?: string }; reset: () => void }) {
  return <div className="standalone-shell"><main className="standalone-card"><span className="brand-mark" aria-hidden="true">K</span><StateVignette icon={WarningCircleIcon} /><p className="auth-kicker">Terjadi kendala</p><h1>Halaman gagal dimuat</h1><p>Data Anda tidak berubah. Coba muat halaman ini kembali.</p><Button onClick={reset}>Coba lagi</Button></main></div>;
}
