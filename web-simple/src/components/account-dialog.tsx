"use client";

import { useState, type InputHTMLAttributes, type ReactNode } from "react";
import type { FormAction } from "@/components/action-form";
import { CheckboxField, DatePickerField, SelectField, type SelectOption } from "@/components/ui/form-controls";
import { FormDialog } from "@/components/ui/form-dialog";
import { WizardActionForm } from "@/components/wizard-action-form";

type Role = "ADMIN" | "MANAGER" | "SUPERVISOR" | "EMPLOYEE";
type Account = {
  id: string;
  name: string;
  email: string;
  role: Role;
  isActive: boolean;
  employee?: {
    employeeNumber: string;
    branchId: string;
    positionId: string;
    managerId: string | null;
    supervisorId: string | null;
    joinedAt: string;
    endedAt: string;
  } | null;
};

const roles: SelectOption[] = [
  { value: "EMPLOYEE", label: "Pegawai" },
  { value: "SUPERVISOR", label: "Supervisor" },
  { value: "MANAGER", label: "Manager" },
  { value: "ADMIN", label: "Super Admin" },
];

export function AccountDialog({ trigger, action, branches, positions, managers, supervisors, account, closeHref }: {
  trigger?: ReactNode;
  action: FormAction;
  branches: SelectOption[];
  positions: SelectOption[];
  managers: SelectOption[];
  supervisors: SelectOption[];
  account?: Account;
  closeHref?: string;
}) {
  const editing = Boolean(account);
  const [role, setRole] = useState<Role>(account?.role ?? "EMPLOYEE");
  const employee = account?.employee;
  const accountFields = <div className="form-grid">
    {account ? <input type="hidden" name="userId" value={account.id} /> : null}
    <TextField label="Nama" name="name" defaultValue={account?.name} required />
    <TextField label="Email" name="email" type="email" defaultValue={account?.email} required />
    <TextField label={editing ? "Kata sandi baru (opsional)" : "Kata sandi awal"} name="password" type="password" minLength={10} maxLength={128} required={!editing} />
    {!editing ? <SelectField label="Role" name="role" options={roles} value={role} onValueChange={(value) => setRole(value as Role)} required /> : null}
    {editing ? <CheckboxField className="form-grid-wide" name="isActive" defaultChecked={account?.isActive} label="Akun aktif" description="Akun nonaktif tidak dapat masuk dan semua sesinya dihentikan." /> : null}
  </div>;

  const organizationFields = role === "ADMIN" ? null : <div className="form-grid">
    <TextField label="Nomor pegawai" name="employeeNumber" defaultValue={employee?.employeeNumber} required />
    <SelectField label="Cabang" name="branchId" options={branches} defaultValue={employee?.branchId} required />
    <SelectField label="Jabatan" name="positionId" options={positions} defaultValue={employee?.positionId} required />
    <DatePickerField label="Tanggal masuk" name="joinedAt" defaultValue={employee?.joinedAt} required />
    {role !== "MANAGER" ? <SelectField label="Manager" name="managerId" options={managers} defaultValue={employee?.managerId ?? ""} required /> : null}
    {role === "EMPLOYEE" ? <SelectField label="Supervisor" name="supervisorId" options={supervisors} defaultValue={employee?.supervisorId ?? ""} required /> : null}
    {editing ? <DatePickerField label="Tanggal selesai (opsional)" name="endedAt" defaultValue={employee?.endedAt} min={employee?.joinedAt} /> : null}
  </div>;

  const steps = role === "ADMIN" ? [{ title: "Akun", content: accountFields }] : [
    { title: "Akun", description: editing ? `Role: ${roleLabel(role)}` : "Identitas dan akses masuk pengguna.", content: accountFields },
    { title: "Organisasi", description: "Penempatan ini menjadi sumber snapshot saat periode berikutnya dibuka.", content: organizationFields },
  ];

  return (
    <FormDialog trigger={trigger} title={editing ? `Edit ${account!.name}` : "Buat akun"} description={editing ? "Perbarui akses dan penempatan dalam satu penyimpanan." : "Lengkapi akun, lalu lanjutkan ke data organisasi."} size="large" defaultOpen={editing && !trigger} closeHref={closeHref}>
      <WizardActionForm action={action} steps={steps} submitLabel={editing ? "Simpan perubahan" : "Buat akun"} pendingText={editing ? "Menyimpan..." : "Membuat akun..."} />
    </FormDialog>
  );
}

function TextField({ label, name, ...props }: { label: string; name: string } & InputHTMLAttributes<HTMLInputElement>) {
  return <label className="field"><span className="field-label">{label}{props.required ? <span className="required-mark" aria-hidden="true"> *</span> : null}</span><input className="control" name={name} {...props} /></label>;
}

function roleLabel(role: Role) {
  return ({ ADMIN: "Super Admin", MANAGER: "Manager", SUPERVISOR: "Supervisor", EMPLOYEE: "Pegawai" } as const)[role];
}
