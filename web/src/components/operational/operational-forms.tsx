"use client";

import { CalendarCheckIcon, ClipboardTextIcon, PackageIcon, PlusIcon, SmileyIcon, SpinnerGapIcon, WarningCircleIcon } from "@phosphor-icons/react";
import { useActionState } from "react";
import {
  createComplaint,
  createStockOpname,
  completeStockOpname,
  restockSparepart,
  saveAttendance,
  saveCoaching,
  saveStockOpnameCounts,
  saveWorkLog,
  type OperationalActionState,
} from "@/app/(workspace)/app/operasional/actions";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

const initialState: OperationalActionState = {};
const selectClass = "flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:border-primary focus-visible:ring-2 focus-visible:ring-ring/35";

function Feedback({ state }: { state: OperationalActionState }) {
  if (state.error) return <p role="alert" className="mt-3 rounded-lg bg-destructive/10 px-3 py-2 text-xs text-destructive">{state.error}</p>;
  if (state.success) return <p role="status" className="mt-3 rounded-lg bg-accent px-3 py-2 text-xs text-primary">{state.success}</p>;
  return null;
}

function AttendanceRow({ date, row }: { date: string; row: { id: string; name: string; position: string; status: string; note: string } }) {
  const [state, action, pending] = useActionState(saveAttendance, initialState);
  return (
    <form action={action} className="grid gap-3 border-t py-4 first:border-t-0 md:grid-cols-[1fr_180px_1fr_auto] md:items-end">
      <input type="hidden" name="employeeId" value={row.id} />
      <input type="hidden" name="attendanceDate" value={date} />
      <div><p className="text-sm font-semibold">{row.name}</p><p className="text-xs text-muted-foreground">{row.position}</p></div>
      <div className="space-y-2"><Label htmlFor={`status-${row.id}`}>Status</Label><select id={`status-${row.id}`} name="status" required defaultValue={row.status} className={selectClass}><option value="" disabled>Pilih status</option><option value="present">Hadir</option><option value="late">Terlambat</option><option value="permission">Izin</option><option value="sick_leave">Sakit</option><option value="absent">Alpha</option></select></div>
      <div className="space-y-2"><Label htmlFor={`note-${row.id}`}>Catatan</Label><Input id={`note-${row.id}`} name="note" defaultValue={row.note} maxLength={255} placeholder="Wajib untuk izin, sakit, atau alpha" /></div>
      <Button type="submit" size="sm" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <CalendarCheckIcon />}Simpan</Button>
      <div className="md:col-start-2 md:col-end-5"><Feedback state={state} /></div>
    </form>
  );
}

export function AttendanceForm({ date, rows }: { date: string; rows: Array<{ id: string; name: string; position: string; status: string; note: string }> }) {
  if (!rows.length) return null;
  return (
    <Card>
      <CardHeader><CardTitle className="flex items-center gap-2 text-base"><CalendarCheckIcon className="text-primary" /> Absensi tim</CardTitle><p className="text-sm text-muted-foreground">Simpan per orang. KPI kehadiran dihitung otomatis.</p></CardHeader>
      <CardContent>{rows.map((row) => <AttendanceRow key={row.id} date={date} row={row} />)}</CardContent>
    </Card>
  );
}

