import assert from "node:assert/strict";
import test from "node:test";
import {
  categoryOptionsFromSnapshot,
  resolveCategoryOption,
  validateCategoryOptions,
  validateCategoryBands,
  resolveCategoryBand,
  type CategoryOptionInput,
  type CategoryBandInput,
} from "../src/modules/kpi/category-options.ts";
import { KPI_CATEGORY_SCALE } from "../src/modules/kpi/master-kpi-templates.ts";

const REASON_ACTIVE = "Indikator kategori wajib memiliki minimal satu kategori aktif.";
const REASON_LABEL = "Label kategori wajib diisi.";
const REASON_LABEL_DUPLICATE = "Label kategori tidak boleh sama.";
const REASON_ORDER = "Urutan kategori harus berupa bilangan bulat positif yang unik.";
const REASON_SCORE = "Skor kategori harus berada pada rentang 0 sampai 100.";

test("skala katalog bersama diterima sebagai konfigurasi kategori", () => {
  // Sangat Baik 100 / Baik 85 / Cukup 70 / Kurang 55 / Sangat Kurang 40, urutan 1..5.
  assert.deepEqual(validateCategoryOptions(KPI_CATEGORY_SCALE), { ok: true });
});

test("kategori menolak daftar tanpa opsi aktif", () => {
  const allInactive: CategoryOptionInput[] = KPI_CATEGORY_SCALE.map((option) => ({ ...option, isActive: false }));
  assert.deepEqual(validateCategoryOptions(allInactive), { ok: false, reason: REASON_ACTIVE });
});

test("kategori menolak label kosong atau hanya spasi", () => {
  assert.deepEqual(validateCategoryOptions([{ label: "", score: 100, sortOrder: 1 }]), { ok: false, reason: REASON_LABEL });
  assert.deepEqual(validateCategoryOptions([{ label: "   ", score: 100, sortOrder: 1 }]), { ok: false, reason: REASON_LABEL });
});

test("kategori menolak label duplikat tanpa memandang kapitalisasi", () => {
  const duplicated: CategoryOptionInput[] = [
    { label: "Baik", score: 85, sortOrder: 1 },
    { label: "baik", score: 70, sortOrder: 2 },
  ];
  assert.deepEqual(validateCategoryOptions(duplicated), { ok: false, reason: REASON_LABEL_DUPLICATE });
});

test("kategori menolak urutan duplikat, nol, dan bukan bilangan bulat", () => {
  const duplicated: CategoryOptionInput[] = [
    { label: "Baik", score: 85, sortOrder: 1 },
    { label: "Cukup", score: 70, sortOrder: 1 },
  ];
  assert.deepEqual(validateCategoryOptions(duplicated), { ok: false, reason: REASON_ORDER });

  const zero: CategoryOptionInput[] = [
    { label: "Baik", score: 85, sortOrder: 0 },
    { label: "Cukup", score: 70, sortOrder: 1 },
  ];
  assert.deepEqual(validateCategoryOptions(zero), { ok: false, reason: REASON_ORDER });

  const fractional: CategoryOptionInput[] = [
    { label: "Baik", score: 85, sortOrder: 1.5 },
    { label: "Cukup", score: 70, sortOrder: 2 },
  ];
  assert.deepEqual(validateCategoryOptions(fractional), { ok: false, reason: REASON_ORDER });
});

test("kategori menolak skor di luar 0 sampai 100", () => {
  const tooHigh: CategoryOptionInput[] = [
    { label: "Baik", score: 101, sortOrder: 1 },
    { label: "Cukup", score: 70, sortOrder: 2 },
  ];
  assert.deepEqual(validateCategoryOptions(tooHigh), { ok: false, reason: REASON_SCORE });

  const negative: CategoryOptionInput[] = [
    { label: "Baik", score: -1, sortOrder: 1 },
    { label: "Cukup", score: 70, sortOrder: 2 },
  ];
  assert.deepEqual(validateCategoryOptions(negative), { ok: false, reason: REASON_SCORE });

  // Skor non-numerik harus ditolak sebagai alasan validasi, bukan exception DecimalError.
  const nonNumeric: CategoryOptionInput[] = [{ label: "Baik", score: "abc", sortOrder: 1 }];
  assert.deepEqual(validateCategoryOptions(nonNumeric), { ok: false, reason: REASON_SCORE });

  const emptyScore: CategoryOptionInput[] = [{ label: "Baik", score: "", sortOrder: 1 }];
  assert.deepEqual(validateCategoryOptions(emptyScore), { ok: false, reason: REASON_SCORE });
});

test("snapshot kategori menormalkan skor menjadi dua desimal", () => {
  // Bentuk JSON nyata hasil snapshot periode: skor tersimpan sebagai string.
  assert.deepEqual(categoryOptionsFromSnapshot([{ id: "opt-baik", label: "Baik", score: "85", sortOrder: 2 }]), [
    { id: "opt-baik", label: "Baik", score: "85.00", sortOrder: 2 },
  ]);
  // Skor numerik juga dinormalkan ke bentuk dua desimal.
  assert.equal(categoryOptionsFromSnapshot([{ id: "opt-cukup", label: "Cukup", score: 70, sortOrder: 3 }])[0].score, "70.00");
});

