"use client";

import { FloppyDiskIcon, SpinnerGapIcon } from "@phosphor-icons/react";
import { useActionState } from "react";
import { saveAccount, saveBranch, saveEmployee, savePosition, type OrganizationActionState } from "@/app/(workspace)/app/pengaturan/organisasi/actions";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";

const initial: OrganizationActionState = {};
const field = "space-y-2";
const selectClass = "flex h-11 w-full rounded-[10px] border border-input bg-background px-3 text-sm outline-none focus-visible:ring-2 focus-visible:ring-ring";

function Feedback({ state }: { state: OrganizationActionState }) {
  if (state.error) return <p role="alert" className="text-sm text-destructive">{state.error}</p>;
  if (state.success) return <p role="status" className="text-sm text-primary">{state.success}</p>;
  return null;
}

function Submit({ pending }: { pending: boolean }) {
  return <Button type="submit" disabled={pending}>{pending ? <SpinnerGapIcon className="animate-spin" /> : <FloppyDiskIcon />}{pending ? "Menyimpan..." : "Simpan"}</Button>;
}

export function BranchForm({ value }: { value?: { id: string; code: string; name: string; address: string | null; phone: string | null; isActive: boolean } }) {
  const [state, action, pending] = useActionState(saveBranch, initial);
  const suffix = value?.id ?? "new";
  return <form action={action} className="space-y-4"><input type="hidden" name="id" value={value?.id ?? ""} /><div className="grid gap-4 sm:grid-cols-2"><div className={field}><Label htmlFor={`branch-code-${suffix}`}>Kode</Label><Input id={`branch-code-${suffix}`} name="code" required maxLength={20} defaultValue={value?.code} /></div><div className={field}><Label htmlFor={`branch-name-${suffix}`}>Nama cabang</Label><Input id={`branch-name-${suffix}`} name="name" required maxLength={100} defaultValue={value?.name} /></div><div className={field}><Label htmlFor={`branch-phone-${suffix}`}>Telepon</Label><Input id={`branch-phone-${suffix}`} name="phone" type="tel" maxLength={30} defaultValue={value?.phone ?? ""} /></div><div className={field}><Label htmlFor={`branch-active-${suffix}`}>Status</Label><select id={`branch-active-${suffix}`} name="isActive" className={selectClass} defaultValue={String(value?.isActive ?? true)}><option value="true">Aktif</option><option value="false">Nonaktif</option></select></div><div className={`${field} sm:col-span-2`}><Label htmlFor={`branch-address-${suffix}`}>Alamat</Label><Textarea id={`branch-address-${suffix}`} name="address" rows={2} maxLength={500} defaultValue={value?.address ?? ""} /></div></div><Feedback state={state} /><Submit pending={pending} /></form>;
}

export function PositionForm({ value }: { value?: { id: string; code: string; name: string; department: string; description: string | null; isActive: boolean } }) {
  const [state, action, pending] = useActionState(savePosition, initial);
  const suffix = value?.id ?? "new";
  return <form action={action} className="space-y-4"><input type="hidden" name="id" value={value?.id ?? ""} /><div className="grid gap-4 sm:grid-cols-2"><div className={field}><Label htmlFor={`position-code-${suffix}`}>Kode</Label><Input id={`position-code-${suffix}`} name="code" required maxLength={30} defaultValue={value?.code} /></div><div className={field}><Label htmlFor={`position-name-${suffix}`}>Nama jabatan</Label><Input id={`position-name-${suffix}`} name="name" required maxLength={100} defaultValue={value?.name} /></div><div className={field}><Label htmlFor={`position-dept-${suffix}`}>Departemen</Label><Input id={`position-dept-${suffix}`} name="department" required maxLength={100} defaultValue={value?.department ?? "Operasional"} /></div><div className={field}><Label htmlFor={`position-active-${suffix}`}>Status</Label><select id={`position-active-${suffix}`} name="isActive" className={selectClass} defaultValue={String(value?.isActive ?? true)}><option value="true">Aktif</option><option value="false">Nonaktif</option></select></div><div className={`${field} sm:col-span-2`}><Label htmlFor={`position-description-${suffix}`}>Deskripsi</Label><Textarea id={`position-description-${suffix}`} name="description" rows={2} maxLength={500} defaultValue={value?.description ?? ""} /></div></div><Feedback state={state} /><Submit pending={pending} /></form>;
}

type Option = { id: string; label: string };
type EmployeeValue = { id: string; employeeNumber: string; name: string; email: string; phone: string | null; positionId: string; branchId: string; supervisorId: string | null; joinedAt: string; status: string };

