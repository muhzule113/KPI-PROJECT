const dateInMakassar = new Intl.DateTimeFormat("en-CA", {
  timeZone: "Asia/Makassar",
  year: "numeric",
  month: "2-digit",
  day: "2-digit",
});

export function dateFromIso(value?: string) {
  return value && /^\d{4}-\d{2}-\d{2}$/.test(value) ? new Date(`${value}T00:00:00.000Z`) : undefined;
}

export function dateToIso(value: Date) {
  return dateInMakassar.format(value);
}

export function dateWithinBounds(value: string, min?: string, max?: string) {
  return (!min || value >= min) && (!max || value <= max);
}