test("snapshot kategori menolak bentuk dan nilai yang tidak sah", () => {
  assert.throws(() => categoryOptionsFromSnapshot("bukan array"), /Snapshot kategori tidak valid\./);
  assert.throws(() => categoryOptionsFromSnapshot([null]), /Snapshot kategori tidak valid\./);
  assert.throws(
    () => categoryOptionsFromSnapshot([{ id: "opt-baik", label: "Baik", score: "85" }]),
    /Snapshot kategori tidak lengkap\./,
  );
  assert.throws(
    () => categoryOptionsFromSnapshot([{ id: "opt-baik", label: "Baik", score: "85", sortOrder: 1.5 }]),
    /Snapshot kategori tidak lengkap\./,
  );
  assert.throws(
    () => categoryOptionsFromSnapshot([{ id: "opt-baik", label: "Baik", score: 101, sortOrder: 1 }]),
    /Snapshot kategori memiliki skor tidak valid\./,
  );
  assert.throws(
    () => categoryOptionsFromSnapshot([{ id: "opt-baik", label: "Baik", score: "abc", sortOrder: 1 }]),
    /Snapshot kategori memiliki skor tidak valid\./,
  );
});

test("resolve kategori memilih opsi snapshot berdasarkan id", () => {
  const snapshot = categoryOptionsFromSnapshot([
    { id: "opt-sangat-baik", label: "Sangat Baik", score: 100, sortOrder: 1 },
    { id: "opt-baik", label: "Baik", score: "85", sortOrder: 2 },
  ]);
  assert.deepEqual(resolveCategoryOption(snapshot, "opt-baik"), {
    id: "opt-baik",
    label: "Baik",
    score: "85.00",
    sortOrder: 2,
  });
  assert.throws(() => resolveCategoryOption(snapshot, "opt-asing"), /Kategori yang dipilih tidak tersedia pada periode ini\./);
});

test("skor snapshot adalah sumber kebenaran, bukan kiriman klien", () => {
  // Snapshot dibekukan saat periode dibuka; resolver hanya membacanya sehingga
  // skor 85 selalu dikembalikan apa pun yang dikirim klien.
  const snapshot = categoryOptionsFromSnapshot([{ id: "opt-baik", label: "Baik", score: "85", sortOrder: 2 }]);
  assert.equal(resolveCategoryOption(snapshot, "opt-baik").score, "85.00");
  assert.equal(snapshot[0].score, "85.00");
});

const bands: CategoryBandInput[] = [
  { label: "Sangat Baik", threshold: 60, sortOrder: 1 },
  { label: "Baik", threshold: 70, sortOrder: 2 },
  { label: "Cukup", threshold: 80, sortOrder: 3 },
  { label: "Kurang", threshold: 90, sortOrder: 4 },
  { label: "Sangat Kurang", threshold: null, sortOrder: 5 },
];

test("Angka + predikat wajib lima label, empat batas desimal, dan batas unit", () => {
  assert.deepEqual(validateCategoryBands(bands, "HIGHER", "%"), { ok: true });
  assert.deepEqual(validateCategoryBands(bands.slice(0, 4), "HIGHER", "%").ok, false);
  assert.equal(validateCategoryBands(bands.map((band, index) => index === 2 ? { ...band, threshold: 70 } : band), "HIGHER", "%").ok, false);
  assert.equal(validateCategoryBands(bands.map((band, index) => index === 1 ? { ...band, threshold: 70.12345 } : band), "HIGHER", "%").ok, false);
  assert.equal(validateCategoryBands(bands.map((band, index) => index === 3 ? { ...band, threshold: 101 } : band), "HIGHER", "%").ok, false);
});

test("resolver rentang HIGHER, LOWER, dan ZERO_TOLERANCE tepat di batas", () => {
  const snapshot = bands.map((band, index) => ({ id: String(index + 1), ...band, threshold: band.threshold === null ? null : String(band.threshold) }));
  assert.equal(resolveCategoryBand("59.999", snapshot, "HIGHER")?.label, "Sangat Kurang");
  assert.equal(resolveCategoryBand(60, snapshot, "HIGHER")?.label, "Kurang");
  assert.equal(resolveCategoryBand(89.999, snapshot, "HIGHER")?.label, "Baik");
  assert.equal(resolveCategoryBand(90, snapshot, "HIGHER")?.label, "Sangat Baik");
  assert.equal(resolveCategoryBand(60, snapshot, "LOWER")?.label, "Sangat Baik");
  assert.equal(resolveCategoryBand("60.0001", snapshot, "LOWER")?.label, "Baik");
  assert.equal(resolveCategoryBand(90, snapshot, "ZERO_TOLERANCE")?.label, "Kurang");
  assert.equal(resolveCategoryBand(90.0001, snapshot, "ZERO_TOLERANCE")?.label, "Sangat Kurang");
});

test("snapshot threshold baru dan snapshot score legacy sama-sama dapat dibaca", () => {
  assert.deepEqual(categoryOptionsFromSnapshot([{ id: "1", label: "Sangat Baik", threshold: "60.125", sortOrder: 1 }]), [
    { id: "1", label: "Sangat Baik", threshold: "60.125", sortOrder: 1 },
  ]);
  assert.equal("score" in categoryOptionsFromSnapshot([{ id: "1", label: "Baik", score: "85", sortOrder: 2 }])[0], true);
});
