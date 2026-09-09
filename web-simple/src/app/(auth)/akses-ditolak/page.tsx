import Link from "next/link";
import { Button } from "@/components/ui/button";

export default async function DeniedPage({ searchParams }: { searchParams: Promise<{ reason?: string }> }) {
  const reason = (await searchParams).reason;
  return <><p className="auth-kicker">Akses dibatasi</p><h1>Akses tidak tersedia</h1><p>{reason || "Akun Anda tidak memiliki hak untuk membuka halaman ini."}</p><div className="auth-result-action"><Button asChild><Link href="/app">Kembali ke aplikasi</Link></Button></div></>;
}
