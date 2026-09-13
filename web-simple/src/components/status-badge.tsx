import {
  CheckCircleIcon,
  ClockIcon,
  InfoIcon,
  MinusCircleIcon,
  WarningCircleIcon,
} from "@phosphor-icons/react/dist/ssr";
import { Badge } from "@/components/ui/badge";

const labels: Record<string, string> = {
  PENDING: "Belum diisi",
  DRAFT: "Draf",
  RETIRED: "Dipensiunkan",
  ACTIVE: "Aktif",
  INACTIVE: "Nonaktif",
  SUBMITTED: "Menunggu review",
  REVISION_REQUIRED: "Perlu diperbaiki",
  APPROVED: "Disetujui",
  IN_PROGRESS: "Berjalan",
  READY: "Siap difinalkan",
  FINALIZED: "Final",
  REOPENED: "Dibuka kembali",
  OPEN: "Terbuka",
  COMPLETED: "Selesai",
  WORKED: "Bekerja",
  OFF: "Libur",
  PERMIT: "Izin",
  SICK: "Sakit",
};

export function StatusBadge({ status }: { status: string | null }) {
  if (!status) return <Badge variant="secondary" className="status neutral"><MinusCircleIcon aria-hidden="true" />Belum dipilih</Badge>;
  const success = ["ACTIVE", "APPROVED", "FINALIZED", "COMPLETED", "WORKED"].includes(status);
  const info = status === "SUBMITTED";
  const warning = ["READY", "REOPENED", "PERMIT", "SICK"].includes(status);
  const danger = ["INACTIVE", "REVISION_REQUIRED", "RETIRED"].includes(status);
  const isLive = ["IN_PROGRESS", "SUBMITTED", "OPEN"].includes(status);
  const Icon = success ? CheckCircleIcon : info ? InfoIcon : warning ? ClockIcon : danger ? WarningCircleIcon : MinusCircleIcon;
  const variant = success ? "success" : info ? "info" : warning ? "warning" : danger ? "destructive" : "secondary";
  return <Badge variant={variant} className={`status${isLive ? " live" : ""}`}><Icon aria-hidden="true" />{labels[status] ?? status}</Badge>;
}
