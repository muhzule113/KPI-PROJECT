export type RankingInput = {
  id: string;
  employeeNumber: string;
  employeeName: string;
  positionId: string;
  positionCode: string;
  positionName: string;
  branchName: string;
  status: string;
  eligibility: string;
  finalScore: number | null;
  items: Array<{ weight: number; achievement: number | null }>;
  corrections: Array<{ status: string }>;
};

export type RankingRow = RankingInput & { rank: number; tieBreakAchievement: number; correctionInProgress: boolean };

export function rankKpisByPosition(rows: RankingInput[]) {
  const groups = new Map<string, RankingRow[]>();
  for (const row of rows) {
    if (row.status !== "LOCKED" || row.eligibility !== "full" || row.finalScore == null) continue;
    const highestWeight = [...row.items].sort((left, right) => right.weight - left.weight)[0];
    const ranked = { ...row, rank: 0, tieBreakAchievement: highestWeight?.achievement ?? 0, correctionInProgress: row.corrections.some((item) => item.status.toLowerCase() === "pending") };
    groups.set(row.positionId, [...(groups.get(row.positionId) ?? []), ranked]);
  }
  for (const group of groups.values()) {
    group.sort((left, right) => right.finalScore! - left.finalScore! || right.tieBreakAchievement - left.tieBreakAchievement || left.employeeNumber.localeCompare(right.employeeNumber));
    let previous = "";
    group.forEach((row, index) => {
      const key = `${row.finalScore}|${row.tieBreakAchievement}`;
      row.rank = key === previous ? group[index - 1].rank : index + 1;
      previous = key;
    });
  }
  return groups;
}