export function WorkLogForm({ date, values }: { date: string; values?: { recordsInput: number; recordsCorrected: number; documentsEligible: number; documentsComplete: number; reconciliationsTotal: number; reconciliationsSuccess: number; notes: string } }) {
  const [state, action, pending] = useActionState(saveWorkLog, initialState);
  return (
    <Card>
      <CardHeader><CardTitle className="flex items-center gap-2 text-base"><ClipboardTextIcon className="text-primary" /> Catat pekerjaan administrasi</CardTitle><p className="text-sm text-muted-foreground">Masukkan fakta kerja. Persentase KPI dihitung server.</p></CardHeader>
      <CardContent>
        <form action={action}>
          <input type="hidden" name="workDate" value={date} />
          <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <div className="space-y-2"><Label htmlFor="recordsInput">Data diinput</Label><Input id="recordsInput" name="recordsInput" type="number" min={0} required defaultValue={values?.recordsInput ?? 0} /></div>
            <div className="space-y-2"><Label htmlFor="recordsCorrected">Data dikoreksi</Label><Input id="recordsCorrected" name="recordsCorrected" type="number" min={0} required defaultValue={values?.recordsCorrected ?? 0} /></div>
            <div className="space-y-2"><Label htmlFor="documentsEligible">Dokumen wajib</Label><Input id="documentsEligible" name="documentsEligible" type="number" min={0} required defaultValue={values?.documentsEligible ?? 0} /></div>
            <div className="space-y-2"><Label htmlFor="documentsComplete">Dokumen lengkap</Label><Input id="documentsComplete" name="documentsComplete" type="number" min={0} required defaultValue={values?.documentsComplete ?? 0} /></div>
            <div className="space-y-2"><Label htmlFor="reconciliationsTotal">Rekonsiliasi</Label><Input id="reconciliationsTotal" name="reconciliationsTotal" type="number" min={0} required defaultValue={values?.reconciliationsTotal ?? 0} /></div>
            <div className="space-y-2"><Label htmlFor="reconciliationsSuccess">Rekonsiliasi berhasil</Label><Input id="reconciliationsSuccess" name="reconciliationsSuccess" type="number" min={0} required defaultValue={values?.reconciliationsSuccess ?? 0} /></div>
            <div className="space-y-2 sm:col-span-2 lg:col-span-3"><Label htmlFor="workNotes">Catatan</Label><Textarea id="workNotes" name="notes" rows={3} maxLength={1000} defaultValue={values?.notes ?? ""} /></div>
          </div>
          <div className="mt-4 flex justify-end"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <ClipboardTextIcon />}{pending ? "Menyimpan..." : "Simpan log kerja"}</Button></div>
          <Feedback state={state} />
        </form>
      </CardContent>
    </Card>
  );
}

type Option = { id: string; label: string };

export function ComplaintForm({ date, employees, tickets }: { date: string; employees: Option[]; tickets: Option[] }) {
  const [state, action, pending] = useActionState(createComplaint, initialState);
  return (
    <Card>
      <CardHeader><CardTitle className="flex items-center gap-2 text-base"><WarningCircleIcon className="text-amber-600" /> Catat komplain</CardTitle><p className="text-sm text-muted-foreground">Hubungkan ke karyawan atau tiket agar sumber KPI dapat diaudit.</p></CardHeader>
      <CardContent>
        <form action={action}>
          <input type="hidden" name="complaintDate" value={date} />
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2"><Label htmlFor="complaintEmployee">Karyawan terkait</Label><select id="complaintEmployee" name="employeeId" defaultValue="" className={selectClass}><option value="">Tidak dipilih</option>{employees.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}</select></div>
            <div className="space-y-2"><Label htmlFor="complaintTicket">Tiket terkait</Label><select id="complaintTicket" name="serviceTicketId" defaultValue="" className={selectClass}><option value="">Tidak dipilih</option>{tickets.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}</select></div>
            <div className="space-y-2"><Label htmlFor="complaintChannel">Kanal</Label><select id="complaintChannel" name="channel" defaultValue="in_store" className={selectClass}><option value="in_store">Di toko</option><option value="phone">Telepon</option><option value="whatsapp">WhatsApp</option><option value="google_review">Google Review</option></select></div>
            <div className="space-y-2"><Label htmlFor="complaintSeverity">Tingkat</Label><select id="complaintSeverity" name="severity" defaultValue="medium" className={selectClass}><option value="low">Rendah</option><option value="medium">Sedang</option><option value="high">Tinggi</option><option value="critical">Kritis</option></select></div>
            <div className="space-y-2 sm:col-span-2"><Label htmlFor="complaintCategory">Kategori</Label><Input id="complaintCategory" name="category" maxLength={100} /></div>
            <div className="space-y-2 sm:col-span-2"><Label htmlFor="complaintDescription">Uraian</Label><Textarea id="complaintDescription" name="description" required minLength={5} maxLength={2000} rows={4} /></div>
          </div>
          <div className="mt-4 flex justify-end"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <PlusIcon />}{pending ? "Menyimpan..." : "Catat komplain"}</Button></div>
          <Feedback state={state} />
        </form>
      </CardContent>
    </Card>
  );
}

