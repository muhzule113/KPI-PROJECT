import type { Icon } from "@phosphor-icons/react";
import {
  BellIcon,
  BuildingsIcon,
  CalendarDotsIcon,
  ChartBarIcon,
  ChecksIcon,
  ClipboardTextIcon,
  GearIcon,
  SealCheckIcon,
  ShieldCheckIcon,
  UsersIcon,
} from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { RoleWorkbenchIllustration } from "@/components/illustrations";
import { PageHeader } from "@/components/page-elements";
import { StatusBadge } from "@/components/status-badge";
import { todayInMakassar } from "@/lib/date";
import { prisma } from "@/lib/prisma";
import { formatNumber } from "@/lib/utils";
import { requireUser } from "@/modules/access/current-user";

type QuickLink = [href: string, title: string, description: string, icon: Icon];
type Metric = [label: string, value: number, icon: Icon, note?: string];

export default async function DashboardPage() {
  const user = await requireUser();
  const today = new Date(`${todayInMakassar()}T00:00:00.000Z`);

  if (user.role === "ADMIN") {
    const [users, branches, periods, finalized] = await Promise.all([
      prisma.user.count({ where: { isActive: true } }),
      prisma.branch.count({ where: { isActive: true } }),
      prisma.kpiPeriod.count({ where: { status: "OPEN" } }),
      prisma.monthlyKpi.count({ where: { status: "FINALIZED" } }),
    ]);
    return <Dashboard
      role={user.role}
      header={<PageHeader eyebrow="Ruang Super Admin" title={`Selamat datang, ${user.name}`} description="Siapkan organisasi, versi indikator, predikat, dan periode penilaian." />}
      focus={["/app/pengaturan/jabatan", "Fokus administrasi", "Pastikan setiap jabatan siap dinilai", "Jabatan KPI memerlukan versi indikator aktif sebelum periode baru dapat dibuka.", String(periods), "periode terbuka"]}
      metrics={[["Akun aktif", users, UsersIcon], ["Cabang aktif", branches, BuildingsIcon], ["Periode terbuka", periods, CalendarDotsIcon], ["Hasil final", finalized, SealCheckIcon]]}
      links={[["/app/pengaturan/jabatan", "Kelola jabatan", "Tambah jabatan dan periksa kesiapan template KPI.", BuildingsIcon], ["/app/pengaturan/indikator", "Atur indikator KPI", "Kelola draft dan aktifkan versi untuk periode berikutnya.", GearIcon], ["/app/pengaturan/predikat", "Atur predikat nilai", "Ubah lima label dan ambang predikat global.", ChartBarIcon], ["/app/pengaturan/periode", "Kelola periode", "Buat periode setelah seluruh konfigurasi siap.", CalendarDotsIcon], ["/app/rekap", "Lihat rekap", "Pantau hasil bulanan dan buka kembali hasil bila perlu.", ChartBarIcon], ["/app/audit", "Periksa audit", "Lihat perubahan penting beserta pelakunya.", ShieldCheckIcon]]}
    />;
  }

  if (user.role === "MANAGER") {
    const employeeId = user.employee!.id;
    const [supervisorToday, reviewQueue, ready, finalized] = await Promise.all([
      prisma.dailySheet.count({ where: { entryDate: today, monthlyKpi: { managerIdSnapshot: employeeId, subjectRoleSnapshot: "SUPERVISOR" }, status: { not: "APPROVED" } } }),
      prisma.dailySheet.count({ where: { monthlyKpi: { managerIdSnapshot: employeeId, subjectRoleSnapshot: "EMPLOYEE" }, status: "SUBMITTED" } }),
      prisma.monthlyKpi.count({ where: { managerIdSnapshot: employeeId, status: "READY" } }),
      prisma.monthlyKpi.count({ where: { managerIdSnapshot: employeeId, status: "FINALIZED" } }),
    ]);
    return <Dashboard
      role={user.role}
      header={<PageHeader eyebrow="Meja Manager" title={`Selamat datang, ${user.name}`} description="Isi KPI Supervisor, review penilaian staf, lalu finalkan hasil setelah periode berakhir." />}
      focus={["/app/review", "Prioritas hari ini", "Tinjau penilaian staf yang masuk", "Setujui, koreksi, atau kembalikan lembar yang sudah dikirim oleh Supervisor.", String(reviewQueue), "lembar menunggu"]}
      metrics={[["Supervisor hari ini", supervisorToday, ClipboardTextIcon, "belum disetujui"], ["Antrean review", reviewQueue, ChecksIcon], ["Siap final", ready, CalendarDotsIcon], ["Hasil final", finalized, SealCheckIcon]]}
      links={[["/app/harian", "Nilai Supervisor", "Isi lembar KPI Supervisor untuk tanggal kerja.", ClipboardTextIcon], ["/app/review", "Review penilaian staf", "Tindak lanjuti lembar yang dikirim Supervisor.", ChecksIcon], ["/app/rekap", "Finalisasi bulanan", "Pastikan semua hari lengkap sebelum mengesahkan hasil.", ChartBarIcon], ["/app/notifikasi", "Buka notifikasi", "Lihat pekerjaan yang perlu ditindaklanjuti.", BellIcon]]}
    />;
  }

  if (user.role === "SUPERVISOR") {
    const employeeId = user.employee!.id;
    const [totalToday, pendingToday, returned, teamFinal] = await Promise.all([
      prisma.dailySheet.count({ where: { entryDate: today, monthlyKpi: { supervisorIdSnapshot: employeeId, subjectRoleSnapshot: "EMPLOYEE" } } }),
      prisma.dailySheet.count({ where: { entryDate: today, monthlyKpi: { supervisorIdSnapshot: employeeId, subjectRoleSnapshot: "EMPLOYEE" }, status: { in: ["PENDING", "DRAFT", "REVISION_REQUIRED"] } } }),
      prisma.dailySheet.count({ where: { monthlyKpi: { supervisorIdSnapshot: employeeId }, status: "REVISION_REQUIRED" } }),
      prisma.monthlyKpi.count({ where: { supervisorIdSnapshot: employeeId, status: "FINALIZED" } }),
    ]);
    return <Dashboard
      role={user.role}
      header={<PageHeader eyebrow="Meja Supervisor" title={`Selamat datang, ${user.name}`} description="Isi status kerja dan nilai aktual anggota tim setiap hari." />}
      focus={["/app/harian", "Fokus hari ini", "Lengkapi penilaian anggota tim", "Pilih tanggal, isi status kerja, lalu masukkan nilai aktual untuk setiap indikator.", String(pendingToday), "lembar belum dikirim"]}
      metrics={[["Anggota hari ini", totalToday, UsersIcon], ["Belum dikirim", pendingToday, ClipboardTextIcon], ["Perlu diperbaiki", returned, ChecksIcon], ["Hasil tim final", teamFinal, SealCheckIcon]]}
      links={[["/app/harian", "Mulai penilaian", "Pilih tanggal dan lengkapi lembar setiap anggota tim.", ClipboardTextIcon], ["/app/rekap", "Pantau rekap tim", "Lihat progres lembar dan akumulasi bulanan.", ChartBarIcon], ["/app/notifikasi", "Buka notifikasi", "Lihat hasil review dan lembar yang dikembalikan.", BellIcon]]}
    />;
  }

  const latest = await prisma.monthlyKpi.findFirst({
    where: { employeeId: user.employee!.id },
    orderBy: [{ period: { year: "desc" } }, { period: { month: "desc" } }],
    include: { period: true },
  });
  const latestFinal = latest?.status === "FINALIZED";
  return <>
    <PageHeader eyebrow="Ringkasan pribadi" title={`Selamat datang, ${user.name}`} description="Pantau nilai sementara, status review harian, dan hasil KPI resmi Anda." />
    <div className="dashboard-flow">
      <Link href="/app/kpi-saya" className="dashboard-focus">
        <div className="dashboard-focus-copy"><p className="eyebrow">KPI terbaru</p><h2>{latest ? latest.period.name : "Belum ada KPI"}</h2><p>{latest ? latest.ratingLabel ?? latest.noScoreReason ?? "Belum dapat dihitung" : "KPI akan tersedia setelah periode penilaian dibuka."}</p>{latest ? <StatusBadge status={latest.status} /> : null}<span className="dashboard-focus-action">Lihat rincian KPI</span></div>
        <div className="dashboard-focus-meta"><strong>{latest ? formatNumber(latest.finalScore) : "-"}</strong><span>{latest ? latestFinal ? "nilai final" : "nilai sementara" : "belum tersedia"}</span></div>
        <div className="dashboard-focus-illustration"><RoleWorkbenchIllustration role={user.role} /></div>
      </Link>
      <ToolList links={[["/app/kpi-saya", "Lihat KPI saya", "Buka progres harian, nilai sementara, dan hasil final.", ChartBarIcon], ["/app/notifikasi", "Buka notifikasi", "Lihat perubahan status penilaian harian Anda.", BellIcon]]} />
    </div>
  </>;
}

