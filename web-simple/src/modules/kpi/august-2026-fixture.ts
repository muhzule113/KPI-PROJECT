import { KPI_CATEGORY_SCALE, MASTER_KPI_TEMPLATES, type MasterKpiIndicator } from "./master-kpi-templates.ts";

export const AUGUST_WORKED_DAYS = 30;

export type RosterPositionCode = "CREW" | "KASIR" | "PELAYAN" | "TEKNISI" | "KURIR" | "ADMIN_OPS";

export type FixtureCategoryOption = { label: string; score: number | string; threshold?: number | string | null; sortOrder: number; isActive?: boolean; id?: string };
export type FixtureIndicator = Omit<MasterKpiIndicator, "categoryOptions"> & { categoryOptions?: readonly FixtureCategoryOption[] };

export const AUGUST_2026_ROSTER = [
  { name: "Cumi", username: "cumi", employeeNumber: "AUG26-001", positionCode: "CREW" },
  { name: "Ica", username: "ica", employeeNumber: "AUG26-002", positionCode: "KASIR" },
  { name: "Nurajizah", username: "nurajizah", employeeNumber: "AUG26-003", positionCode: "PELAYAN" },
  { name: "Miming", username: "miming", employeeNumber: "AUG26-004", positionCode: "TEKNISI" },
  { name: "Muhammad Novriansyah", username: "muhammad.novriansyah", employeeNumber: "AUG26-005", positionCode: "TEKNISI" },
  { name: "Akmal", username: "akmal", employeeNumber: "AUG26-006", positionCode: "TEKNISI" },
  { name: "Ani", username: "ani", employeeNumber: "AUG26-007", positionCode: "CREW" },
  { name: "Pandy", username: "pandy", employeeNumber: "AUG26-008", positionCode: "TEKNISI" },
  { name: "Wahyu", username: "wahyu", employeeNumber: "AUG26-009", positionCode: "TEKNISI" },
  { name: "Jusri", username: "jusri", employeeNumber: "AUG26-010", positionCode: "TEKNISI" },
  { name: "Lastri", username: "lastri", employeeNumber: "AUG26-011", positionCode: "PELAYAN" },
  { name: "Mikma", username: "mikma", employeeNumber: "AUG26-012", positionCode: "KASIR" },
  { name: "Awalia", username: "awalia", employeeNumber: "AUG26-013", positionCode: "KASIR" },
  { name: "Soleha", username: "soleha", employeeNumber: "AUG26-014", positionCode: "PELAYAN" },
  { name: "Abu Rizal", username: "abu.rizal", employeeNumber: "AUG26-015", positionCode: "TEKNISI" },
  { name: "Andi Syukur", username: "andi.syukur", employeeNumber: "AUG26-016", positionCode: "TEKNISI" },
  { name: "Ida Ayu Putri Shalihah", username: "ida.ayu.putri.shalihah", employeeNumber: "AUG26-017", positionCode: "KASIR" },
  { name: "Rsky Rahmawati A.", username: "rsky.rahmawati.a", employeeNumber: "AUG26-018", positionCode: "KASIR" },
  { name: "Mia", username: "mia", employeeNumber: "AUG26-019", positionCode: "KASIR" },
  { name: "Muhammad Akbar", username: "muhammad.akbar", employeeNumber: "AUG26-020", positionCode: "TEKNISI" },
  { name: "Dzul", username: "dzul", employeeNumber: "AUG26-021", positionCode: "TEKNISI" },
  { name: "Muh. Ikhsan", username: "muh.ikhsan", employeeNumber: "AUG26-022", positionCode: "TEKNISI" },
  { name: "Abrar Syaputra", username: "abrar.syaputra", employeeNumber: "AUG26-023", positionCode: "TEKNISI" },
  { name: "Faisal/Icahl", username: "faisal.icahl", employeeNumber: "AUG26-024", positionCode: "TEKNISI" },
  { name: "Kaneki", username: "kaneki", employeeNumber: "AUG26-025", positionCode: "TEKNISI" },
  { name: "Ciu Andi", username: "ciu.andi", employeeNumber: "AUG26-026", positionCode: "TEKNISI" },
  { name: "Andi Al-Faruq", username: "andi.al-faruq", employeeNumber: "AUG26-027", positionCode: "KURIR" },
  { name: "Pipah", username: "pipah", employeeNumber: "AUG26-028", positionCode: "CREW" },
  { name: "Eva", username: "eva", employeeNumber: "AUG26-029", positionCode: "KASIR" },
  { name: "Fani", username: "fani", employeeNumber: "AUG26-030", positionCode: "KASIR" },
  { name: "Sela", username: "sela", employeeNumber: "AUG26-031", positionCode: "PELAYAN" },
  { name: "Ria", username: "ria", employeeNumber: "AUG26-032", positionCode: "PELAYAN" },
  { name: "Astri", username: "astri", employeeNumber: "AUG26-033", positionCode: "CREW" },
  { name: "Kia", username: "kia", employeeNumber: "AUG26-034", positionCode: "KASIR" },
  { name: "Nita", username: "nita", employeeNumber: "AUG26-035", positionCode: "ADMIN_OPS" },
] as const satisfies readonly { name: string; username: string; employeeNumber: string; positionCode: RosterPositionCode }[];

