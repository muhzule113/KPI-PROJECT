import Decimal from "decimal.js";
import type { Aggregation, Direction, ValueKind } from "./calculation.ts";
import { validateCategoryBands, type CategoryBandInput } from "./category-options.ts";

export type TemplateIndicator = {
  code: string;
  kind: ValueKind;
  unit?: string;
  aggregation: Aggregation;
  direction: Direction;
  target: number | string;
  failureLimit: number | string | null;
  weight: number | string;
  activeCategoryOptions?: number;
  categoryBands?: readonly CategoryBandInput[];
};

export type RatingBandSnapshot = {
  code: string;
  label: string;
  minScore: number | string;
  sortOrder: number;
};

export type PeriodTemplateSelectionInput = Readonly<{
  positionId: string;
  templateVersionId: string;
}>;

export function validatePeriodTemplateSelections(
  selections: readonly PeriodTemplateSelectionInput[],
  requiredPositionIds: readonly string[] = [],
): { ok: true } | { ok: false; reason: string } {
  if (!selections.length) return { ok: false, reason: "Minimal satu versi template harus dipilih." };
  const positionIds = selections.map((selection) => selection.positionId);
  if (new Set(positionIds).size !== positionIds.length) return { ok: false, reason: "Satu jabatan hanya boleh memiliki satu versi template per periode." };
  if (requiredPositionIds.some((positionId) => !positionIds.includes(positionId))) return { ok: false, reason: "Versi template untuk seluruh jabatan yang dinilai wajib dipilih." };
  if (selections.some((selection) => !selection.positionId || !selection.templateVersionId)) return { ok: false, reason: "Jabatan dan versi template wajib dipilih." };
  return { ok: true };
}

const RATING_CODES = ["POOR", "FAIR", "GOOD", "VERY_GOOD", "STAR"] as const;

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/;

function parseDate(value: string) {
  if (!ISO_DATE.test(value)) throw new Error("Tanggal harus memakai format YYYY-MM-DD.");
  const date = new Date(`${value}T00:00:00.000Z`);
  if (Number.isNaN(date.valueOf()) || date.toISOString().slice(0, 10) !== value) throw new Error("Tanggal tidak valid.");
  return date;
}

export function assessmentDates(input: {
  periodStart: string;
  periodEnd: string;
  joinedAt: string;
  endedAt: string | null;
}) {
  const start = new Date(Math.max(parseDate(input.periodStart).valueOf(), parseDate(input.joinedAt).valueOf()));
  const end = new Date(Math.min(parseDate(input.periodEnd).valueOf(), input.endedAt ? parseDate(input.endedAt).valueOf() : Number.POSITIVE_INFINITY));
  if (start > end) return [];

  const dates: string[] = [];
  for (const date = start; date <= end; date.setUTCDate(date.getUTCDate() + 1)) dates.push(date.toISOString().slice(0, 10));
  return dates;
}

export function validateTemplate(indicators: readonly TemplateIndicator[]): { ok: true } | { ok: false; reason: string } {
  if (indicators.length === 0) return { ok: false, reason: "Template harus memiliki minimal satu indikator." };
  const codes = indicators.map((indicator) => indicator.code.trim().toUpperCase());
  if (codes.some((code) => !code) || new Set(codes).size !== codes.length) return { ok: false, reason: "Kode indikator wajib diisi dan harus unik." };

  for (const indicator of indicators) {
    const weight = new Decimal(indicator.weight);
    const target = new Decimal(indicator.target);
    if (!weight.isFinite() || weight.lte(0) || weight.gt(100)) return { ok: false, reason: "Bobot tiap indikator harus lebih dari 0 dan maksimal 100." };
    if (!target.isFinite()) return { ok: false, reason: `Target ${indicator.code} tidak valid.` };
    if (indicator.kind === "RATING" || indicator.kind === "CHECKBOX") {
      if (indicator.aggregation !== "AVERAGE") {
        return { ok: false, reason: `Indikator ${indicator.code} wajib memakai agregasi AVERAGE.` };
      }
      if (indicator.direction !== "HIGHER") return { ok: false, reason: `Arah indikator ${indicator.code} harus HIGHER.` };
    }
    if (indicator.kind === "RATING") {
      if (target.lt(1) || target.gt(5)) return { ok: false, reason: "Target rating harus 1 sampai 5 dengan arah HIGHER." };
    } else if (indicator.kind === "CHECKBOX") {
      if (!target.eq(100)) return { ok: false, reason: `Target ${indicator.code} untuk indikator centang wajib 100.` };
    } else if (indicator.kind === "CATEGORY") {
      if (indicator.categoryBands) {
        const bandsValidation = validateCategoryBands(indicator.categoryBands, indicator.direction, indicator.unit ?? "");
        if (!bandsValidation.ok) return { ok: false, reason: `${indicator.code}: ${bandsValidation.reason}` };
      } else if (indicator.activeCategoryOptions !== 5) {
        return { ok: false, reason: `Indikator ${indicator.code} wajib memiliki lima tingkat predikat aktif (kategori aktif).` };
      }
      if (indicator.direction !== "ZERO_TOLERANCE" && target.lte(0)) return { ok: false, reason: `Target ${indicator.code} harus lebih besar dari 0.` };
    } else if (indicator.direction !== "ZERO_TOLERANCE" && target.lte(0)) {
      return { ok: false, reason: `Target ${indicator.code} harus lebih besar dari 0.` };
    }
    if (indicator.direction === "LOWER") {
      if (indicator.failureLimit === null || new Decimal(indicator.failureLimit).lte(target)) {
        return { ok: false, reason: `Failure limit ${indicator.code} harus lebih besar dari target.` };
      }
    }
  }

  const totalWeight = indicators.reduce((total, indicator) => total.plus(indicator.weight), new Decimal(0));
  return totalWeight.eq(100) ? { ok: true } : { ok: false, reason: "Total bobot template harus tepat 100%." };
}

