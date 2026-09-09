import { CompassIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { StateVignette } from "@/components/illustrations";
import { Button } from "@/components/ui/button";

export default function NotFound() {
  return <div className="standalone-shell"><main className="standalone-card"><span className="brand-mark" aria-hidden="true">K</span><StateVignette icon={CompassIcon} /><p className="auth-kicker">Kode 404</p><h1>Halaman tidak ditemukan</h1><p>Alamat yang dibuka tidak tersedia atau sudah dipindahkan.</p><Button asChild><Link href="/app">Kembali ke aplikasi</Link></Button></main></div>;
}
