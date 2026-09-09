import type { MonthlyIndicatorInput } from "./calculation.ts";

export type MasterKpiPositionCode = "TEKNISI" | "PELAYAN" | "ADMIN_OPS" | "KASIR" | "GUDANG" | "SPV";

export type MasterKpiIndicator = Omit<MonthlyIndicatorInput, "values"> & {
  code: string;
  name: string;
  description: string;
  unit: string;
  sortOrder: number;
};

export const MASTER_KPI_POSITION_NAMES: Record<MasterKpiPositionCode, string> = {
  TEKNISI: "Teknisi",
  PELAYAN: "Pelayan",
  ADMIN_OPS: "Admin Operasional",
  KASIR: "Kasir",
  GUDANG: "Gudang / Sparepart",
  SPV: "Supervisor",
};

export const MASTER_KPI_TEMPLATES = {
  TEKNISI: [
    indicator("TEK-01", "Jumlah servis selesai", "Jumlah pekerjaan servis yang selesai.", "unit", "SUM", "HIGHER", 80, null, 25, 1),
    indicator("TEK-02", "Tingkat keberhasilan servis", "Persentase servis yang berhasil diselesaikan.", "%", "AVERAGE", "HIGHER", 95, null, 25, 2),
    indicator("TEK-03", "Tingkat retur", "Persentase servis yang kembali karena retur.", "%", "AVERAGE", "LOWER", 3, 6, 15, 3),
    indicator("TEK-04", "Ketepatan waktu pengerjaan", "Persentase pekerjaan yang selesai tepat waktu.", "%", "AVERAGE", "HIGHER", 95, null, 15, 4),
    indicator("TEK-05", "Kepatuhan SOP", "Persentase kepatuhan terhadap SOP teknisi.", "%", "AVERAGE", "HIGHER", 95, null, 10, 5),
    indicator("TEK-06", "Kerapian & kebersihan", "Persentase kerapian dan kebersihan area kerja.", "%", "AVERAGE", "HIGHER", 90, null, 5, 6),
    indicator("TEK-07", "Kelengkapan laporan servis", "Persentase laporan servis yang lengkap.", "%", "AVERAGE", "HIGHER", 100, null, 5, 7),
  ],
  PELAYAN: [
    indicator("CS-01", "Kepuasan pelanggan", "Persentase kepuasan pelanggan.", "%", "AVERAGE", "HIGHER", 90, null, 25, 1),
    indicator("CS-02", "Kecepatan melayani", "Persentase pelayanan yang memenuhi standar waktu.", "%", "AVERAGE", "HIGHER", 95, null, 20, 2),
    indicator("CS-03", "Akurasi input order", "Persentase input order tanpa kesalahan.", "%", "AVERAGE", "HIGHER", 98, null, 20, 3),
    indicator("CS-04", "Follow-up pelanggan", "Persentase follow-up pelanggan yang diselesaikan.", "%", "AVERAGE", "HIGHER", 95, null, 15, 4),
    indicator("CS-05", "Jumlah komplain", "Jumlah komplain pelanggan.", "komplain", "SUM", "LOWER", 3, 5, 10, 5),
    indicator("CS-06", "Kehadiran & disiplin", "Persentase kehadiran dan disiplin kerja.", "%", "AVERAGE", "HIGHER", 95, null, 10, 6),
  ],
  ADMIN_OPS: [
    indicator("ADM-01", "Akurasi input data", "Persentase input data tanpa kesalahan.", "%", "AVERAGE", "HIGHER", 98, null, 30, 1),
    indicator("ADM-02", "Ketepatan laporan", "Persentase laporan yang disampaikan tepat waktu.", "%", "AVERAGE", "HIGHER", 100, null, 25, 2),
    indicator("ADM-03", "Kelengkapan dokumen", "Persentase dokumen yang lengkap.", "%", "AVERAGE", "HIGHER", 98, null, 15, 3),
    indicator("ADM-04", "Rekonsiliasi data", "Persentase data yang berhasil direkonsiliasi.", "%", "AVERAGE", "HIGHER", 98, null, 15, 4),
    indicator("ADM-05", "Kehadiran & disiplin", "Persentase kehadiran dan disiplin kerja.", "%", "AVERAGE", "HIGHER", 95, null, 10, 5),
    indicator("ADM-06", "Kepatuhan SOP", "Persentase kepatuhan terhadap SOP administrasi.", "%", "AVERAGE", "HIGHER", 95, null, 5, 6),
  ],
  KASIR: [
    indicator("KSR-01", "Akurasi transaksi", "Persentase transaksi tanpa kesalahan.", "%", "AVERAGE", "HIGHER", 99, null, 30, 1),
    indicator("KSR-02", "Selisih kas", "Total selisih kas aktual terhadap catatan.", "Rp", "SUM", "ZERO_TOLERANCE", 0, null, 25, 2),
    indicator("KSR-03", "Ketepatan laporan kas", "Persentase laporan kas yang disampaikan tepat waktu.", "%", "AVERAGE", "HIGHER", 100, null, 20, 3),
    indicator("KSR-04", "Kecepatan transaksi", "Persentase transaksi yang memenuhi standar waktu.", "%", "AVERAGE", "HIGHER", 95, null, 10, 4),
    indicator("KSR-05", "Pelayanan", "Persentase mutu pelayanan kasir.", "%", "AVERAGE", "HIGHER", 90, null, 10, 5),
    indicator("KSR-06", "Disiplin", "Persentase disiplin kerja.", "%", "AVERAGE", "HIGHER", 95, null, 5, 6),
  ],
  GUDANG: [
    indicator("GUD-01", "Akurasi stok", "Persentase stok yang sesuai catatan.", "%", "AVERAGE", "HIGHER", 98, null, 30, 1),
    indicator("GUD-02", "Selisih stok", "Persentase selisih stok terhadap catatan.", "%", "AVERAGE", "LOWER", 2, 5, 20, 2),
    indicator("GUD-03", "Kecepatan penyediaan sparepart", "Persentase penyediaan sparepart yang memenuhi standar waktu.", "%", "AVERAGE", "HIGHER", 95, null, 15, 3),
    indicator("GUD-04", "Kelengkapan stok", "Persentase ketersediaan stok yang diwajibkan.", "%", "AVERAGE", "HIGHER", 95, null, 15, 4),
    indicator("GUD-05", "Stock opname", "Persentase pelaksanaan stock opname yang selesai.", "%", "AVERAGE", "HIGHER", 100, null, 10, 5),
    indicator("GUD-06", "Kerapian gudang", "Persentase kerapian gudang.", "%", "AVERAGE", "HIGHER", 90, null, 5, 6),
    indicator("GUD-07", "Disiplin", "Persentase disiplin kerja.", "%", "AVERAGE", "HIGHER", 95, null, 5, 7),
  ],
  SPV: [
    indicator("SUP-01", "Pencapaian target tim", "Persentase pencapaian target tim.", "%", "AVERAGE", "HIGHER", 90, null, 30, 1),
    indicator("SUP-02", "Kualitas kerja tim", "Persentase kualitas kerja tim.", "%", "AVERAGE", "HIGHER", 95, null, 20, 2),
    indicator("SUP-03", "Kedisiplinan tim", "Persentase kedisiplinan tim.", "%", "AVERAGE", "HIGHER", 95, null, 15, 3),
    indicator("SUP-04", "Penyelesaian komplain", "Persentase komplain yang diselesaikan.", "%", "AVERAGE", "HIGHER", 90, null, 10, 4),
    indicator("SUP-05", "Coaching/evaluasi karyawan", "Persentase coaching dan evaluasi yang diselesaikan.", "%", "AVERAGE", "HIGHER", 100, null, 10, 5),
    indicator("SUP-06", "Kepatuhan SOP", "Persentase kepatuhan terhadap SOP Supervisor.", "%", "AVERAGE", "HIGHER", 95, null, 10, 6),
    indicator("SUP-07", "Ketepatan laporan", "Persentase laporan yang disampaikan tepat waktu.", "%", "AVERAGE", "HIGHER", 100, null, 5, 7),
  ],
} as const satisfies Record<MasterKpiPositionCode, readonly MasterKpiIndicator[]>;

function indicator(
  code: string,
  name: string,
  description: string,
  unit: string,
  aggregation: MasterKpiIndicator["aggregation"],
  direction: MasterKpiIndicator["direction"],
  target: number,
  failureLimit: number | null,
  weight: number,
  sortOrder: number,
): MasterKpiIndicator {
  return { code, name, description, kind: "NUMERIC", unit, aggregation, direction, target, failureLimit, weight, sortOrder };
}
