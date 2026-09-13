import {
  CheckCircleIcon,
  ClockIcon,
  InfoIcon,
  MinusCircleIcon,
  WarningCircleIcon,
} from "@phosphor-icons/react/dist/ssr";
import { cn } from "@/lib/utils";

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
  if (!status) return <span className="status neutral"><MinusCircleIcon aria-hidden="true" />Belum dipilih</span>;
  const success = ["ACTIVE", "APPROVED", "FINALIZED", "COMPLETED", "WORKED"].includes(status);
  const info = status === "SUBMITTED";
  const warning = ["READY", "REOPENED", "PERMIT", "SICK"].includes(status);
  const danger = ["INACTIVE", "REVISION_REQUIRED", "RETIRED"].includes(status);
  const isLive = ["IN_PROGRESS", "SUBMITTED", "OPEN"].includes(status);
  const Icon = success ? CheckCircleIcon : info ? InfoIcon : warning ? ClockIcon : danger ? WarningCircleIcon : MinusCircleIcon;
  return <span className={cn("status", {
    success,
    info,
    warning,
    danger,
    neutral: ["PENDING", "DRAFT", "IN_PROGRESS", "OPEN", "OFF"].includes(status),
    live: isLive,
  })}><Icon aria-hidden="true" />{labels[status] ?? status}</span>;
}