export function validateRatingBands(bands: readonly RatingBandSnapshot[]): { ok: true } | { ok: false; reason: string } {
  if (bands.length !== RATING_CODES.length) return { ok: false, reason: "Skala wajib memiliki tepat lima predikat." };
  const ordered = [...bands].sort((left, right) => left.sortOrder - right.sortOrder);
  if (ordered.map((band) => band.code).join() !== RATING_CODES.join()) return { ok: false, reason: "Kode dan urutan predikat tidak valid." };
  if (new Set(ordered.map((band) => band.sortOrder)).size !== ordered.length) return { ok: false, reason: "Urutan predikat harus unik." };
  if (ordered.some((band) => !band.label.trim())) return { ok: false, reason: "Label predikat wajib diisi." };

  const minimums = ordered.map((band) => new Decimal(band.minScore));
  if (minimums.some((minimum) => !minimum.isFinite() || minimum.lt(0) || minimum.gt(100))) return { ok: false, reason: "Batas predikat harus berada pada rentang 0 sampai 100." };
  if (!minimums[0].eq(0)) return { ok: false, reason: "Predikat terendah harus dimulai dari 0." };
  if (minimums.some((minimum, index) => index > 0 && minimum.lte(minimums[index - 1]))) return { ok: false, reason: "Batas minimum predikat harus meningkat dan unik." };
  return { ok: true };
}

export function ratingBandsFromSnapshot(value: unknown): RatingBandSnapshot[] {
  if (!Array.isArray(value) || value.length === 0) throw new Error("Snapshot predikat tidak valid.");
  const bands = value.map((candidate) => {
    if (!candidate || typeof candidate !== "object") throw new Error("Snapshot predikat tidak valid.");
    const band = candidate as Record<string, unknown>;
    if (typeof band.code !== "string" || typeof band.label !== "string" ||
      (typeof band.minScore !== "string" && typeof band.minScore !== "number") ||
      !Number.isInteger(band.sortOrder)) throw new Error("Snapshot predikat tidak lengkap.");
    const minimum = new Decimal(band.minScore);
    if (!minimum.isFinite() || minimum.lt(0) || minimum.gt(100)) throw new Error("Snapshot predikat memiliki batas tidak valid.");
    return { code: band.code, label: band.label, minScore: band.minScore, sortOrder: band.sortOrder as number };
  });
  if (new Set(bands.map((band) => band.code)).size !== bands.length) throw new Error("Snapshot predikat memiliki kode duplikat.");
  return bands;
}

export function ratingForScore(score: number | string, bands: readonly RatingBandSnapshot[]) {
  const value = new Decimal(score);
  if (!value.isFinite() || value.lt(0) || value.gt(100)) throw new Error("Skor harus berada pada rentang 0 sampai 100.");
  const band = [...bands]
    .sort((left, right) => new Decimal(right.minScore).cmp(new Decimal(left.minScore)))
    .find((candidate) => value.gte(candidate.minScore));
  if (!band) throw new Error("Snapshot predikat tidak mencakup skor ini.");
  return { code: band.code, label: band.label };
}
