import { CheckCircleIcon } from "@phosphor-icons/react/dist/ssr";
import type { ReactNode } from "react";
import { AuthWorkbenchIllustration } from "@/components/illustrations";

const proofPoints = [
  "Supervisor mengisi nilai pegawai setiap hari.",
  "Manager meninjau nilai dan mengisi KPI Supervisor.",
  "Hasil final tersimpan dan dapat ditelusuri per periode.",
];

export default function AuthLayout({ children }: { children: ReactNode }) {
  return <div className="auth-shell">
    <a className="skip-link" href="#main-content">Lewati ke konten utama</a>
    <section className="auth-stage"><main className="auth-card" id="main-content">{children}</main></section>
    <aside className="auth-aside" aria-label="Tentang KPI Harian">
      <div className="auth-aside-brand"><span className="brand-mark">K</span><span><strong>KPI Harian</strong><small>Penilaian manual</small></span></div>
      <div className="auth-aside-content">
        <p className="auth-kicker">Alur kerja yang terhubung</p>
        <h2>Dari catatan harian menuju hasil bulanan.</h2>
        <AuthWorkbenchIllustration />
        <ul className="auth-proof-list">{proofPoints.map((point) => <li key={point}><CheckCircleIcon size={20} weight="fill" aria-hidden="true" /><span>{point}</span></li>)}</ul>
      </div>
      <p className="auth-aside-footer">KPI Toko &amp; Servis HP · Web responsif untuk seluruh tim</p>
    </aside>
  </div>;
}
