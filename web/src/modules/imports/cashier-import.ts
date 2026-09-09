import { readSheet } from "read-excel-file/node";
import { PDFParse } from "pdf-parse";
import { normalizeMappingHeader, REQUIRED_COLUMNS } from "./mapping.ts";

export const VALID_TRANSACTION_STATUSES = new Set(["SUCCESS", "REFUND", "VOID", "CANCELLED"]);
export const MAX_IMPORT_FILE_BYTES = 10 * 1024 * 1024;
export const MAX_IMPORT_ROWS = 20_000;
export const ALLOWED_IMPORT_EXTENSIONS = new Set([".csv", ".xlsx", ".pdf"]);

export function assertCashierImportFileSignature(buffer: Buffer, extension: string) {
  if (extension === ".pdf" && buffer.subarray(0, 5).toString("ascii") !== "%PDF-") throw new Error("Isi file tidak sesuai format PDF.");
  if (extension === ".xlsx" && buffer.subarray(0, 4).toString("binary") !== "PK\u0003\u0004") throw new Error("Isi file tidak sesuai format XLSX.");
  if (extension === ".csv" && buffer.includes(0)) throw new Error("CSV mengandung data biner dan ditolak.");
  if (!ALLOWED_IMPORT_EXTENSIONS.has(extension)) throw new Error("Format yang didukung hanya XLSX, CSV, dan PDF.");
}

type Cell = string | number | boolean | Date | null;
type Issue = { row: number; severity: "error" | "warning"; code: string; message: string };
type Cashier = { id: string; name: string; employeeNumber: string; effectiveFrom: Date; effectiveUntil: Date | null };

const aliases: Record<string, (typeof REQUIRED_COLUMNS)[number]> = {
  transactionnumber: "transaction_number", transactionno: "transaction_number", notransaksi: "transaction_number", noinvoice: "transaction_number", invoice: "transaction_number", transaksi: "transaction_number", nomor: "transaction_number",
  transactiondate: "transaction_date", tanggal: "transaction_date", date: "transaction_date", waktu: "transaction_date",
  cashiername: "cashier_name", cashier: "cashier_name", kasir: "cashier_name", namakasir: "cashier_name", operator: "cashier_name",
  transactionamount: "transaction_amount", amount: "transaction_amount", total: "transaction_amount", grandtotal: "transaction_amount", nominal: "transaction_amount", totaltransaksi: "transaction_amount",
  systemcashamount: "system_cash_amount", systemcash: "system_cash_amount", kassistem: "system_cash_amount", kassistim: "system_cash_amount", totalsistem: "system_cash_amount",
  actualcashamount: "actual_cash_amount", actualcash: "actual_cash_amount", kasaktual: "actual_cash_amount", kasfisik: "actual_cash_amount", totalaktual: "actual_cash_amount",
  durationseconds: "duration_seconds", duration: "duration_seconds", durasi: "duration_seconds", durasidetik: "duration_seconds", seconds: "duration_seconds",
  status: "status", tipe: "status", state: "status",
};

const normalizedHeader = (value: Cell) => normalizeMappingHeader(value);
const text = (value: Cell) => String(value ?? "").trim();

export function parseCsv(input: string, delimiter = ",") {
  const rows: string[][] = [];
  let row: string[] = [];
  let cell = "";
  let quoted = false;
  for (let index = 0; index < input.length; index += 1) {
    const char = input[index];
    if (quoted && char === '"' && input[index + 1] === '"') { cell += '"'; index += 1; continue; }
    if (char === '"') { quoted = !quoted; continue; }
    if (!quoted && char === delimiter) { row.push(cell); cell = ""; continue; }
    if (!quoted && (char === "\n" || char === "\r")) {
      if (char === "\r" && input[index + 1] === "\n") index += 1;
      row.push(cell); rows.push(row); row = []; cell = ""; continue;
    }
    cell += char;
  }
  if (quoted) throw new Error("CSV memiliki tanda kutip yang tidak ditutup.");
  if (cell || row.length) { row.push(cell); rows.push(row); }
  return rows;
}

