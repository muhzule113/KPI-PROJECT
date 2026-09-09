import type { MonthlyIndicatorInput } from "./calculation.ts";

export const AUGUST_WORKED_DAYS = 30;

export type RosterPositionCode = "CREW" | "KASIR" | "PELAYAN" | "TEKNISI" | "KURIR" | "ADMIN_OPS";

export type FixtureIndicator = Omit<MonthlyIndicatorInput, "values"> & {
  code: string;
  name: string;
  description: string;
  unit: string;
  sortOrder: number;
};

export const AUGUST_2026_ROSTER = [
  { name: "Cumi", email: "cumi@kpi-test.local", employeeNumber: "AUG26-001", positionCode: "CREW" },
  { name: "Ica", email: "ica@kpi-test.local", employeeNumber: "AUG26-002", positionCode: "KASIR" },
  { name: "Nurajizah", email: "nurajizah@kpi-test.local", employeeNumber: "AUG26-003", positionCode: "PELAYAN" },
  { name: "Miming", email: "miming@kpi-test.local", employeeNumber: "AUG26-004", positionCode: "TEKNISI" },
  { name: "Muhammad Novriansyah", email: "muhammad.novriansyah@kpi-test.local", employeeNumber: "AUG26-005", positionCode: "TEKNISI" },
  { name: "Akmal", email: "akmal@kpi-test.local", employeeNumber: "AUG26-006", positionCode: "TEKNISI" },
  { name: "Ani", email: "ani@kpi-test.local", employeeNumber: "AUG26-007", positionCode: "CREW" },
  { name: "Pandy", email: "pandy@kpi-test.local", employeeNumber: "AUG26-008", positionCode: "TEKNISI" },
  { name: "Wahyu", email: "wahyu@kpi-test.local", employeeNumber: "AUG26-009", positionCode: "TEKNISI" },
  { name: "Jusri", email: "jusri@kpi-test.local", employeeNumber: "AUG26-010", positionCode: "TEKNISI" },
  { name: "Lastri", email: "lastri@kpi-test.local", employeeNumber: "AUG26-011", positionCode: "PELAYAN" },
  { name: "Mikma", email: "mikma@kpi-test.local", employeeNumber: "AUG26-012", positionCode: "KASIR" },
  { name: "Awalia", email: "awalia@kpi-test.local", employeeNumber: "AUG26-013", positionCode: "KASIR" },
  { name: "Soleha", email: "soleha@kpi-test.local", employeeNumber: "AUG26-014", positionCode: "PELAYAN" },
  { name: "Abu Rizal", email: "abu.rizal@kpi-test.local", employeeNumber: "AUG26-015", positionCode: "TEKNISI" },
  { name: "Andi Syukur", email: "andi.syukur@kpi-test.local", employeeNumber: "AUG26-016", positionCode: "TEKNISI" },
  { name: "Ida Ayu Putri Shalihah", email: "ida.ayu.putri.shalihah@kpi-test.local", employeeNumber: "AUG26-017", positionCode: "KASIR" },
  { name: "Rsky Rahmawati A.", email: "rsky.rahmawati.a@kpi-test.local", employeeNumber: "AUG26-018", positionCode: "KASIR" },
  { name: "Mia", email: "mia@kpi-test.local", employeeNumber: "AUG26-019", positionCode: "KASIR" },
  { name: "Muhammad Akbar", email: "muhammad.akbar@kpi-test.local", employeeNumber: "AUG26-020", positionCode: "TEKNISI" },
  { name: "Dzul", email: "dzul@kpi-test.local", employeeNumber: "AUG26-021", positionCode: "TEKNISI" },
  { name: "Muh. Ikhsan", email: "muh.ikhsan@kpi-test.local", employeeNumber: "AUG26-022", positionCode: "TEKNISI" },
  { name: "Abrar Syaputra", email: "abrar.syaputra@kpi-test.local", employeeNumber: "AUG26-023", positionCode: "TEKNISI" },
  { name: "Faisal/Icahl", email: "faisal.icahl@kpi-test.local", employeeNumber: "AUG26-024", positionCode: "TEKNISI" },
  { name: "Kaneki", email: "kaneki@kpi-test.local", employeeNumber: "AUG26-025", positionCode: "TEKNISI" },
  { name: "Ciu Andi", email: "ciu.andi@kpi-test.local", employeeNumber: "AUG26-026", positionCode: "TEKNISI" },
  { name: "Andi Al-Faruq", email: "andi.al-faruq@kpi-test.local", employeeNumber: "AUG26-027", positionCode: "KURIR" },
  { name: "Pipah", email: "pipah@kpi-test.local", employeeNumber: "AUG26-028", positionCode: "CREW" },
  { name: "Eva", email: "eva@kpi-test.local", employeeNumber: "AUG26-029", positionCode: "KASIR" },
  { name: "Fani", email: "fani@kpi-test.local", employeeNumber: "AUG26-030", positionCode: "KASIR" },
  { name: "Sela", email: "sela@kpi-test.local", employeeNumber: "AUG26-031", positionCode: "PELAYAN" },
  { name: "Ria", email: "ria@kpi-test.local", employeeNumber: "AUG26-032", positionCode: "PELAYAN" },
  { name: "Astri", email: "astri@kpi-test.local", employeeNumber: "AUG26-033", positionCode: "CREW" },
  { name: "Kia", email: "kia@kpi-test.local", employeeNumber: "AUG26-034", positionCode: "KASIR" },
  { name: "Nita", email: "nita@kpi-test.local", employeeNumber: "AUG26-035", positionCode: "ADMIN_OPS" },
] as const satisfies readonly { name: string; email: string; employeeNumber: string; positionCode: RosterPositionCode }[];

