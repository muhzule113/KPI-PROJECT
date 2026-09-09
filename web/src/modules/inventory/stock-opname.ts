export function stockDifference(snapshot: number, current: number, physical: number) {
  if (![snapshot, current, physical].every(Number.isSafeInteger) || physical < 0) throw new Error("Jumlah stok tidak valid.");
  if (current !== snapshot) throw new Error("Stok berubah sejak snapshot. Buat opname baru dengan stok terbaru.");
  return physical - snapshot;
}
