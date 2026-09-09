export const CASHIER_MAPPING_FIELDS = [
  { key: "transaction_number", label: "Nomor transaksi" },
  { key: "transaction_date", label: "Tanggal transaksi" },
  { key: "cashier_name", label: "Nama kasir" },
  { key: "transaction_amount", label: "Nilai transaksi" },
  { key: "system_cash_amount", label: "Kas sistem" },
  { key: "actual_cash_amount", label: "Kas aktual" },
  { key: "duration_seconds", label: "Durasi (detik)" },
  { key: "status", label: "Status transaksi" },
] as const;

export type CashierMappingKey = (typeof CASHIER_MAPPING_FIELDS)[number]["key"];
export const REQUIRED_COLUMNS = CASHIER_MAPPING_FIELDS.map((field) => field.key);
export const DEFAULT_CASHIER_MAPPING = Object.fromEntries(REQUIRED_COLUMNS.map((column) => [column, column])) as Record<CashierMappingKey, string>;

export const normalizeMappingHeader = (value: unknown) => String(value ?? "")
  .normalize("NFKD")
  .toLowerCase()
  .replace(/\p{M}/gu, "")
  .replace(/[^a-z0-9]/g, "");

export function validateCashierMapping(input: Record<string, unknown>) {
  const mapping = {} as Record<CashierMappingKey, string>;
  const headers = new Set<string>();
  for (const field of CASHIER_MAPPING_FIELDS) {
    const header = String(input[field.key] ?? "").trim();
    const normalized = normalizeMappingHeader(header);
    if (!normalized || header.length > 150) throw new Error(`Header ${field.label} wajib diisi dan maksimal 150 karakter.`);
    if (headers.has(normalized)) throw new Error("Setiap data harus memakai header kolom yang berbeda.");
    headers.add(normalized);
    mapping[field.key] = header;
  }
  return mapping;
}
