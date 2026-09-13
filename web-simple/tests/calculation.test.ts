import assert from "node:assert/strict";
import test from "node:test";
import {
  calculateFinalScore,
  calculateMonthlyIndicator,
  type MonthlyIndicatorInput,
} from "../src/modules/kpi/calculation.ts";

const base: MonthlyIndicatorInput = {
  kind: "NUMERIC",
  aggregation: "SUM",
  direction: "HIGHER",
  values: [10, 0, 15],
  target: 20,
  failureLimit: null,
  weight: 40,
};

test("nilai kosong tetap pending dan tidak dianggap nol", () => {
  assert.deepEqual(calculateMonthlyIndicator({ ...base, values: [] }), {
    status: "PENDING",
    actual: null,
    achievement: null,
    weightedScore: null,
    note: "Belum ada nilai harian yang disetujui.",
  });
});

test("nilai numerik dapat dijumlahkan atau dirata-ratakan", () => {
  assert.equal(calculateMonthlyIndicator(base).actual, "25.00");
  assert.equal(calculateMonthlyIndicator({ ...base, aggregation: "AVERAGE" }).actual, "8.33");
});

test("higher dan rating berhenti pada pencapaian 100", () => {
  assert.deepEqual(calculateMonthlyIndicator(base), {
    status: "CALCULATED",
    actual: "25.00",
    achievement: "100.00",
    weightedScore: "40.00",
  });
  assert.equal(calculateMonthlyIndicator({
    ...base,
    kind: "RATING",
    aggregation: "AVERAGE",
    values: [4, 5, 3],
    target: 4,
    weight: 25,
  }).weightedScore, "25.00");
});

test("lower menurun linear sampai failure limit", () => {
  assert.deepEqual(calculateMonthlyIndicator({
    ...base,
    direction: "LOWER",
    aggregation: "AVERAGE",
    values: [4],
    target: 2,
    failureLimit: 6,
    weight: 30,
  }), {
    status: "CALCULATED",
    actual: "4.00",
    achievement: "50.00",
    weightedScore: "15.00",
  });
});

test("zero tolerance membedakan nol dari kejadian pelanggaran", () => {
  assert.equal(calculateMonthlyIndicator({ ...base, direction: "ZERO_TOLERANCE", values: [0, 0] }).achievement, "100.00");
  assert.equal(calculateMonthlyIndicator({ ...base, direction: "ZERO_TOLERANCE", values: [0, 1] }).achievement, "0.00");
});

test("konfigurasi formula yang tidak sah menghasilkan unscorable", () => {
  assert.equal(calculateMonthlyIndicator({ ...base, target: 0 }).status, "UNSCORABLE");
  assert.equal(calculateMonthlyIndicator({ ...base, direction: "LOWER", target: 5, failureLimit: 5 }).status, "UNSCORABLE");
});

test("skor akhir hanya tersedia jika semua indikator terhitung", () => {
  const calculated = calculateMonthlyIndicator(base);
  assert.equal(calculateFinalScore([calculated, { ...calculated, weightedScore: "35.25" }]), "75.25");
  assert.equal(calculateFinalScore([calculated, calculateMonthlyIndicator({ ...base, values: [] })]), null);
});

test("skor kategori dipakai langsung tanpa normalisasi target", () => {
  // mean([85, 85, 85]) = 85 -> achievement = actual = 85 (bukan 85/90x100 = 94.44)
  // weightedScore = 85 x 10/100 = 8.50
  assert.deepEqual(calculateMonthlyIndicator({
    kind: "CATEGORY",
    aggregation: "AVERAGE",
    direction: "HIGHER",
    values: [85, 85, 85],
    target: 90,
    failureLimit: null,
    weight: 10,
    legacyCategoryScoring: true,
  }), {
    status: "CALCULATED",
    actual: "85.00",
    achievement: "85.00",
    weightedScore: "8.50",
  });

  // Pembanding skala maksimum: achievement 100 -> 100 x 10/100 = 10.00
  assert.equal(calculateMonthlyIndicator({
    kind: "CATEGORY",
    aggregation: "AVERAGE",
    direction: "HIGHER",
    values: [100],
    target: 100,
    failureLimit: null,
    weight: 10,
    legacyCategoryScoring: true,
  }).weightedScore, "10.00");
});

test("Angka + predikat CATEGORY memakai rumus target biasa", () => {
  const result = calculateMonthlyIndicator({
    kind: "CATEGORY",
    aggregation: "AVERAGE",
    direction: "HIGHER",
    values: [85],
    target: 90,
    failureLimit: null,
    weight: 10,
  });
  assert.equal(result.actual, "85.00");
  assert.equal(result.achievement, "94.44");
  assert.equal(result.weightedScore, "9.44");
});

