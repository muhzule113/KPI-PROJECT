const makassarDate = new Intl.DateTimeFormat("en-CA", {
  timeZone: "Asia/Makassar",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
});

export function todayInMakassar(now = new Date()) {
  return makassarDate.format(now);
}

export function isoDate(value: Date) {
  return value.toISOString().slice(0, 10);
}
