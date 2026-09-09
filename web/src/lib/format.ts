const dateFormatter = new Intl.DateTimeFormat("id-ID", {
  day: "2-digit",
  month: "short",
  year: "numeric",
  timeZone: "Asia/Makassar",
});

const numberFormatter = new Intl.NumberFormat("id-ID", { maximumFractionDigits: 2 });
const moneyFormatter = new Intl.NumberFormat("id-ID", {
  style: "currency",
  currency: "IDR",
  maximumFractionDigits: 0,
});

export const formatDate = (value: Date | string) => dateFormatter.format(new Date(value));
export const formatNumber = (value: number | string) => numberFormatter.format(Number(value));
export const formatMoney = (value: number | string) => moneyFormatter.format(Number(value));

export function initials(name: string) {
  return name
    .split(/\s+/)
    .slice(0, 2)
    .map((word) => word[0])
    .join("")
    .toUpperCase();
}
