const MAKASSAR_OFFSET_MS = 8 * 60 * 60 * 1000;

export function makassarDate(now: Date, daysAhead = 0) {
  const local = new Date(now.valueOf() + MAKASSAR_OFFSET_MS);
  return new Date(Date.UTC(local.getUTCFullYear(), local.getUTCMonth(), local.getUTCDate() + daysAhead));
}

export function makassarDayBoundsUtc(now: Date, daysAhead: number) {
  const date = makassarDate(now, daysAhead);
  const start = new Date(date.valueOf() - MAKASSAR_OFFSET_MS);
  return { start, end: new Date(start.valueOf() + 86_400_000) };
}
