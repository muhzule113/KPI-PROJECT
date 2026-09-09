import {
  CheckCircleIcon,
  ClockIcon,
  LockIcon,
  WarningCircleIcon,
  XCircleIcon,
} from "@phosphor-icons/react/dist/ssr";
import { Badge } from "@/components/ui/badge";

const labels: Record<string, string> = {
  DRAFT: "Draf",
  READY: "Siap",
  OPEN: "Dibuka",
  SUBMISSION_CLOSED: "Pengajuan ditutup",
  IN_REVIEW: "Sedang ditinjau",
  WAITING_APPROVAL: "Menunggu persetujuan",
  PUBLISHED: "Diterbitkan",
  LOCKED: "Dikunci",
  CANCELLED: "Dibatalkan",
  SUBMITTED: "Diajukan",
  UNDER_REVIEW: "Sedang ditinjau",
  REVISION_REQUIRED: "Perlu revisi",
  VERIFIED: "Terverifikasi",
  PENDING_APPROVAL: "Menunggu persetujuan",
  APPROVED: "Disetujui",
  INTAKE: "Penerimaan",
  DIAGNOSING: "Diagnosis",
  WAITING_CONSENT: "Menunggu persetujuan pelanggan",
  WAITING_SPAREPART: "Menunggu sparepart",
  IN_PROGRESS: "Dikerjakan",
  QC_READY: "Siap QC",
  COMPLETED: "Selesai",
  CANCELLED_UNREPAIRABLE: "Tidak dapat diperbaiki",
  DELIVERED: "Diserahkan",
  UNPAID: "Belum dibayar",
  PARTIAL: "Dibayar sebagian",
  PAID: "Lunas",
  WAIVED: "Dibebaskan",
  UPLOADED: "Diunggah",
  SCANNING: "Memindai keamanan",
  QUEUED: "Dalam antrean",
  PARSING: "Membaca file",
  NORMALIZING: "Menormalkan data",
  VALIDATING: "Memvalidasi data",
  READY_FOR_PREVIEW: "Siap diperiksa",
  NEEDS_MAPPING: "Perlu pemetaan",
  NEEDS_REVIEW: "Perlu ditinjau",
  CONFIRMED: "Dikonfirmasi",
  COMMITTING: "Menyimpan transaksi",
  COMPLETED_WITH_WARNINGS: "Selesai dengan catatan",
  SUPERSEDED: "Digantikan",
  FAILED: "Gagal",
};

const positive = new Set(["READY", "OPEN", "PUBLISHED", "VERIFIED", "APPROVED", "COMPLETED", "DELIVERED", "PAID", "CONFIRMED"]);
const warning = new Set(["WAITING_APPROVAL", "PENDING_APPROVAL", "REVISION_REQUIRED", "WAITING_CONSENT", "WAITING_SPAREPART", "PARTIAL", "NEEDS_MAPPING", "NEEDS_REVIEW", "COMPLETED_WITH_WARNINGS"]);
const negative = new Set(["CANCELLED", "CANCELLED_UNREPAIRABLE", "FAILED"]);

export function StatusBadge({ status }: { status: string }) {
  const Icon = negative.has(status)
    ? XCircleIcon
    : warning.has(status)
      ? WarningCircleIcon
      : positive.has(status)
        ? CheckCircleIcon
        : status === "LOCKED"
          ? LockIcon
          : ClockIcon;
  const tone = negative.has(status)
    ? "border-red-200 bg-red-50 text-red-700"
    : warning.has(status)
      ? "border-amber-200 bg-amber-50 text-amber-800"
      : positive.has(status)
        ? "border-emerald-200 bg-emerald-50 text-emerald-700"
        : "border-border bg-muted text-muted-foreground";

  return (
    <Badge variant="outline" className={tone}>
      <Icon aria-hidden="true" weight="fill" />
      {labels[status] ?? status.replaceAll("_", " ").toLocaleLowerCase("id-ID")}
    </Badge>
  );
}