export const FIXTURE_TEMPLATES = {
  CREW: [
    indicator("CRW-01", "Tugas operasional selesai", "Jumlah tugas operasional yang selesai.", "NUMERIC", "tugas", "SUM", "HIGHER", 180, null, 40, 1),
    indicator("CRW-02", "Kualitas pekerjaan", "Rating ketelitian dan kualitas hasil kerja.", "RATING", "rating", "AVERAGE", "HIGHER", 4, null, 35, 2),
    indicator("CRW-03", "Kedisiplinan kerja", "Rating kepatuhan terhadap jadwal dan prosedur.", "RATING", "rating", "AVERAGE", "HIGHER", 4, null, 25, 3),
  ],
  KASIR: [
    indicator("KSR-01", "Akurasi transaksi kasir", "Persentase transaksi yang tercatat tanpa kesalahan.", "NUMERIC", "%", "AVERAGE", "HIGHER", 99, null, 30, 1),
    indicator("KSR-02", "Selisih kas", "Total selisih kas aktual terhadap catatan sistem.", "NUMERIC", "Rp", "SUM", "ZERO_TOLERANCE", 0, null, 25, 2),
    indicator("KSR-03", "Ketepatan laporan kas", "Persentase laporan kas yang dikirim tepat waktu.", "NUMERIC", "%", "AVERAGE", "HIGHER", 100, null, 20, 3),
    indicator("KSR-04", "Kecepatan transaksi", "Persentase transaksi yang selesai dalam SLA.", "NUMERIC", "%", "AVERAGE", "HIGHER", 95, null, 10, 4),
    indicator("KSR-05", "Pelayanan kasir", "Rating keramahan dan ketelitian pelayanan.", "RATING", "rating", "AVERAGE", "HIGHER", 4, null, 10, 5),
    indicator("KSR-06", "Disiplin", "Persentase kepatuhan terhadap jadwal kerja.", "NUMERIC", "%", "AVERAGE", "HIGHER", 95, null, 5, 6),
  ],
  KURIR: [
    indicator("KUR-01", "Pengantaran selesai", "Jumlah pengantaran yang selesai dan tercatat.", "NUMERIC", "pengantaran", "SUM", "HIGHER", 120, null, 40, 1),
    indicator("KUR-02", "Ketepatan waktu", "Persentase pengantaran yang tiba sesuai jadwal.", "NUMERIC", "%", "AVERAGE", "HIGHER", 95, null, 35, 2),
    indicator("KUR-03", "Kedisiplinan kerja", "Rating kepatuhan terhadap jadwal dan prosedur.", "RATING", "rating", "AVERAGE", "HIGHER", 4, null, 25, 3),
  ],
  ADMIN_OPS: [
    indicator("ADM-01", "Akurasi input data", "Persentase data tanpa koreksi atau kesalahan.", "NUMERIC", "%", "AVERAGE", "HIGHER", 98, null, 30, 1),
    indicator("ADM-02", "Ketepatan laporan", "Persentase laporan yang dikirim tepat waktu.", "NUMERIC", "%", "AVERAGE", "HIGHER", 100, null, 25, 2),
    indicator("ADM-03", "Kelengkapan dokumen", "Persentase dokumen eligible yang lengkap.", "NUMERIC", "%", "AVERAGE", "HIGHER", 98, null, 15, 3),
    indicator("ADM-04", "Rekonsiliasi data", "Persentase data yang berhasil direkonsiliasi.", "NUMERIC", "%", "AVERAGE", "HIGHER", 98, null, 15, 4),
    indicator("ADM-05", "Kehadiran dan disiplin", "Persentase kepatuhan terhadap jadwal kerja.", "NUMERIC", "%", "AVERAGE", "HIGHER", 95, null, 10, 5),
    indicator("ADM-06", "Kepatuhan SOP", "Rating kepatuhan terhadap SOP administrasi.", "RATING", "rating", "AVERAGE", "HIGHER", 4, null, 5, 6),
  ],
  GUDANG: [
    indicator("GUD-01", "Akurasi stok", "Persentase stok sistem yang sesuai hasil opname.", "NUMERIC", "%", "AVERAGE", "HIGHER", 98, null, 30, 1),
    indicator("GUD-02", "Selisih stok", "Persentase selisih stok terhadap catatan sistem.", "NUMERIC", "%", "AVERAGE", "LOWER", 2, 5, 20, 2),
    indicator("GUD-03", "Kecepatan penyediaan sparepart", "Persentase permintaan yang dipenuhi sesuai SLA.", "NUMERIC", "%", "AVERAGE", "HIGHER", 95, null, 15, 3),
    indicator("GUD-04", "Kelengkapan stok", "Persentase ketersediaan item wajib.", "NUMERIC", "%", "AVERAGE", "HIGHER", 95, null, 15, 4),
    indicator("GUD-05", "Stock opname", "Persentase pelaksanaan opname yang selesai tepat waktu.", "NUMERIC", "%", "AVERAGE", "HIGHER", 100, null, 10, 5),
    indicator("GUD-06", "Kerapian gudang", "Rating kerapian dan kebersihan area gudang.", "RATING", "rating", "AVERAGE", "HIGHER", 4, null, 5, 6),
    indicator("GUD-07", "Disiplin", "Persentase kepatuhan terhadap jadwal kerja.", "NUMERIC", "%", "AVERAGE", "HIGHER", 95, null, 5, 7),
  ],
} as const satisfies Record<string, readonly FixtureIndicator[]>;

