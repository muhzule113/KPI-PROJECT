import { ChartLineUpIcon, CheckCircleIcon, StorefrontIcon } from "@phosphor-icons/react/dist/ssr";

export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <main className="grid min-h-dvh bg-background lg:grid-cols-[minmax(24rem,0.9fr)_1.1fr]">
      <section className="flex items-center justify-center px-4 py-8 sm:px-8 lg:px-12">
        <div className="w-full max-w-md">{children}</div>
      </section>
      <aside className="relative hidden overflow-hidden border-l bg-sidebar p-12 text-sidebar-foreground lg:flex lg:flex-col lg:justify-between">
        <div className="relative z-10 flex items-center gap-3">
          <span className="grid size-11 place-items-center rounded-xl bg-primary text-primary-foreground"><StorefrontIcon size={24} weight="duotone" /></span>
          <div><p className="font-bold tracking-[0.16em]">KPI OPS</p><p className="text-xs text-sidebar-foreground/60">Satu pusat operasi</p></div>
        </div>
        <div className="relative z-10 max-w-xl">
          <p className="mb-4 text-sm font-semibold uppercase tracking-[0.16em] text-primary">Kerja lebih terlihat</p>
          <h1 className="text-4xl font-semibold leading-tight tracking-tight xl:text-5xl">KPI, servis, dan operasional dalam satu layar.</h1>
          <ul className="mt-8 grid gap-4 text-sm text-sidebar-foreground/75">
            <li className="flex gap-3"><CheckCircleIcon className="mt-0.5 shrink-0 text-primary" size={20} weight="fill" />Alur review dan persetujuan mengikuti kewenangan.</li>
            <li className="flex gap-3"><CheckCircleIcon className="mt-0.5 shrink-0 text-primary" size={20} weight="fill" />Nyaman dipakai dari komputer maupun ponsel.</li>
            <li className="flex gap-3"><CheckCircleIcon className="mt-0.5 shrink-0 text-primary" size={20} weight="fill" />Riwayat perubahan tetap dapat ditelusuri.</li>
          </ul>
        </div>
        <div aria-hidden="true" className="absolute -bottom-14 -right-10 flex h-72 w-96 items-end gap-4 opacity-20">
          {[35, 62, 48, 82, 68, 100].map((height, index) => <span key={index} className="flex-1 rounded-t-xl bg-primary" style={{ height: `${height}%` }} />)}
          <ChartLineUpIcon className="absolute right-8 top-3" size={80} />
        </div>
        <p className="relative z-10 text-xs text-sidebar-foreground/45">Sistem Manajemen KPI Toko dan Servis HP</p>
      </aside>
    </main>
  );
}
