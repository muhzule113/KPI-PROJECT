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
