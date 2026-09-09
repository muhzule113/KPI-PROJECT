import { ShieldCheckIcon } from "@phosphor-icons/react/dist/ssr";
import { EmptyState, PageHeader } from "@/components/page-elements";
import { prisma } from "@/lib/prisma";
import { formatDate } from "@/lib/utils";
import { requireRole } from "@/modules/access/current-user";

const actionLabels: Record<string, string> = {
  create_period: "Membuat periode",
  open_period: "Membuka periode",
  save_daily_sheet: "Menyimpan draf harian",
  submit_daily_sheet: "Mengirim penilaian harian",
  approve_daily_sheet: "Menyetujui penilaian harian",
  correct_daily_sheet: "Mengoreksi penilaian harian",
  correct_supervisor_daily_sheet: "Mengoreksi KPI Supervisor",
  return_daily_sheet: "Mengembalikan penilaian harian",
  finalize_monthly_kpi: "Memfinalkan KPI bulanan",
  reopen_monthly_kpi: "Membuka kembali KPI bulanan",
  upload_evidence: "Mengunggah evidence",
  remove_evidence: "Menghapus evidence",
  create_account: "Membuat akun",
  update_account: "Memperbarui akun",
  create_branch: "Membuat cabang",
  update_branch: "Memperbarui cabang",
  create_position: "Membuat jabatan",
  update_position: "Memperbarui jabatan",
  create_indicator: "Membuat indikator",
  update_indicator: "Memperbarui indikator",
  remove_indicator: "Menghapus indikator",
  activate_template: "Mengaktifkan template",
  update_template_name: "Memperbarui nama template",
  create_template_draft: "Membuat revisi template",
  discard_template_draft: "Membatalkan revisi template",
  activate_template_version: "Mengaktifkan versi template",
  create_rating_draft: "Membuat revisi predikat",
  update_rating_draft: "Memperbarui revisi predikat",
  discard_rating_draft: "Membatalkan revisi predikat",
  activate_rating_version: "Mengaktifkan versi predikat",
};

export default async function AuditPage() {
  await requireRole("ADMIN");
  const events = await prisma.auditEvent.findMany({ orderBy: { createdAt: "desc" }, take: 200, include: { actor: { select: { name: true, email: true } } } });
  return <><PageHeader eyebrow="Kontrol" title="Riwayat perubahan" description="Catatan 200 tindakan terbaru. Kata sandi dan isi file tidak pernah disimpan di audit." />{!events.length ? <EmptyState icon={ShieldCheckIcon} title="Belum ada perubahan" description="Tindakan penting akan tercatat otomatis di sini." /> : <section className="panel"><div className="table-wrap"><table className="data-table"><thead><tr><th>Waktu</th><th>Pelaku</th><th>Tindakan</th><th>Objek</th><th>Alasan</th></tr></thead><tbody>{events.map((event) => <tr key={event.id}><td>{formatDate(event.createdAt, { dateStyle: undefined, timeStyle: "medium" } as Intl.DateTimeFormatOptions)}</td><td>{event.actor?.name ?? "Sistem"}<span className="cell-subtitle">{event.actor?.email ?? "Tidak tersedia"}</span></td><td>{actionLabels[event.action] ?? event.action}</td><td>{event.subjectType}<span className="cell-subtitle">{event.subjectId}</span></td><td>{event.reason || "Tidak ada alasan"}</td></tr>)}</tbody></table></div></section>}</>;
}