const PROFILE_FACTORS = [0.82, 0.9, 0.96, 1, 1.05] as const;
const PROFILE_RATINGS = [3, 4, 4, 5, 5] as const;

export function fixtureValuesForIndicator(profileIndex: number, indicator: FixtureIndicator) {
  const profile = Math.abs(profileIndex) % PROFILE_FACTORS.length;
  if (indicator.kind === "RATING") return Array.from({ length: AUGUST_WORKED_DAYS }, () => PROFILE_RATINGS[profile]);
  if (indicator.direction === "ZERO_TOLERANCE") {
    return Array.from({ length: AUGUST_WORKED_DAYS }, (_, day) => profile < 2 && day === 0 ? 1_000 : 0);
  }

  const target = Number(indicator.target);
  let actual: number;
  if (indicator.direction === "LOWER") {
    const failureLimit = Number(indicator.failureLimit ?? target + 1);
    actual = [failureLimit, target + (failureLimit - target) * 0.75, target + (failureLimit - target) * 0.5, target, target * 0.5][profile];
  } else {
    actual = target * PROFILE_FACTORS[profile];
    if (indicator.unit === "%") actual = Math.min(100, actual);
  }
  return indicator.aggregation === "SUM" ? distribute(round4(actual)) : Array.from({ length: AUGUST_WORKED_DAYS }, () => round4(actual));
}

function distribute(total: number) {
  const daily = Math.floor(total * 10_000 / AUGUST_WORKED_DAYS) / 10_000;
  return Array.from({ length: AUGUST_WORKED_DAYS }, (_, day) => day === AUGUST_WORKED_DAYS - 1 ? round4(total - daily * (AUGUST_WORKED_DAYS - 1)) : daily);
}

function round4(value: number) {
  return Math.round(value * 10_000) / 10_000;
}

function indicator(
  code: string,
  name: string,
  description: string,
  kind: FixtureIndicator["kind"],
  unit: string,
  aggregation: FixtureIndicator["aggregation"],
  direction: FixtureIndicator["direction"],
  target: number,
  failureLimit: number | null,
  weight: number,
  sortOrder: number,
): FixtureIndicator {
  return { code, name, description, kind, unit, aggregation, direction, target, failureLimit, weight, sortOrder };
}