test("checkbox dinormalisasi 0/1 menjadi 0/100 sebelum agregasi", () => {
  const checkbox = {
    kind: "CHECKBOX" as const,
    aggregation: "AVERAGE" as const,
    direction: "HIGHER" as const,
    target: 100,
    failureLimit: null,
    weight: 20,
  };

  // [1, 1, 0, 1] -> [100, 100, 0, 100]; mean = 300/4 = 75
  // achievement = 75/100 x 100 = 75; weightedScore = 75 x 20/100 = 15.00
  assert.deepEqual(calculateMonthlyIndicator({ ...checkbox, values: [1, 1, 0, 1] }), {
    status: "CALCULATED",
    actual: "75.00",
    achievement: "75.00",
    weightedScore: "15.00",
  });

  // Semua tercentang: mean 100 -> 100 x 20/100 = 20.00
  assert.equal(calculateMonthlyIndicator({ ...checkbox, values: [1, 1] }).weightedScore, "20.00");

  // Semua tidak tercentang: mean 0 -> achievement 0.00 yang sah, bukan PENDING
  assert.deepEqual(calculateMonthlyIndicator({ ...checkbox, values: [0, 0] }), {
    status: "CALCULATED",
    actual: "0.00",
    achievement: "0.00",
    weightedScore: "0.00",
  });
});

test("agregasi LATEST mengambil nilai harian terakhir", () => {
  const latest = {
    kind: "NUMERIC" as const,
    aggregation: "LATEST" as const,
    direction: "HIGHER" as const,
    target: 100,
    failureLimit: null,
    weight: 50,
  };

  // [40, 60, 55] -> elemen terakhir 55; achievement = 55/100 x 100 = 55
  assert.equal(calculateMonthlyIndicator({ ...latest, values: [40, 60, 55] }).actual, "55.00");

  // Urutan array bermakna: membalik urutan mengganti nilai terakhir menjadi 40
  assert.equal(calculateMonthlyIndicator({ ...latest, values: [55, 60, 40] }).actual, "40.00");
});

test("agregasi COUNT menghitung banyak nilai, bukan menjumlahkan", () => {
  const count = {
    kind: "NUMERIC" as const,
    aggregation: "COUNT" as const,
    direction: "HIGHER" as const,
    target: 3,
    failureLimit: null,
    weight: 10,
  };

  // 4 nilai -> actual 4.00; achievement = min(100, 4/3 x 100 = 133.33) = 100.00
  // weightedScore = 100 x 10/100 = 10.00
  assert.deepEqual(calculateMonthlyIndicator({ ...count, values: [7, 8, 9, 10] }), {
    status: "CALCULATED",
    actual: "4.00",
    achievement: "100.00",
    weightedScore: "10.00",
  });

  // 1 nilai -> actual 1.00; achievement = 1/3 x 100 = 33.333... -> 33.33
  // weightedScore = 33.333... x 10/100 = 3.333... -> 3.33
  assert.deepEqual(calculateMonthlyIndicator({ ...count, values: [7] }), {
    status: "CALCULATED",
    actual: "1.00",
    achievement: "33.33",
    weightedScore: "3.33",
  });
});

test("jenis indikator baru tetap pending saat belum ada nilai", () => {
  for (const kind of ["CATEGORY", "CHECKBOX", "SYSTEM", "IMPORTED"] as const) {
    assert.deepEqual(calculateMonthlyIndicator({
      kind,
      aggregation: "AVERAGE",
      direction: "HIGHER",
      values: [],
      target: 100,
      failureLimit: null,
      weight: 25,
    }), {
      status: "PENDING",
      actual: null,
      achievement: null,
      weightedScore: null,
      note: "Belum ada nilai harian yang disetujui.",
    });
  }
});

test("skor kategori tidak terpengaruh guard target", () => {
  // Jalur CATEGORY memakai achievement = actual sehingga target 90 (atau target apa pun)
  // tidak boleh memicu UNSCORABLE.
  const result = calculateMonthlyIndicator({
    kind: "CATEGORY",
    aggregation: "AVERAGE",
    direction: "HIGHER",
    values: [70],
    target: 90,
    failureLimit: null,
    weight: 100,
    legacyCategoryScoring: true,
  });
  assert.equal(result.status, "CALCULATED");
  assert.equal(result.achievement, "70.00");
});

test("skor akhir menjumlahkan hasil kategori dan checkbox", () => {
  const category = calculateMonthlyIndicator({
    kind: "CATEGORY",
    aggregation: "AVERAGE",
    direction: "HIGHER",
    values: [85, 85, 85],
    target: 90,
    failureLimit: null,
    weight: 10,
    legacyCategoryScoring: true,
  });
  const checkbox = calculateMonthlyIndicator({
    kind: "CHECKBOX",
    aggregation: "AVERAGE",
    direction: "HIGHER",
    values: [1, 1, 0, 1],
    target: 100,
    failureLimit: null,
    weight: 20,
  });
  // 8.50 (kategori) + 15.00 (checkbox) = 23.50
  assert.equal(calculateFinalScore([category, checkbox]), "23.50");
});