export async function readTabularFile(buffer: Buffer, extension: string): Promise<Cell[][]> {
  if (extension === ".csv") {
    const input = new TextDecoder("utf-8", { fatal: true }).decode(buffer).replace(/^\uFEFF/, "");
    const firstLine = input.split(/\r?\n/, 1)[0] ?? "";
    const delimiter = [",", ";", "\t"].sort((a, b) => firstLine.split(b).length - firstLine.split(a).length)[0];
    return parseCsv(input, delimiter);
  }
  if (extension === ".xlsx") return await readSheet(buffer) as Cell[][];
  if (extension === ".pdf") {
    const parser = new PDFParse({ data: buffer });
    try {
      const text = (await parser.getText()).text;
      const lines = text.split(/\r?\n/).map((line) => line.trim()).filter((line) => line && !/^-- \d+ of \d+ --$/.test(line));
      if (lines.length < 2) return [];
      const delimiter = ["\t", ";", ",", "|"].sort((left, right) => lines[0].split(right).length - lines[0].split(left).length)[0];
      return lines[0].includes(delimiter) ? parseCsv(lines.join("\n"), delimiter) : lines.map((line) => line.split(/\s{2,}/));
    } finally {
      await parser.destroy();
    }
  }
  throw new Error("Format tabel tidak didukung.");
}

export function mapHeaders(headers: Cell[], configured: unknown = {}) {
  const mapping = configured !== null && !Array.isArray(configured) && typeof configured === "object" ? configured as Record<string, unknown> : {};
  const configuredByHeader = new Map(Object.entries(mapping).flatMap(([field, header]) => REQUIRED_COLUMNS.includes(field as (typeof REQUIRED_COLUMNS)[number]) && typeof header === "string" ? [[normalizedHeader(header), field as (typeof REQUIRED_COLUMNS)[number]]] : []));
  const result = new Map<(typeof REQUIRED_COLUMNS)[number], number>();
  headers.forEach((header, index) => {
    const normalized = normalizedHeader(header);
    const field = configuredByHeader.size ? configuredByHeader.get(normalized) : aliases[normalized];
    if (!field) return;
    if (result.has(field)) throw new Error(`Lebih dari satu kolom dipetakan ke ${field}.`);
    result.set(field, index);
  });
  return result;
}

export function parseMoney(value: Cell) {
  if (typeof value === "number") return Number.isFinite(value) ? value : null;
  let raw = text(value).replace(/[^0-9,.-]/g, "");
  if (!raw || raw === "-") return null;
  const comma = raw.lastIndexOf(",");
  const dot = raw.lastIndexOf(".");
  if (comma >= 0 && dot >= 0) {
    const decimal = Math.max(comma, dot);
    raw = raw.slice(0, decimal).replace(/[,.]/g, "") + "." + raw.slice(decimal + 1);
  } else if (comma >= 0 && raw.length - comma - 1 === 2) raw = raw.slice(0, comma).replaceAll(".", "") + "." + raw.slice(comma + 1);
  else raw = raw.replaceAll(",", "");
  const number = Number(raw);
  return Number.isFinite(number) ? number : null;
}

export function parseTransactionDate(value: Cell) {
  if (value instanceof Date && !Number.isNaN(value.valueOf())) return value;
  if (typeof value === "number" && value > 0 && value < 100_000) return new Date(Date.UTC(1899, 11, 30) + Math.floor(value) * 86_400_000);
  const raw = text(value);
  const iso = /^(\d{4})-(\d{2})-(\d{2})/.exec(raw);
  const local = /^(\d{1,2})[/-](\d{1,2})[/-](\d{4})$/.exec(raw);
  const canonical = iso ? `${iso[1]}-${iso[2]}-${iso[3]}` : local ? `${local[3]}-${local[2].padStart(2, "0")}-${local[1].padStart(2, "0")}` : null;
  const date = canonical ? new Date(`${canonical}T00:00:00.000Z`) : null;
  return date && !Number.isNaN(date.valueOf()) && date.toISOString().slice(0, 10) === canonical ? date : null;
}