export const FIXTURE_TEMPLATES = {
  CREW: [
    indicator("CRW-01", "Tugas operasional selesai", "Jumlah tugas operasional yang selesai.", "NUMERIC", "tugas", "SUM", "HIGHER", 180, null, 40, 1),
    indicator("CRW-02", "Kualitas pekerjaan", "Rating ketelitian dan kualitas hasil kerja.", "RATING", "rating", "AVERAGE", "HIGHER", 4, null, 35, 2),
    indicator("CRW-03", "Kedisiplinan kerja", "Rating kepatuhan terhadap jadwal dan prosedur.", "RATING", "rating", "AVERAGE", "HIGHER", 4, null, 25, 3),
  ],
  KURIR: [
    indicator("KUR-01", "Pengantaran selesai", "Jumlah pengantaran yang selesai dan tercatat.", "NUMERIC", "pengantaran", "SUM", "HIGHER", 120, null, 40, 1),
    indicator("KUR-02", "Ketepatan waktu", "Persentase pengantaran yang tiba sesuai jadwal.", "NUMERIC", "%", "AVERAGE", "HIGHER", 95, null, 35, 2),
    indicator("KUR-03", "Kedisiplinan kerja", "Rating kepatuhan terhadap jadwal dan prosedur.", "RATING", "rating", "AVERAGE", "HIGHER", 4, null, 25, 3),
  ],
  ...MASTER_KPI_TEMPLATES,
} as const satisfies Record<string, readonly FixtureIndicator[]>;

const PROFILE_FACTORS = [0.82, 0.9, 0.96, 1, 1.05] as const;
const PROFILE_RATINGS = [3, 4, 4, 5, 5] as const;


// Profil 0 memakai kategori berskor terendah; profil terakhir memakai yang tertinggi.
export function fixtureCategoryOption(profileIndex: number, indicator: FixtureIndicator) {
  const profile = Math.abs(profileIndex) % PROFILE_FACTORS.length;
  const options: readonly FixtureCategoryOption[] = [...(indicator.categoryOptions ?? KPI_CATEGORY_SCALE)].sort((left, right) => Number(left.score) - Number(right.score));
  const option = options[Math.min(profile, options.length - 1)];
  return { id: option.id ?? null, score: round4(Number(option.score)) };
}

export function fixtureValuesForIndicator(profileIndex: number, indicator: FixtureIndicator) {
  const profile = Math.abs(profileIndex) % PROFILE_FACTORS.length;
  if (indicator.kind === "RATING") return Array.from({ length: AUGUST_WORKED_DAYS }, () => PROFILE_RATINGS[profile]);
  if (indicator.kind === "CATEGORY") {
    const score = fixtureCategoryOption(profileIndex, indicator).score;
    return Array.from({ length: AUGUST_WORKED_DAYS }, () => score);
  }
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
