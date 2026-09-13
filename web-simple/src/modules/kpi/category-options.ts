import Decimal from "decimal.js";
import type { Direction } from "./calculation.ts";

export const CATEGORY_LABELS = ["Sangat Baik", "Baik", "Cukup", "Kurang", "Sangat Kurang"] as const;

/** Konfigurasi lima rentang tetap untuk indikator Angka + predikat. */
export type CategoryBandInput = {
  label: string;
  /** Empat baris pertama memakai ambang; baris kelima adalah rentang terbuka. */
  threshold: number | string | { toString(): string } | null;
  sortOrder: number;
  isActive?: boolean;
};

export type CategoryBandSnapshot = {
  id: string;
  label: string;
  threshold: number | string | null;
  /** Optional only for TypeScript compatibility with legacy consumers. */
  score?: string | null;
  sortOrder: number;
};

/** Alias legacy agar import lama tetap dapat membaca snapshot berbasis skor. */
export type CategoryOptionInput = {
  label: string;
  score: number | string;
  threshold?: number | string | null;
  sortOrder: number;
  isActive?: boolean;
};

export type CategoryOptionSnapshot = CategoryBandSnapshot | {
  id: string;
  label: string;
  score: string;
  sortOrder: number;
};

type ValidationResult = { ok: true } | { ok: false; reason: string };

const parseDecimal = (value: unknown) => {
  if (value === null || value === undefined || value === "") return null;
  try {
    const decimal = new Decimal(value as Decimal.Value);
    return decimal.isFinite() ? decimal : null;
  } catch {
    return null;
  }
};

export function validateCategoryBands(
  bands: readonly CategoryBandInput[],
  direction: Direction = "HIGHER",
  unit = "",
): ValidationResult {
  void direction;
  if (bands.length !== 5) return { ok: false, reason: "Indikator Angka + predikat wajib memiliki tepat lima tingkat." };
  const ordered = [...bands].sort((left, right) => left.sortOrder - right.sortOrder);
  if (ordered.some((band, index) => band.sortOrder !== index + 1 || band.isActive === false)) {
    return { ok: false, reason: "Lima tingkat predikat harus aktif dan berurutan 1 sampai 5." };
  }
  if (ordered.some((band) => !band.label.trim())) return { ok: false, reason: "Label predikat wajib diisi." };
  const labels = ordered.map((band) => band.label.trim().toLocaleLowerCase());
  if (new Set(labels).size !== labels.length) return { ok: false, reason: "Label predikat tidak boleh sama." };

  const thresholds = ordered.slice(0, 4).map((band) => parseDecimal(band.threshold));
  if (thresholds.some((threshold) => threshold === null)) return { ok: false, reason: "Empat batas predikat wajib diisi dengan angka." };
  if (ordered[4].threshold !== null) {
    return { ok: false, reason: "Tingkat Sangat Kurang harus menjadi rentang terbuka tanpa batas." };
  }
  if (thresholds.some((threshold) => threshold!.lt(0) || threshold!.gt(1_000_000_000))) {
    return { ok: false, reason: "Batas predikat harus non-negatif dan maksimal 1.000.000.000." };
  }
  if (thresholds.some((threshold) => threshold!.decimalPlaces() > 4)) {
    return { ok: false, reason: "Batas predikat maksimal memiliki empat angka desimal." };
  }
  if (thresholds.some((threshold, index) => index > 0 && threshold!.lte(thresholds[index - 1]!))) {
    return { ok: false, reason: "Empat batas predikat harus meningkat dan tidak tumpang tindih." };
  }
  if (unit.trim() === "%" && thresholds.some((threshold) => threshold!.gt(100))) {
    return { ok: false, reason: "Batas predikat untuk satuan % tidak boleh lebih dari 100." };
  }
  return { ok: true };
}

/** Pemeriksaan kompatibilitas untuk data konfigurasi lama berbasis score. */
export function validateCategoryOptions(options: readonly CategoryOptionInput[]): ValidationResult {
  if (options.some((option) => option.threshold !== undefined) && options.every((option) => option.score === undefined)) {
    return validateCategoryBands(options.map((option) => ({
      label: option.label,
      threshold: option.threshold ?? null,
      sortOrder: option.sortOrder,
      isActive: option.isActive,
    })));
  }
  if (!options.some((option) => option.isActive !== false)) return { ok: false, reason: "Indikator kategori wajib memiliki minimal satu kategori aktif." };
  if (options.some((option) => !option.label.trim())) return { ok: false, reason: "Label kategori wajib diisi." };
  const labels = options.map((option) => option.label.trim().toLowerCase());
  if (new Set(labels).size !== labels.length) return { ok: false, reason: "Label kategori tidak boleh sama." };
  const orders = options.map((option) => option.sortOrder);
  if (orders.some((order) => !Number.isInteger(order) || order < 1) || new Set(orders).size !== orders.length) {
    return { ok: false, reason: "Urutan kategori harus berupa bilangan bulat positif yang unik." };
  }
  if (options.some((option) => {
    const score = parseDecimal(option.score);
    return score === null || score.lt(0) || score.gt(100);
  })) return { ok: false, reason: "Skor kategori harus berada pada rentang 0 sampai 100." };
  return { ok: true };
}