export function CoachingForm({ date, employees }: { date: string; employees: Option[] }) {
  const [state, action, pending] = useActionState(saveCoaching, initialState);
  if (!employees.length) return null;
  return (
    <Card>
      <CardHeader><CardTitle className="flex items-center gap-2 text-base"><SmileyIcon className="text-primary" /> Catat coaching</CardTitle><p className="text-sm text-muted-foreground">Pilih anggota tim dari snapshot periode aktif.</p></CardHeader>
      <CardContent>
        <form action={action}>
          <input type="hidden" name="coachingDate" value={date} />
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="space-y-2"><Label htmlFor="coachingEmployee">Karyawan</Label><select id="coachingEmployee" name="employeeId" required defaultValue="" className={selectClass}><option value="" disabled>Pilih karyawan</option>{employees.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}</select></div>
            <div className="space-y-2"><Label htmlFor="coachingTopic">Topik</Label><Input id="coachingTopic" name="topic" required minLength={3} maxLength={200} /></div>
            <div className="space-y-2"><Label htmlFor="targetMet">Target sebelumnya</Label><select id="targetMet" name="targetMet" defaultValue="" className={selectClass}><option value="">Belum dinilai</option><option value="true">Tercapai</option><option value="false">Belum tercapai</option></select></div>
            <div className="space-y-2"><Label htmlFor="followUpDate">Tindak lanjut</Label><Input id="followUpDate" name="followUpDate" type="date" min={date} /></div>
            <div className="space-y-2 sm:col-span-2"><Label htmlFor="coachingNotes">Catatan</Label><Textarea id="coachingNotes" name="notes" rows={4} maxLength={2000} /></div>
          </div>
          <div className="mt-4 flex justify-end"><Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <SmileyIcon />}{pending ? "Menyimpan..." : "Simpan coaching"}</Button></div>
          <Feedback state={state} />
        </form>
      </CardContent>
    </Card>
  );
}

export function StockForms({ branches, spareparts }: { branches: Option[]; spareparts: Option[] }) {
  const [restockState, restockAction, restockPending] = useActionState(restockSparepart, initialState);
  const [opnameState, opnameAction, opnamePending] = useActionState(createStockOpname, initialState);
  return <div className="grid gap-5 lg:grid-cols-2">
    <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><PackageIcon className="text-primary" /> Restok sparepart</CardTitle><p className="text-sm text-muted-foreground">Penambahan stok selalu dicatat sebagai mutasi ledger.</p></CardHeader><CardContent><form action={restockAction} className="space-y-4"><div className="space-y-2"><Label htmlFor="restockPart">Sparepart</Label><select id="restockPart" name="sparepartId" required defaultValue="" className={selectClass}><option value="" disabled>Pilih sparepart</option>{spareparts.map((part) => <option key={part.id} value={part.id}>{part.label}</option>)}</select></div><div className="space-y-2"><Label htmlFor="restockQuantity">Jumlah masuk</Label><Input id="restockQuantity" name="quantity" type="number" inputMode="numeric" min={1} max={1_000_000} required /></div><div className="space-y-2"><Label htmlFor="restockNote">Sumber / catatan</Label><Textarea id="restockNote" name="note" required minLength={3} maxLength={1000} rows={2} /></div><Button type="submit" disabled={restockPending}>{restockPending ? <SpinnerGapIcon className="animate-spin" /> : <PlusIcon />}{restockPending ? "Menyimpan..." : "Catat restok"}</Button><Feedback state={restockState} /></form></CardContent></Card>
    <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><ClipboardTextIcon className="text-primary" /> Mulai stock opname</CardTitle><p className="text-sm text-muted-foreground">Stok sistem disalin sebagai snapshot yang tidak berubah.</p></CardHeader><CardContent><form action={opnameAction} className="space-y-4"><div className="space-y-2"><Label htmlFor="opnameBranch">Cabang</Label><select id="opnameBranch" name="branchId" required defaultValue={branches.length === 1 ? branches[0].id : ""} className={selectClass}><option value="" disabled>Pilih cabang</option>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.label}</option>)}</select></div><div className="space-y-2"><Label htmlFor="opnameDeadline">Deadline (opsional)</Label><Input id="opnameDeadline" name="deadline" type="date" /></div><Button type="submit" disabled={opnamePending}>{opnamePending ? <SpinnerGapIcon className="animate-spin" /> : <PlusIcon />}{opnamePending ? "Membuat..." : "Buat snapshot opname"}</Button><Feedback state={opnameState} /></form></CardContent></Card>
  </div>;
}

