import { clsx, type ClassValue } from "clsx";
import { twMerge } from "tailwind-merge";

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs));
}

export function formatDate(value: Date | string, options?: Intl.DateTimeFormatOptions) {
  return new Intl.DateTimeFormat("id-ID", { dateStyle: "medium", timeZone: "Asia/Makassar", ...options }).format(new Date(value));
}

export function formatNumber(value: { toNumber(): number } | number | null, maximumFractionDigits = 2) {
  if (value === null) return "-";
  const number = typeof value === "number" ? value : value.toNumber();
  return new Intl.NumberFormat("id-ID", { maximumFractionDigits }).format(number);
}
