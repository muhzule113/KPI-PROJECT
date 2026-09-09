import assert from "node:assert/strict";
import test from "node:test";
import {
  assessmentDates,
  ratingBandsFromSnapshot,
  ratingForScore,
  validateRatingBands,
  validateTemplate,
} from "../src/modules/kpi/period.ts";

const reasonOf = (result: ReturnType<typeof validateTemplate>) => result.ok ? "" : result.reason;
const ratingReasonOf = (result: ReturnType<typeof validateRatingBands>) => result.ok ? "" : result.reason;

test("periode membuat satu lembar untuk setiap tanggal kalender", () => {
  const dates = assessmentDates({ periodStart: "2026-09-01", periodEnd: "2026-09-30", joinedAt: "2025-01-01", endedAt: null });
  assert.equal(dates.length, 30);
  assert.equal(dates[0], "2026-09-01");
  assert.equal(dates.at(-1), "2026-09-30");
});

test("tanggal penilaian dibatasi masa kerja pegawai", () => {
  assert.deepEqual(assessmentDates({ periodStart: "2026-09-01", periodEnd: "2026-09-30", joinedAt: "2026-09-10", endedAt: "2026-09-20" }), [
    "2026-09-10", "2026-09-11", "2026-09-12", "2026-09-13", "2026-09-14", "2026-09-15",
    "2026-09-16", "2026-09-17", "2026-09-18", "2026-09-19", "2026-09-20",
  ]);
  assert.deepEqual(assessmentDates({ periodStart: "2026-09-01", periodEnd: "2026-09-30", joinedAt: "2026-10-01", endedAt: null }), []);
});

test("template valid memiliki kode unik dan bobot tepat 100", () => {
  assert.deepEqual(validateTemplate([
    { code: "PROD", kind: "NUMERIC", aggregation: "SUM", direction: "HIGHER", target: 100, failureLimit: null, weight: 60 },
    { code: "QUALITY", kind: "RATING", aggregation: "AVERAGE", direction: "HIGHER", target: 4, failureLimit: null, weight: 40 },
  ]), { ok: true });
});

test("template menolak bobot, kode, rating, dan lower yang tidak valid", () => {
  const base = [
    { code: "A", kind: "NUMERIC" as const, aggregation: "SUM" as const, direction: "HIGHER" as const, target: 10, failureLimit: null, weight: 50 },
    { code: "B", kind: "NUMERIC" as const, aggregation: "SUM" as const, direction: "HIGHER" as const, target: 10, failureLimit: null, weight: 49 },
  ];
  assert.match(reasonOf(validateTemplate(base)), /100/);
  assert.match(reasonOf(validateTemplate([{ ...base[0] }, { ...base[0] }])), /unik/i);
  assert.match(reasonOf(validateTemplate([{ ...base[0], kind: "RATING", aggregation: "SUM", weight: 100 }])), /average/i);
  assert.match(reasonOf(validateTemplate([{ ...base[0], direction: "LOWER", target: 5, failureLimit: 4, weight: 100 }])), /failure limit/i);
});

test("predikat bulanan mengikuti snapshot skala pada setiap batas nilai", () => {
  const bands = [
    { code: "POOR", label: "Perlu Perbaikan", minScore: 0, sortOrder: 1 },
    { code: "FAIR", label: "Cukup", minScore: 70, sortOrder: 2 },
    { code: "GOOD", label: "Baik", minScore: 80, sortOrder: 3 },
    { code: "VERY_GOOD", label: "Sangat Baik", minScore: 90, sortOrder: 4 },
    { code: "STAR", label: "Istimewa", minScore: 95, sortOrder: 5 },
  ];

  assert.deepEqual(validateRatingBands(bands), { ok: true });
  assert.deepEqual(ratingForScore(95, bands), { code: "STAR", label: "Istimewa" });
  assert.deepEqual(ratingForScore(94.99, bands), { code: "VERY_GOOD", label: "Sangat Baik" });
  assert.deepEqual(ratingForScore(80, bands), { code: "GOOD", label: "Baik" });
  assert.deepEqual(ratingForScore(70, bands), { code: "FAIR", label: "Cukup" });
  assert.deepEqual(ratingForScore(0, bands), { code: "POOR", label: "Perlu Perbaikan" });
  assert.throws(() => ratingForScore(-0.01, bands), /0 sampai 100/i);
  assert.throws(() => ratingForScore(100.01, bands), /0 sampai 100/i);
});

test("snapshot predikat menolak bentuk data yang tidak lengkap", () => {
  assert.throws(() => ratingBandsFromSnapshot([{ code: "GOOD", label: "Baik" }]), /snapshot/i);
});

test("skala predikat menolak band hilang, urutan salah, dan batas duplikat", () => {
  const bands = [
    { code: "POOR", label: "Perlu Perbaikan", minScore: 0, sortOrder: 1 },
    { code: "FAIR", label: "Cukup", minScore: 70, sortOrder: 2 },
    { code: "GOOD", label: "Baik", minScore: 80, sortOrder: 3 },
    { code: "VERY_GOOD", label: "Sangat Baik", minScore: 90, sortOrder: 4 },
    { code: "STAR", label: "Istimewa", minScore: 95, sortOrder: 5 },
  ];
  assert.match(ratingReasonOf(validateRatingBands(bands.slice(0, 4))), /lima/i);
  assert.match(ratingReasonOf(validateRatingBands(bands.map((band) => band.code === "STAR" ? { ...band, code: "VERY_GOOD" } : band))), /kode/i);
  assert.match(ratingReasonOf(validateRatingBands(bands.map((band) => band.code === "STAR" ? { ...band, minScore: 90 } : band))), /meningkat|unik/i);
});
