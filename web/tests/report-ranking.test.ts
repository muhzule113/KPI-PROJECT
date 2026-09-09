import assert from "node:assert/strict";
import test from "node:test";
import { rankKpisByPosition, type RankingInput } from "../src/modules/reports/ranking.ts";

const row = (id: string, score: number, achievement: number): RankingInput => ({ id, employeeNumber: id, employeeName: id, positionId: "teknisi", positionCode: "POS-TEK", positionName: "Teknisi", branchName: "Pusat", status: "LOCKED", eligibility: "full", finalScore: score, items: [{ weight: 60, achievement }, { weight: 40, achievement: 100 }], corrections: [] });

test("ranking memakai jabatan, skor, indikator bobot tertinggi, shared rank, dan eligibility", () => {
  const excluded = { ...row("D", 100, 100), eligibility: "partial" };
  const ranked = rankKpisByPosition([row("B", 90, 95), row("A", 90, 95), row("C", 90, 80), excluded]).get("teknisi")!;
  assert.deepEqual(ranked.map((item) => [item.id, item.rank]), [["A", 1], ["B", 1], ["C", 3]]);
});
