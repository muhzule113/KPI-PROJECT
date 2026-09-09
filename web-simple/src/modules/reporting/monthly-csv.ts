const FORMULA_PREFIX = /^[=+\-@\t\r]/;

export type MonthlyCsvRow = {
  period: string;
  employeeNumber: string;
  employeeName: string;
  branch: string;
  position: string;
  status: string;
  finalScore: string;
  rating: string;
  noScoreReason: string;
  finalizedAt: string;
};

function cell(value: string) {
  const safe = FORMULA_PREFIX.test(value) ? `'${value}` : value;
  return `"${safe.replaceAll('"', '""')}"`;
}

export function monthlyCsv(rows: MonthlyCsvRow[]) {
  const header = ["Periode", "NIP", "Nama", "Cabang", "Jabatan", "Status", "Nilai akhir", "Predikat", "Alasan tanpa nilai", "Difinalkan pada"];
  return [
    header.map(cell).join(","),
    ...rows.map((row) => [
      row.period,
      row.employeeNumber,
      row.employeeName,
      row.branch,
      row.position,
      row.status,
      row.finalScore,
      row.rating,
      row.noScoreReason,
      row.finalizedAt,
    ].map(cell).join(",")),
  ].join("\r\n");
}