function Dashboard({ role, header, focus, metrics, links }: {
  role: "ADMIN" | "MANAGER" | "SUPERVISOR" | "EMPLOYEE";
  header: React.ReactNode;
  focus: [href: string, eyebrow: string, title: string, description: string, value: string, valueLabel: string];
  metrics: Metric[];
  links: QuickLink[];
}) {
  return <>{header}<div className="dashboard-flow">
    <Link href={focus[0]} className="dashboard-focus"><div className="dashboard-focus-copy"><p className="eyebrow">{focus[1]}</p><h2>{focus[2]}</h2><p>{focus[3]}</p><span className="dashboard-focus-action">Buka pekerjaan</span></div><div className="dashboard-focus-meta"><strong>{focus[4]}</strong><span>{focus[5]}</span></div><div className="dashboard-focus-illustration"><RoleWorkbenchIllustration role={role} /></div></Link>
    <MetricLedger metrics={metrics} />
    <ToolList links={links} />
  </div></>;
}

function MetricLedger({ metrics }: { metrics: Metric[] }) {
  return <section className="metric-ledger" aria-labelledby="metric-ledger-title"><div className="metric-ledger-heading"><h2 id="metric-ledger-title">Catatan angka</h2><p>Posisi pekerjaan saat ini</p></div><dl>{metrics.map(([label, value, icon, note]) => {
    const Icon = icon;
    return <div className="metric-ledger-row" key={label}><dt><Icon size={19} weight="duotone" aria-hidden="true" /><span>{label}</span></dt><dd><strong>{value}</strong>{note ? <small>{note}</small> : null}</dd></div>;
  })}</dl></section>;
}

function ToolList({ links }: { links: QuickLink[] }) {
  return <section className="tool-section"><div className="section-heading"><h2>Alat kerja</h2><p>Pilih sesuai pekerjaan yang ingin diselesaikan.</p></div><div className="tool-list">{links.map(([href, title, description, icon]) => {
    const Icon = icon;
    return <Link href={href} key={href} className="tool-link"><span className="tool-link-icon"><Icon size={21} weight="duotone" aria-hidden="true" /></span><span className="tool-link-copy"><strong>{title}</strong><span>{description}</span></span><span className="tool-link-action">Buka</span></Link>;
  })}</div></section>;
}