export function EmployeeForm({ positions, branches, reviewers, value, today }: { positions: Option[]; branches: Option[]; reviewers: Option[]; value?: EmployeeValue; today: string }) {
  const [state, action, pending] = useActionState(saveEmployee, initial);
  const suffix = value?.id ?? "new";
  return <form action={action} className="space-y-4"><input type="hidden" name="id" value={value?.id ?? ""} /><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"><div className={field}><Label htmlFor={`employee-number-${suffix}`}>Nomor karyawan</Label><Input id={`employee-number-${suffix}`} name="employeeNumber" required maxLength={40} defaultValue={value?.employeeNumber} /></div><div className={field}><Label htmlFor={`employee-name-${suffix}`}>Nama</Label><Input id={`employee-name-${suffix}`} name="name" required maxLength={120} defaultValue={value?.name} /></div><div className={field}><Label htmlFor={`employee-email-${suffix}`}>Email profil</Label><Input id={`employee-email-${suffix}`} name="email" type="email" required maxLength={190} defaultValue={value?.email} /></div><div className={field}><Label htmlFor={`employee-phone-${suffix}`}>Telepon</Label><Input id={`employee-phone-${suffix}`} name="phone" type="tel" maxLength={30} defaultValue={value?.phone ?? ""} /></div><div className={field}><Label htmlFor={`employee-position-${suffix}`}>Jabatan</Label><select id={`employee-position-${suffix}`} name="positionId" required className={selectClass} defaultValue={value?.positionId ?? ""}><option value="" disabled>Pilih jabatan</option>{positions.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}</select></div><div className={field}><Label htmlFor={`employee-branch-${suffix}`}>Cabang</Label><select id={`employee-branch-${suffix}`} name="branchId" required className={selectClass} defaultValue={value?.branchId ?? ""}><option value="" disabled>Pilih cabang</option>{branches.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}</select></div><div className={field}><Label htmlFor={`employee-supervisor-${suffix}`}>Penilai/atasan</Label><select id={`employee-supervisor-${suffix}`} name="supervisorId" className={selectClass} defaultValue={value?.supervisorId ?? ""}><option value="">Tanpa atasan (Owner/Manager)</option>{reviewers.filter((option) => option.id !== value?.id).map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}</select></div><div className={field}><Label htmlFor={`employee-joined-${suffix}`}>Tanggal bergabung</Label><Input id={`employee-joined-${suffix}`} name="joinedAt" type="date" required max={today} defaultValue={value?.joinedAt ?? today} /></div><div className={field}><Label htmlFor={`employee-effective-${suffix}`}>Efektif penempatan</Label><Input id={`employee-effective-${suffix}`} name="placementEffectiveFrom" type="date" required max={today} defaultValue={today} /></div><div className={field}><Label htmlFor={`employee-status-${suffix}`}>Status</Label><select id={`employee-status-${suffix}`} name="status" className={selectClass} defaultValue={value?.status ?? "ACTIVE"}><option value="ACTIVE">Aktif</option><option value="LEAVE">Cuti</option><option value="INACTIVE">Nonaktif</option><option value="RESIGNED">Resign</option></select></div></div><Feedback state={state} /><Submit pending={pending} /></form>;
}

type AccountValue = { id: string; name: string; email: string; role: string; employeeId: string | null; isActive: boolean };
const roles = [["super_admin", "Super Admin"], ["kpi_admin", "Admin KPI"], ["auditor", "Auditor"], ["owner_manager", "Owner / Manager"], ["supervisor", "Supervisor"], ["employee", "Karyawan"]] as const;

export function AccountForm({ employees, value }: { employees: Option[]; value?: AccountValue }) {
  const [state, action, pending] = useActionState(saveAccount, initial);
  const suffix = value?.id ?? "new";
  return <form action={action} className="space-y-4"><input type="hidden" name="id" value={value?.id ?? ""} /><div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3"><div className={field}><Label htmlFor={`account-name-${suffix}`}>Nama akun</Label><Input id={`account-name-${suffix}`} name="name" required maxLength={120} defaultValue={value?.name} /></div><div className={field}><Label htmlFor={`account-email-${suffix}`}>Email login</Label><Input id={`account-email-${suffix}`} name="email" type="email" required maxLength={190} defaultValue={value?.email} /></div><div className={field}><Label htmlFor={`account-role-${suffix}`}>Role</Label><select id={`account-role-${suffix}`} name="role" required className={selectClass} defaultValue={value?.role ?? "employee"}>{roles.map(([role, label]) => <option key={role} value={role}>{label}</option>)}</select></div><div className={field}><Label htmlFor={`account-employee-${suffix}`}>Profil karyawan</Label><select id={`account-employee-${suffix}`} name="employeeId" className={selectClass} defaultValue={value?.employeeId ?? ""}><option value="">Tanpa profil (role administratif)</option>{employees.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}</select></div><div className={field}><Label htmlFor={`account-password-${suffix}`}>{value ? "Kata sandi baru (opsional)" : "Kata sandi"}</Label><Input id={`account-password-${suffix}`} name="password" type="password" minLength={10} maxLength={200} required={!value} autoComplete="new-password" /></div><div className={field}><Label htmlFor={`account-active-${suffix}`}>Status akun</Label><select id={`account-active-${suffix}`} name="isActive" className={selectClass} defaultValue={String(value?.isActive ?? true)}><option value="true">Aktif</option><option value="false">Nonaktif</option></select></div></div><Feedback state={state} /><Submit pending={pending} /></form>;
}
