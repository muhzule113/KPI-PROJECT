import Link from "next/link";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { FormDialog } from "@/components/ui/form-dialog";
import { formatDate, formatNumber } from "@/lib/utils";

type DecimalValue = { toNumber(): number };
type WorkStatus = "WORKED" | "OFF" | "PERMIT" | "SICK";

type HistoryItem = {
  id: string;
  nameSnapshot: string;
  descriptionSnapshot: string | null;
  unitSnapshot: string;
  targetSnapshot: DecimalValue;
};

type HistorySheet = {
  id: string;
  entryDate: Date;
  status: string;
  workStatus: WorkStatus | null;
  managerWorkStatus: WorkStatus | null;
  effectiveWorkStatus: WorkStatus | null;
  note: string | null;
  managerReason: string | null;
  submittedAt: Date | null;
  managerReviewedAt: Date | null;
  values: Array<{
    monthlyKpiItemId: string;
    enteredValue: DecimalValue | null;
    managerValue: DecimalValue | null;
    effectiveValue: DecimalValue | null;
  }>;
  evidence: Array<{ id: string; fileName: string; fileSize: bigint }>;
};

export function DailyHistory({
  sheets,
  items,
  selectedSheetId,
  baseHref,
  canViewSelectedDetails,
  reviewEnabled = false,
  monthlyFinalized = false,
}: {
  sheets: HistorySheet[];
  items: HistoryItem[];
  selectedSheetId?: string;
  baseHref: string;
  canViewSelectedDetails: boolean;
  reviewEnabled?: boolean;
  monthlyFinalized?: boolean;
}) {
  const selectedSheet = sheets.find((sheet) => sheet.id === selectedSheetId);

  return <>
    <section className="panel stack-top">
      <div className="panel-header"><div><h2>Riwayat hari</h2><p>Status kerja dan persetujuan sampai hari ini</p></div></div>
      <div className="table-wrap"><table className="data-table"><thead><tr><th>Tanggal</th><th>Status kerja</th><th>Lembar</th><th>Catatan Manager</th><th></th></tr></thead><tbody>{sheets.map((sheet) => {
        const canReview = reviewEnabled && !monthlyFinalized && ["SUBMITTED", "APPROVED"].includes(sheet.status);
        return <tr key={sheet.id}>
          <td>{formatDate(sheet.entryDate)}</td>
          <td><StatusBadge status={sheet.effectiveWorkStatus ?? sheet.workStatus} /></td>
          <td><StatusBadge status={sheet.status} /></td>
          <td>{sheet.managerReason || "Tidak ada catatan"}</td>
          <td><div className="form-actions">
            <Button asChild size="small" variant="ghost"><Link href={`${baseHref}?sheet=${encodeURIComponent(sheet.id)}`}>Rincian</Link></Button>
            {canReview ? <Button asChild size="small" variant="secondary"><Link href={`/app/review?sheet=${sheet.id}`}>{sheet.status === "APPROVED" ? "Koreksi" : "Review"}</Link></Button> : null}
          </div></td>
        </tr>;
      })}</tbody></table></div>
    </section>
    {selectedSheet ? <FormDialog key={selectedSheet.id} title={`Penilaian ${formatDate(selectedSheet.entryDate)}`} description="Rincian status kerja, nilai indikator, koreksi, dan bukti pendukung." size="large" defaultOpen closeHref={baseHref}>
      <DailyHistoryDetail sheet={selectedSheet} items={items} visible={canViewSelectedDetails} />
    </FormDialog> : null}
  </>;
}

function DailyHistoryDetail({ sheet, items, visible }: { sheet: HistorySheet; items: HistoryItem[]; visible: boolean }) {
  if (!visible) return <div className="dialog-body"><div className="notice">Rincian nilai dan evidence tersedia setelah Supervisor mengirim lembar ini untuk review.</div></div>;

  const effectiveWorkStatus = sheet.effectiveWorkStatus ?? sheet.workStatus;
  const showIndicators = sheet.workStatus === "WORKED" || effectiveWorkStatus === "WORKED";
  const approved = sheet.status === "APPROVED";

  return <div className="dialog-body form-stack">
    <dl className="definition-list">
      <div><dt>Status lembar</dt><dd><StatusBadge status={sheet.status} /></dd></div>
      <div><dt>Status diajukan</dt><dd><StatusBadge status={sheet.workStatus} /></dd></div>
      {sheet.managerWorkStatus ? <div><dt>Status efektif</dt><dd><StatusBadge status={effectiveWorkStatus} /></dd></div> : null}
      <div><dt>Dikirim</dt><dd>{sheet.submittedAt ? formatDateTime(sheet.submittedAt) : "Belum dikirim"}</dd></div>
      <div><dt>Direview Manager</dt><dd>{sheet.managerReviewedAt ? formatDateTime(sheet.managerReviewedAt) : "Belum direview"}</dd></div>
      <div><dt>Catatan penilai</dt><dd>{sheet.note || "Tidak ada catatan"}</dd></div>
      <div><dt>Catatan Manager</dt><dd>{sheet.managerReason || "Tidak ada catatan"}</dd></div>
    </dl>
    {showIndicators ? <div className="indicator-list">{items.map((item, index) => {
      const value = sheet.values.find((entry) => entry.monthlyKpiItemId === item.id);
      return <div className="indicator-row" key={item.id}>
        <div className="indicator-copy">
          <span className="indicator-position">Indikator {index + 1} dari {items.length}</span>
          <h3>{item.nameSnapshot}</h3>
          <p>{item.descriptionSnapshot || "Nilai aktual untuk indikator ini."}</p>
          <div className="indicator-target"><span>Target</span><strong>{formatNumber(item.targetSnapshot)} {item.unitSnapshot}</strong></div>
        </div>
        <div className="indicator-entry">
          {approved ? <>
            <div className="indicator-original"><span>Nilai awal</span><strong>{displayValue(value?.enteredValue, item.unitSnapshot)}</strong></div>
            <div className="indicator-result"><span>{value?.managerValue === null || value?.managerValue === undefined ? "Nilai efektif" : "Nilai efektif (koreksi)"}</span><strong>{displayValue(value?.effectiveValue, item.unitSnapshot)}</strong></div>
          </> : <div className="indicator-result"><span>Nilai diajukan</span><strong>{displayValue(value?.enteredValue, item.unitSnapshot)}</strong></div>}
        </div>
      </div>;
    })}</div> : <p className="help">Hari {workStatusLabel(effectiveWorkStatus)} tidak memiliki nilai indikator.</p>}
    {sheet.evidence.length ? <section className="evidence-section"><h3 className="evidence-heading">Bukti pendukung</h3><ul className="evidence-list">{sheet.evidence.map((item) => <li className="evidence-item" key={item.id}><a href={`/api/evidence/${item.id}`}>{item.fileName}</a><span>{Math.ceil(Number(item.fileSize) / 1024)} KB</span></li>)}</ul></section> : null}
  </div>;
}

function displayValue(value: DecimalValue | null | undefined, unit: string) {
  return value === null || value === undefined ? "Belum tersedia" : `${formatNumber(value)} ${unit}`;
}

function workStatusLabel(status: WorkStatus | null) {
  return status ? ({ WORKED: "Bekerja", OFF: "Libur", PERMIT: "Izin", SICK: "Sakit" } as Record<WorkStatus, string>)[status] : "belum dipilih";
}

function formatDateTime(value: Date) {
  return formatDate(value, { dateStyle: "medium", timeStyle: "short" });
}