type Opname = { id: string; code: string; status: string; branch: string; period: string; deadline: string | null; completedAt: string | null; items: Array<{ id: string; code: string; name: string; systemStock: number; physicalStock: number | null; difference: number }> };

function StockOpnameSession({ opname }: { opname: Opname }) {
  const [saveState, saveAction, savePending] = useActionState(saveStockOpnameCounts, initialState);
  const [completeState, completeAction, completePending] = useActionState(completeStockOpname, initialState);
  const completed = opname.status === "completed";
  return <Card><CardHeader><div className="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between"><div><CardTitle className="text-base">{opname.code}</CardTitle><p className="mt-1 text-xs text-muted-foreground">{opname.branch} · {opname.period}{opname.deadline ? ` · deadline ${opname.deadline}` : ""}</p></div><span className="text-xs font-semibold uppercase tracking-wide text-primary">{completed ? "Selesai" : "Draft"}</span></div></CardHeader><CardContent>
    <form action={saveAction}><input type="hidden" name="stockOpnameId" value={opname.id} /><div className="overflow-x-auto rounded-xl border"><table className="w-full min-w-[620px] text-left text-sm"><thead className="bg-muted/60"><tr><th className="px-3 py-2">Sparepart</th><th className="px-3 py-2">Stok sistem</th><th className="px-3 py-2">Stok fisik</th><th className="px-3 py-2">Selisih</th></tr></thead><tbody className="divide-y">{opname.items.map((item) => <tr key={item.id}><td className="px-3 py-2"><span className="font-medium">{item.name}</span><span className="block font-mono text-[11px] text-muted-foreground">{item.code}</span></td><td className="px-3 py-2">{item.systemStock}</td><td className="px-3 py-2">{completed ? item.physicalStock : <><Label htmlFor={`physical-${item.id}`} className="sr-only">Stok fisik {item.name}</Label><Input id={`physical-${item.id}`} name={`physicalStock:${item.id}`} type="number" inputMode="numeric" min={0} max={1_000_000_000} required defaultValue={item.physicalStock ?? ""} className="w-28" /></>}</td><td className="px-3 py-2">{completed ? item.difference : "—"}</td></tr>)}</tbody></table></div>{!completed ? <div className="mt-4"><Button type="submit" variant="outline" disabled={savePending}>{savePending ? <SpinnerGapIcon className="animate-spin" /> : null}{savePending ? "Menyimpan..." : "Simpan hitungan"}</Button></div> : null}<Feedback state={saveState} /></form>
    {!completed ? <form action={completeAction} className="mt-3"><input type="hidden" name="stockOpnameId" value={opname.id} /><Button type="submit" disabled={completePending}>{completePending ? <SpinnerGapIcon className="animate-spin" /> : null}{completePending ? "Menyelesaikan..." : "Selesaikan dan sesuaikan stok"}</Button><p className="mt-2 text-xs text-muted-foreground">Simpan hitungan terlebih dahulu. Penyelesaian bersifat final dan tercatat di audit.</p><Feedback state={completeState} /></form> : null}
  </CardContent></Card>;
}

export function StockOpnameList({ opnames }: { opnames: Opname[] }) {
  return <section className="space-y-4"><h2 className="text-base font-semibold">Sesi stock opname</h2>{opnames.length ? opnames.map((opname) => <StockOpnameSession key={opname.id} opname={opname} />) : <p className="rounded-xl border border-dashed p-5 text-sm text-muted-foreground">Belum ada sesi opname untuk periode aktif.</p>}</section>;
}
