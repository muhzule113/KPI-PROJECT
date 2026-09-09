import { ArrowLeftIcon, BuildingsIcon, IdentificationCardIcon, PlusIcon, UserCircleGearIcon, UsersIcon } from "@phosphor-icons/react/dist/ssr";
import type { Metadata } from "next";
import Link from "next/link";
import { redirect } from "next/navigation";
import { AccountForm, BranchForm, EmployeeForm, PositionForm } from "@/components/settings/organization-forms";
import { PageHeading } from "@/components/page-heading";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { prisma } from "@/lib/prisma";
import { capabilitiesFor } from "@/modules/access/capabilities";
import { requireUser } from "@/modules/access/current-user";

export const metadata: Metadata = { title: "Organisasi dan akun" };
const date = (value: Date) => value.toISOString().slice(0, 10);

export default async function OrganizationSettingsPage() {
  const user = await requireUser();
  const caps = capabilitiesFor(user);
  if (!caps.has("organization.manage") && !caps.has("accounts.manage")) redirect("/app/pengaturan");
  const [branches, positions, employees, accounts] = await Promise.all([
    prisma.branch.findMany({ orderBy: { name: "asc" } }),
    prisma.position.findMany({ orderBy: { name: "asc" } }),
    prisma.employee.findMany({ orderBy: { name: "asc" }, include: { position: true, branch: true, supervisor: true, user: true } }),
    prisma.user.findMany({ orderBy: { name: "asc" }, include: { employee: true } }),
  ]);
  const activeBranches = branches.filter((row) => row.isActive).map((row) => ({ id: row.id, label: `${row.code} · ${row.name}` }));
  const activePositions = positions.filter((row) => row.isActive).map((row) => ({ id: row.id, label: `${row.code} · ${row.name}` }));
  const reviewers = employees.filter((row) => row.status === "ACTIVE" && row.user?.isActive && ["supervisor", "owner_manager"].includes(row.user.role)).map((row) => ({ id: row.id, label: `${row.name} · ${row.branch.name} · ${row.user!.role}` }));
  const employeeOptions = employees.map((row) => ({ id: row.id, label: `${row.employeeNumber} · ${row.name} · ${row.position.name}` }));
  const today = new Intl.DateTimeFormat("en-CA", { timeZone: "Asia/Makassar", year: "numeric", month: "2-digit", day: "2-digit" }).format(new Date());

  return <div className="space-y-7">
    <Button asChild variant="link"><Link href="/app/pengaturan"><ArrowLeftIcon /> Kembali ke pengaturan</Link></Button>
    <PageHeading eyebrow="Master data" title="Organisasi dan akun" description="Kelola cabang, jabatan, histori penempatan karyawan, dan akses login tanpa menghapus histori lama." />

    {caps.has("organization.manage") ? <>
      <div className="grid gap-5 xl:grid-cols-2">
        <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><PlusIcon className="text-primary" /> Cabang baru</CardTitle></CardHeader><CardContent><BranchForm /></CardContent></Card>
        <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><PlusIcon className="text-primary" /> Jabatan baru</CardTitle></CardHeader><CardContent><PositionForm /></CardContent></Card>
      </div>
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><UsersIcon className="text-primary" /> Karyawan baru</CardTitle></CardHeader><CardContent><EmployeeForm positions={activePositions} branches={activeBranches} reviewers={reviewers} today={today} /></CardContent></Card>

      <div className="grid gap-5 xl:grid-cols-2">
        <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><BuildingsIcon className="text-primary" /> Cabang ({branches.length})</CardTitle></CardHeader><CardContent className="space-y-2">{branches.map((branch) => <details key={branch.id} className="rounded-xl border p-4"><summary className="flex cursor-pointer list-none items-center justify-between gap-3"><span className="font-medium">{branch.code} · {branch.name}</span><Badge variant={branch.isActive ? "default" : "secondary"}>{branch.isActive ? "Aktif" : "Nonaktif"}</Badge></summary><div className="mt-4 border-t pt-4"><BranchForm value={branch} /></div></details>)}</CardContent></Card>
        <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><IdentificationCardIcon className="text-primary" /> Jabatan ({positions.length})</CardTitle></CardHeader><CardContent className="space-y-2">{positions.map((position) => <details key={position.id} className="rounded-xl border p-4"><summary className="flex cursor-pointer list-none items-center justify-between gap-3"><span className="font-medium">{position.code} · {position.name}</span><Badge variant={position.isActive ? "default" : "secondary"}>{position.isActive ? "Aktif" : "Nonaktif"}</Badge></summary><div className="mt-4 border-t pt-4"><PositionForm value={position} /></div></details>)}</CardContent></Card>
      </div>
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><UsersIcon className="text-primary" /> Karyawan ({employees.length})</CardTitle></CardHeader><CardContent className="space-y-2">{employees.map((employee) => <details key={employee.id} className="rounded-xl border p-4"><summary className="flex cursor-pointer list-none items-start justify-between gap-3"><span><span className="block font-medium">{employee.employeeNumber} · {employee.name}</span><span className="mt-1 block text-xs text-muted-foreground">{employee.position.name} · {employee.branch.name} · penilai {employee.supervisor?.name ?? "tidak ada"}</span></span><Badge variant={employee.status === "ACTIVE" ? "default" : "secondary"}>{employee.status}</Badge></summary><div className="mt-4 border-t pt-4"><EmployeeForm positions={activePositions} branches={activeBranches} reviewers={reviewers} today={today} value={{ id: employee.id, employeeNumber: employee.employeeNumber, name: employee.name, email: employee.email, phone: employee.phone, positionId: employee.positionId, branchId: employee.branchId, supervisorId: employee.supervisorId, joinedAt: date(employee.joinedAt), status: employee.status }} /></div></details>)}</CardContent></Card>
    </> : null}

    {caps.has("accounts.manage") ? <>
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><UserCircleGearIcon className="text-primary" /> Akun baru</CardTitle></CardHeader><CardContent><AccountForm employees={employeeOptions} /></CardContent></Card>
      <Card><CardHeader><CardTitle className="flex items-center gap-2 text-base"><UserCircleGearIcon className="text-primary" /> Akun login ({accounts.length})</CardTitle></CardHeader><CardContent className="space-y-2">{accounts.map((account) => <details key={account.id} className="rounded-xl border p-4"><summary className="flex cursor-pointer list-none items-start justify-between gap-3"><span><span className="block font-medium">{account.name}</span><span className="mt-1 block text-xs text-muted-foreground">{account.email} · {account.role} · {account.employee?.employeeNumber ?? "tanpa profil"}</span></span><Badge variant={account.isActive ? "default" : "secondary"}>{account.isActive ? "Aktif" : "Nonaktif"}</Badge></summary><div className="mt-4 border-t pt-4"><AccountForm employees={employeeOptions} value={{ id: account.id, name: account.name, email: account.email, role: account.role, employeeId: account.employee?.id ?? null, isActive: account.isActive }} /></div></details>)}</CardContent></Card>
    </> : null}
  </div>;
}
