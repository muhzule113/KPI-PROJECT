import assert from "node:assert/strict";
import test from "node:test";
import { PDFDocument } from "pdf-lib";
import readXlsxFile from "read-excel-file/node";
import { canStartKpiExport, createCsv, createPdf, createXlsx } from "../src/modules/reports/kpi-export.ts";

test("ekspor laporan menghasilkan CSV aman, XLSX terbaca, dan PDF valid", async () => {
  assert.equal(canStartKpiExport(4), true);
  assert.equal(canStartKpiExport(5), false);
  const table = { headings: ["Nama", "Nilai"], rows: [["=2+2", 95], ["Siti & Rekan <Toko>", null]] };
  assert.match(createCsv(table), /"'=2\+2"/);

  const xlsx = createXlsx(table);
  const parsed = await readXlsxFile(Buffer.from(xlsx));
  assert.deepEqual(parsed[0]?.data, [["Nama", "Nilai"], ["=2+2", "95"], ["Siti & Rekan <Toko>", null]]);

  const pdf = await createPdf("Rekap KPI", "Penguji Ω", table);
  assert.equal(Buffer.from(pdf).subarray(0, 5).toString(), "%PDF-");
  assert.equal((await PDFDocument.load(pdf)).getPageCount(), 1);
});
