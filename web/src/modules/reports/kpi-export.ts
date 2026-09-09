import { strToU8, zipSync } from "fflate";
import { PDFDocument, StandardFonts, rgb } from "pdf-lib";

export type ReportValue = string | number | bigint | null | undefined;
export type ReportTable = {
  headings: readonly string[];
  rows: ReadonlyArray<ReadonlyArray<ReportValue>>;
};

export const canStartKpiExport = (recentExports: number) => recentExports < 5;

const valueText = (value: ReportValue) => String(value ?? "");
const safeSpreadsheetText = (value: ReportValue) => {
  const text = valueText(value);
  return /^\s*[=+\-@]/.test(text) ? `'${text}` : text;
};
const csvCell = (value: ReportValue) => `"${safeSpreadsheetText(value).replaceAll('"', '""')}"`;

export function createCsv({ headings, rows }: ReportTable) {
  return `\uFEFF${[headings, ...rows].map((row) => row.map(csvCell).join(",")).join("\r\n")}`;
}

const xmlText = (value: ReportValue) => valueText(value)
  .replace(/[\u0000-\u0008\u000B\u000C\u000E-\u001F]/g, "")
  .replaceAll("&", "&amp;")
  .replaceAll("<", "&lt;")
  .replaceAll(">", "&gt;");

function columnName(index: number) {
  let name = "";
  for (let value = index + 1; value > 0; value = Math.floor((value - 1) / 26)) {
    name = String.fromCharCode(65 + ((value - 1) % 26)) + name;
  }
  return name;
}

export function createXlsx({ headings, rows }: ReportTable) {
  const allRows = [headings, ...rows];
  const sheetRows = allRows.map((row, rowIndex) => {
    const cells = row.map((value, columnIndex) => `<c r="${columnName(columnIndex)}${rowIndex + 1}" t="inlineStr"><is><t xml:space="preserve">${xmlText(value)}</t></is></c>`).join("");
    return `<row r="${rowIndex + 1}">${cells}</row>`;
  }).join("");
  const lastCell = `${columnName(Math.max(0, headings.length - 1))}${Math.max(1, allRows.length)}`;

  return zipSync({
    "[Content_Types].xml": strToU8('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>'),
    "_rels/.rels": strToU8('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>'),
    "xl/workbook.xml": strToU8('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Rekap KPI" sheetId="1" r:id="rId1"/></sheets></workbook>'),
    "xl/_rels/workbook.xml.rels": strToU8('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>'),
    "xl/worksheets/sheet1.xml": strToU8(`<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:${lastCell}"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>${sheetRows}</sheetData><autoFilter ref="A1:${lastCell}"/></worksheet>`),
  }, { level: 6 });
}

const pdfColumnWidths = [18, 14, 24, 18, 18, 18, 10, 10, 18, 20, 20, 20];

export async function createPdf(title: string, generatedBy: string, { headings, rows }: ReportTable) {
  const document = await PDFDocument.create();
  const regular = await document.embedFont(StandardFonts.Courier);
  const bold = await document.embedFont(StandardFonts.CourierBold);
  const titleFont = await document.embedFont(StandardFonts.HelveticaBold);
  const supported = new Set(regular.getCharacterSet());
  const safeText = (value: ReportValue) => Array.from(valueText(value).replace(/\s+/g, " ").trim())
    .map((character) => supported.has(character.codePointAt(0) ?? 0) ? character : "?")
    .join("");
  const fit = (value: ReportValue, width: number) => {
    const text = safeText(value);
    const clipped = text.length > width ? `${text.slice(0, Math.max(1, width - 3))}...` : text;
    return clipped.padEnd(width);
  };
  const line = (cells: readonly ReportValue[]) => cells.map((cell, index) => fit(cell, pdfColumnWidths[index] ?? 14)).join(" | ");
  const width = 841.89;
  const height = 595.28;
  const addPage = () => {
    const page = document.addPage([width, height]);
    page.drawText(safeText(title), { x: 30, y: height - 34, size: 15, font: titleFont, color: rgb(0.05, 0.2, 0.14) });
    page.drawText(safeText(`Dibuat oleh ${generatedBy} pada ${new Date().toLocaleString("id-ID")}`), { x: 30, y: height - 50, size: 7, font: regular, color: rgb(0.35, 0.4, 0.38) });
    page.drawRectangle({ x: 27, y: height - 75, width: width - 54, height: 16, color: rgb(0.05, 0.2, 0.14) });
    page.drawText(line(headings), { x: 30, y: height - 70, size: 5, font: bold, color: rgb(1, 1, 1) });
    return { page, y: height - 88 };
  };

  let state = addPage();
  rows.forEach((row, index) => {
    if (state.y < 35) state = addPage();
    if (index % 2) state.page.drawRectangle({ x: 27, y: state.y - 3, width: width - 54, height: 12, color: rgb(0.95, 0.97, 0.96) });
    state.page.drawText(line(row), { x: 30, y: state.y, size: 5, font: regular, color: rgb(0.08, 0.1, 0.09) });
    state.y -= 12;
  });

  document.getPages().forEach((page, index, pages) => {
    page.drawText(`${index + 1}/${pages.length}`, { x: width - 48, y: 17, size: 7, font: regular, color: rgb(0.35, 0.4, 0.38) });
  });
  document.setTitle(safeText(title));
  document.setAuthor(safeText(generatedBy));
  document.setProducer("KPI OPS");
  return document.save();
}