export function normalizeCashierRows(rows: Cell[][], input: { periodStart: Date; periodEnd: Date; branchId: string; sourceApplication: string; cashiers: Cashier[]; existingBusinessKeys: Set<string>; mapping?: unknown }) {
  if (rows.length < 2) throw new Error("File kosong atau tidak memiliki baris data setelah header.");
  const columns = mapHeaders(rows[0], input.mapping);
  const missing = REQUIRED_COLUMNS.filter((field) => !columns.has(field));
  if (missing.length) throw new Error(`Header wajib tidak lengkap: ${missing.join(", ")}.`);
  const value = (row: Cell[], field: (typeof REQUIRED_COLUMNS)[number]) => row[columns.get(field)!] ?? null;
  const normalizedRows: Array<Record<string, string | number | null>> = [];
  const issues: Issue[] = [];
  const seen = new Set<string>();
  let duplicateRows = 0;

  rows.slice(1).forEach((row, offset) => {
    if (row.every((cell) => text(cell) === "")) return;
    const rowNumber = offset + 2;
    const transactionNumber = text(value(row, "transaction_number"));
    const cashierNameRaw = text(value(row, "cashier_name"));
    const transactionDate = parseTransactionDate(value(row, "transaction_date"));
    const transactionAmount = parseMoney(value(row, "transaction_amount"));
    const systemCashAmount = parseMoney(value(row, "system_cash_amount"));
    const actualCashAmount = parseMoney(value(row, "actual_cash_amount"));
    const durationRaw = Number(text(value(row, "duration_seconds")));
    const durationSeconds = Number.isInteger(durationRaw) && durationRaw >= 0 ? durationRaw : null;
    const status = text(value(row, "status")).toUpperCase();
    const businessKey = `${input.sourceApplication}|${input.branchId}|${transactionNumber.toLowerCase()}`;
    const rowIssues: Issue[] = [];
    const add = (code: string, message: string) => rowIssues.push({ row: rowNumber, severity: "error", code, message });
    if (!transactionNumber) add("missing_transaction_number", "Nomor transaksi wajib diisi.");
    if (!transactionDate) add("invalid_transaction_date", "Tanggal transaksi tidak valid.");
    if (transactionDate && (transactionDate < input.periodStart || transactionDate > input.periodEnd)) add("transaction_date_outside_period", "Tanggal transaksi berada di luar periode KPI.");
    if (transactionAmount === null || transactionAmount < 0) add("invalid_amount", "Nominal transaksi tidak valid.");
    if (systemCashAmount === null || systemCashAmount < 0) add("invalid_system_cash", "Kas sistem tidak valid.");
    if (actualCashAmount === null || actualCashAmount < 0) add("invalid_actual_cash", "Kas aktual tidak valid.");
    if (durationSeconds === null) add("invalid_duration", "Durasi transaksi harus bilangan bulat nol atau lebih.");
    if (!VALID_TRANSACTION_STATUSES.has(status)) add("invalid_status", "Status transaksi tidak dikenali.");
    const cashierMatches = transactionDate ? input.cashiers.filter((cashier) => (cashier.name.toLowerCase() === cashierNameRaw.toLowerCase() || cashier.employeeNumber.toLowerCase() === cashierNameRaw.toLowerCase()) && cashier.effectiveFrom <= transactionDate && (!cashier.effectiveUntil || cashier.effectiveUntil >= transactionDate)) : [];
    if (!cashierNameRaw || cashierMatches.length === 0) add("cashier_unresolved", `Kasir '${cashierNameRaw}' tidak ditemukan untuk cabang dan tanggal transaksi.`);
    if (cashierMatches.length > 1) add("cashier_ambiguous", `Kasir '${cashierNameRaw}' memiliki lebih dari satu placement yang cocok.`);
    if (seen.has(businessKey)) { add("duplicate_in_file", `Transaksi '${transactionNumber}' berulang dalam file.`); duplicateRows += 1; }
    if (input.existingBusinessKeys.has(businessKey)) { add("duplicate_in_database", `Transaksi '${transactionNumber}' sudah ada pada periode ini.`); duplicateRows += 1; }
    seen.add(businessKey);
    issues.push(...rowIssues);
    normalizedRows.push({ row_number: rowNumber, row_status: rowIssues.length ? "error" : "valid", transaction_number: transactionNumber, transaction_date: transactionDate?.toISOString() ?? null, cashier_employee_id: cashierMatches.length === 1 ? cashierMatches[0].id : null, cashier_name_raw: cashierNameRaw, transaction_amount: transactionAmount, system_cash_amount: systemCashAmount, actual_cash_amount: actualCashAmount, cash_difference: systemCashAmount !== null && actualCashAmount !== null ? Math.round(Math.abs(actualCashAmount - systemCashAmount) * 100) / 100 : null, duration_seconds: durationSeconds, status, business_key: businessKey });
  });
  if (!normalizedRows.length) throw new Error("File tidak memiliki baris transaksi.");
  return { normalizedRows, issues, totalRows: normalizedRows.length, validRows: normalizedRows.filter((row) => row.row_status === "valid").length, warningRows: 0, errorRows: normalizedRows.filter((row) => row.row_status === "error").length, duplicateRows };
}