export function categoryBandsFromSnapshot(value: unknown): CategoryBandSnapshot[] {
  if (!Array.isArray(value)) throw new Error("Snapshot predikat indikator tidak valid.");
  const bands = value.map((candidate): CategoryBandSnapshot => {
    if (!candidate || typeof candidate !== "object") throw new Error("Snapshot predikat indikator tidak valid.");
    const band = candidate as Record<string, unknown>;
    if (typeof band.id !== "string" || typeof band.label !== "string" || !Number.isInteger(band.sortOrder) || !Object.prototype.hasOwnProperty.call(band, "threshold")) {
      throw new Error("Snapshot predikat indikator tidak lengkap.");
    }
    const threshold = band.threshold === null ? null : parseDecimal(band.threshold);
    if (band.threshold !== null && threshold === null) throw new Error("Snapshot predikat indikator memiliki batas tidak valid.");
    return { id: band.id, label: band.label, threshold: threshold?.toFixed(4).replace(/0+$/, "").replace(/\.$/, "") ?? null, sortOrder: band.sortOrder as number };
  });
  return bands.sort((left, right) => left.sortOrder - right.sortOrder);
}

/** Membaca snapshot baru dan snapshot lama tanpa memutakhirkan periode yang sudah dibuka. */
export function categoryOptionsFromSnapshot(value: unknown): CategoryOptionSnapshot[] {
  if (!Array.isArray(value)) throw new Error("Snapshot kategori tidak valid.");
  return value.map((candidate): CategoryOptionSnapshot => {
    if (!candidate || typeof candidate !== "object") throw new Error("Snapshot kategori tidak valid.");
    const option = candidate as Record<string, unknown>;
    if (typeof option.id !== "string" || typeof option.label !== "string" || !Number.isInteger(option.sortOrder)) {
      throw new Error("Snapshot kategori tidak lengkap.");
    }
    if (Object.prototype.hasOwnProperty.call(option, "threshold")) {
      const threshold = option.threshold === null ? null : parseDecimal(option.threshold);
      if (option.threshold !== null && threshold === null) throw new Error("Snapshot kategori memiliki batas tidak valid.");
      return { id: option.id, label: option.label, threshold: threshold?.toFixed(4).replace(/0+$/, "").replace(/\.$/, "") ?? null, sortOrder: option.sortOrder as number };
    }
    const score = parseDecimal(option.score);
    if (score === null || score.lt(0) || score.gt(100)) throw new Error("Snapshot kategori memiliki skor tidak valid.");
    return { id: option.id, label: option.label, score: score.toFixed(2), sortOrder: option.sortOrder as number };
  });
}

export function resolveCategoryOption(snapshot: readonly CategoryOptionSnapshot[], optionId: string) {
  const option = snapshot.find((candidate) => candidate.id === optionId);
  if (!option) throw new Error("Kategori yang dipilih tidak tersedia pada periode ini.");
  return option;
}

/** Menentukan label rentang; `sortOrder` selalu berarti urutan terbaik ke terburuk. */
export function resolveCategoryBand(value: number | string, bands: readonly CategoryBandSnapshot[], direction: Direction) {
  const actual = parseDecimal(value);
  if (actual === null) return null;
  const ordered = [...bands].sort((left, right) => left.sortOrder - right.sortOrder);
  const thresholds = ordered.filter((band) => band.threshold !== null)
    .map((band) => ({ band, threshold: new Decimal(band.threshold!) }))
    .sort((left, right) => left.threshold.cmp(right.threshold));
  if (thresholds.length !== 4 || ordered.length < 5) return null;
  if (direction === "HIGHER") {
    if (actual.lt(thresholds[0].threshold)) return ordered[4];
    if (actual.lt(thresholds[1].threshold)) return ordered[3];
    if (actual.lt(thresholds[2].threshold)) return ordered[2];
    if (actual.lt(thresholds[3].threshold)) return ordered[1];
    return ordered[0];
  }
  if (actual.lte(thresholds[0].threshold)) return ordered[0];
  if (actual.lte(thresholds[1].threshold)) return ordered[1];
  if (actual.lte(thresholds[2].threshold)) return ordered[2];
  if (actual.lte(thresholds[3].threshold)) return ordered[3];
  return ordered[4];
}

export function isLegacyCategorySnapshot(value: unknown) {
  return Array.isArray(value) && value.some((candidate) => candidate && typeof candidate === "object" && Object.prototype.hasOwnProperty.call(candidate, "score"));
}
