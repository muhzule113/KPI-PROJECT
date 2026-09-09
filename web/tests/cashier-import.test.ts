import assert from "node:assert/strict";
import test from "node:test";
import { PDFDocument, StandardFonts } from "pdf-lib";
import { normalizeCashierRows, parseCsv, parseMoney, parseTransactionDate, readTabularFile } from "../src/modules/imports/cashier-import.ts";
import { DEFAULT_CASHIER_MAPPING, validateCashierMapping } from "../src/modules/imports/mapping.ts";

test("import kasir mem-parsing format lokal, memetakan kasir, dan menolak duplikat", () => {
  assert.deepEqual(validateCashierMapping(DEFAULT_CASHIER_MAPPING), DEFAULT_CASHIER_MAPPING);
  assert.throws(() => validateCashierMapping({ ...DEFAULT_CASHIER_MAPPING, status: "transaction-number" }), /header kolom yang berbeda/i);
  assert.deepEqual(parseCsv('a;b\n"1;2";x', ";"), [["a", "b"], ["1;2", "x"]]);
  assert.equal(parseMoney("Rp 1.234,56"), 1234.56);
  assert.equal(parseTransactionDate("2026-02-31"), null);
  const header = ["No Transaksi", "Tanggal", "Kasir", "Total", "Kas Sistem", "Kas Aktual", "Durasi", "Status"];
  const rows = [header, ["TX-1", "09/09/2026", "KSR-001", "100000", "100000", "100000", "120", "SUCCESS"], ["TX-1", "09/09/2026", "KSR-001", "100000", "100000", "99000", "200", "SUCCESS"]];
  const result = normalizeCashierRows(rows, { periodStart: new Date("2026-09-01T00:00:00Z"), periodEnd: new Date("2026-09-30T00:00:00Z"), branchId: "branch-1", sourceApplication: "POS_SYSTEM", cashiers: [{ id: "cashier-1", name: "Ani", employeeNumber: "KSR-001", effectiveFrom: new Date("2026-01-01T00:00:00Z"), effectiveUntil: null }], existingBusinessKeys: new Set() });
  assert.equal(result.validRows, 1);
  assert.equal(result.errorRows, 1);
  assert.equal(result.duplicateRows, 1);
  assert.equal(result.normalizedRows[0].cashier_employee_id, "cashier-1");
});

test("PDF berbasis teks dibaca sebagai tabel dan footer halaman diabaikan", async () => {
  const document = await PDFDocument.create();
  const page = document.addPage();
  const font = await document.embedFont(StandardFonts.Helvetica);
  page.drawText("No Transaksi|Tanggal|Kasir|Total|Kas Sistem|Kas Aktual|Durasi|Status\nTX-1|09/09/2026|KSR-001|100000|100000|100000|120|SUCCESS", { x: 20, y: 700, size: 7, lineHeight: 12, font });
  const rows = await readTabularFile(Buffer.from(await document.save()), ".pdf");
  assert.equal(rows.length, 2);
  assert.equal(rows[0].length, 8);
  assert.equal(rows[1][0], "TX-1");
});
