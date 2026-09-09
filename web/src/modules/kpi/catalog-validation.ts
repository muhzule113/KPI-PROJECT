export type RatingBandInput = { code: string; minScore: number; maxScore: number; manualScore: number };

export function validateRatingBands(bands: RatingBandInput[], scoreCap = 100) {
  const issues: string[] = [];
  const expectedCodes = ["FAIR", "GOOD", "POOR", "STAR", "VERY_GOOD"];
  if (bands.length !== 5 || [...new Set(bands.map((band) => band.code))].sort().join() !== expectedCodes.join()) issues.push("Skema wajib memakai lima kode predikat internal yang unik.");
  if (new Set(bands.map((band) => band.manualScore)).size !== bands.length) issues.push("Nilai pilihan manual harus unik.");
  const sorted = [...bands].sort((left, right) => left.minScore - right.minScore);
  let expected = 0;
  for (const band of sorted) {
    if (![band.minScore, band.maxScore, band.manualScore].every((value) => Number.isFinite(value) && value >= 0 && value <= scoreCap)) issues.push("Semua nilai predikat harus berada dalam rentang skor.");
    if (band.maxScore < band.minScore) issues.push("Batas maksimum predikat tidak boleh lebih kecil dari minimum.");
    if (Math.abs(band.minScore - expected) > 0.000001) issues.push(band.minScore < expected ? "Rentang predikat tumpang tindih." : "Rentang predikat memiliki gap.");
    expected = Math.round((band.maxScore + 0.01) * 100) / 100;
  }
  if (!sorted.length || Math.abs(sorted.at(-1)!.maxScore - scoreCap) > 0.000001) issues.push("Rentang predikat harus menutup skor 0 sampai score cap.");
  return [...new Set(issues)];
}
