import { BuildingsIcon, BriefcaseIcon, CalendarBlankIcon, PencilSimpleIcon, PlusIcon, SlidersHorizontalIcon, TrashIcon, UsersIcon } from "@phosphor-icons/react/dist/ssr";
import Link from "next/link";
import { notFound } from "next/navigation";
import type { InputHTMLAttributes } from "react";
import { ActionForm, SubmitButton } from "@/components/action-form";
import { AccountDialog } from "@/components/account-dialog";
import { ConfirmAction } from "@/components/confirm-action";
import { Pagination } from "@/components/pagination";
import { EmptyState, PageHeader } from "@/components/page-elements";
import { StatusBadge } from "@/components/status-badge";
import { Button } from "@/components/ui/button";
import { CheckboxField, SearchField, SelectField, type SelectOption } from "@/components/ui/form-controls";
import { DialogCancel, FormDialog } from "@/components/ui/form-dialog";
import { IndicatorFormFields } from "@/components/indicator-form-fields";
import {
  activateRatingDraftAction,
  activateTemplateAction,
  createAccountAction,
  createPeriodAction,
  discardRatingDraftAction,
  discardTemplateDraftAction,
  openPeriodAction,
  removeIndicatorAction,
  saveBranchAction,
  savePositionAction,
  saveRatingDraftAction,
  savePeriodTemplateSelectionsAction,
  saveTemplateNameAction,
  startRatingDraftAction,
  startTemplateDraftAction,
  updateAccountProfileAction,
} from "@/app/(workspace)/app/pengaturan/actions";
import type { KpiIndicator } from "@/generated/prisma/client";
import { prisma } from "@/lib/prisma";
import { formatDate, formatNumber } from "@/lib/utils";
import { getPageCount, PAGINATION_PAGE_SIZE, parsePage } from "@/lib/pagination";
import { matchesSearch, parseSearch } from "@/lib/search";
import { requireRole } from "@/modules/access/current-user";
import { aggregationLabel, formatKpiTarget, kindLabel } from "@/modules/kpi/target-label";

const sections = new Set(["cabang", "jabatan", "pengguna", "indikator", "predikat", "periode"]);

export default async function AdminSettingsPage({ params, searchParams }: {
  params: Promise<{ section: string }>;
  searchParams: Promise<{ accountId?: string; positionId?: string; page?: string; q?: string }>;
}) {
  await requireRole("ADMIN");
  const { section } = await params;
  if (!sections.has(section)) notFound();
  const query = await searchParams;
  const q = parseSearch(query.q);
  if (section === "cabang") return <BranchesSection q={q} />;
  if (section === "jabatan") return <PositionsSection q={q} />;
  if (section === "pengguna") return <UsersSection accountId={query.accountId} page={query.page} q={q} />;
  if (section === "indikator") return <IndicatorsSection positionId={query.positionId} q={q} />;
  if (section === "predikat") return <RatingsSection />;
  return <PeriodsSection q={q} />;
}

async function BranchesSection({ q }: { q: string }) {
  const where = q ? { OR: [
    { code: { contains: q, mode: "insensitive" as const } },
    { name: { contains: q, mode: "insensitive" as const } },
  ] } : {};
  const branches = await prisma.branch.findMany({ where, orderBy: { name: "asc" }, include: { _count: { select: { employees: true } } } });
  return <>
    <PageHeader eyebrow="Super Admin" title="Cabang" description="Kelola lokasi organisasi yang dipakai untuk penempatan dan cakupan penilaian." actions={<BranchDialog trigger={<Button><PlusIcon aria-hidden="true" />Tambah cabang</Button>} />} />
    <form method="get" className="toolbar"><SearchField label="Cari cabang" defaultValue={q} placeholder="Kode atau nama cabang" /><Button type="submit" variant="secondary">Cari</Button>{q ? <Button asChild variant="ghost"><Link href="/app/pengaturan/cabang">Hapus pencarian</Link></Button> : null}</form>
    {branches.length ? <section className="panel">
      <div className="panel-header"><div><h2>Daftar cabang</h2><p>{q ? `${branches.length} cabang cocok` : `${branches.length} cabang tersimpan`}</p></div></div>
      <div className="table-wrap"><table className="data-table"><thead><tr><th>Cabang</th><th>Pegawai</th><th>Status</th><th></th></tr></thead><tbody>
        {branches.map((branch) => <tr key={branch.id}>
          <td data-label="Cabang"><span className="cell-title">{branch.name}</span><span className="cell-subtitle">{branch.code}</span></td>
          <td data-label="Pegawai">{branch._count.employees}</td>
          <td data-label="Status"><StatusBadge status={branch.isActive ? "ACTIVE" : "INACTIVE"} /></td>
          <td data-label="Aksi"><BranchDialog branch={branch} trigger={<Button variant="secondary" size="small"><PencilSimpleIcon aria-hidden="true" />Edit</Button>} /></td>
        </tr>)}
      </tbody></table></div>
    </section> : <EmptyState icon={BuildingsIcon} title={q ? "Cabang tidak ditemukan" : "Belum ada cabang"} description={q ? `Tidak ada cabang yang cocok dengan "${q}".` : "Tambahkan cabang pertama untuk mulai menempatkan pegawai."} action={q ? <Button asChild variant="secondary"><Link href="/app/pengaturan/cabang">Hapus pencarian</Link></Button> : <BranchDialog trigger={<Button>Tambah cabang</Button>} />} />}
  </>;
}

function BranchDialog({ trigger, branch }: { trigger: React.ReactNode; branch?: { id: string; code: string; name: string; isActive: boolean } }) {
  return <FormDialog trigger={trigger} title={branch ? `Edit ${branch.name}` : "Tambah cabang"} description="Kode cabang harus unik dan mudah dikenali." size="small">
    <ActionForm action={saveBranchAction} className="dialog-body form-stack" closeOnSuccess resetOnSuccess>
      {branch ? <input type="hidden" name="id" value={branch.id} /> : null}
      <Field label="Kode" name="code" maxLength={120} defaultValue={branch?.code} required />
      <Field label="Nama cabang" name="name" maxLength={120} defaultValue={branch?.name} required />
      <CheckboxField name="isActive" defaultChecked={branch?.isActive ?? true} label="Cabang aktif" description="Cabang aktif dapat dipilih pada penempatan pegawai." />
      <footer className="dialog-actions"><DialogCancel /><SubmitButton>{branch ? "Simpan perubahan" : "Tambah cabang"}</SubmitButton></footer>
    </ActionForm>
  </FormDialog>;
}

async function PositionsSection({ q }: { q: string }) {
  const where = q ? { OR: [
    { code: { contains: q, mode: "insensitive" as const } },
    { name: { contains: q, mode: "insensitive" as const } },
  ] } : {};
  const positions = await prisma.position.findMany({
    where,
    orderBy: { name: "asc" },
    include: {
      _count: { select: { employees: true } },
      template: { include: { versions: { where: { status: { in: ["ACTIVE", "DRAFT"] } }, orderBy: { versionNumber: "desc" }, include: { indicators: { select: { weight: true } } } } } },
    },
  });
  return <><PageHeader eyebrow="Super Admin" title="Jabatan" description="Jabatan yang dinilai KPI otomatis memperoleh template draft untuk dilengkapi." actions={<PositionDialog trigger={<Button><PlusIcon aria-hidden="true" />Tambah jabatan</Button>} />} />
    <form method="get" className="toolbar"><SearchField label="Cari jabatan" defaultValue={q} placeholder="Kode atau nama jabatan" /><Button type="submit" variant="secondary">Cari</Button>{q ? <Button asChild variant="ghost"><Link href="/app/pengaturan/jabatan">Hapus pencarian</Link></Button> : null}</form>
    {positions.length ? <section className="panel"><div className="panel-header"><div><h2>Daftar jabatan</h2><p>{q ? `${positions.length} jabatan cocok` : `${positions.length} jabatan tersimpan`}</p></div></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Jabatan</th><th>Pegawai</th><th>Template KPI</th><th>Status</th><th></th></tr></thead><tbody>{positions.map((position) => {
      const active = position.template?.versions.find((version) => version.status === "ACTIVE");
      const draft = position.template?.versions.find((version) => version.status === "DRAFT");
      const version = active ?? draft;
      const total = version?.indicators.reduce((sum, indicator) => sum + Number(indicator.weight), 0) ?? 0;
      return <tr key={position.id}><td data-label="Jabatan"><span className="cell-title">{position.name}</span><span className="cell-subtitle">{position.code}</span></td><td data-label="Pegawai">{position._count.employees}</td><td data-label="Template KPI">{position.isKpiSubject ? <><StatusBadge status={active ? "ACTIVE" : draft ? "DRAFT" : null} /><span className="cell-subtitle">{version ? `v${version.versionNumber} · ${formatNumber(total)}%` : "Belum tersedia"}</span></> : "Tidak dinilai"}</td><td data-label="Status"><StatusBadge status={position.isActive ? "ACTIVE" : "INACTIVE"} /></td><td data-label="Aksi"><div className="table-actions">{position.isKpiSubject && position.template ? <Button asChild variant="secondary" size="small"><Link href={`/app/pengaturan/indikator?positionId=${position.id}`}>Atur indikator</Link></Button> : null}<PositionDialog position={position} trigger={<Button variant="secondary" size="small"><PencilSimpleIcon aria-hidden="true" />Edit</Button>} /></div></td></tr>;
    })}</tbody></table></div></section> : <EmptyState icon={BriefcaseIcon} title={q ? "Jabatan tidak ditemukan" : "Belum ada jabatan"} description={q ? `Tidak ada jabatan yang cocok dengan "${q}".` : "Tambahkan jabatan dan tentukan apakah masuk penilaian KPI."} action={q ? <Button asChild variant="secondary"><Link href="/app/pengaturan/jabatan">Hapus pencarian</Link></Button> : <PositionDialog trigger={<Button>Tambah jabatan</Button>} />} />}
    <div className="notice stack-top">Jabatan yang masih dipakai pegawai aktif tidak dapat dinonaktifkan atau dikeluarkan dari KPI.</div>
  </>;
}

function PositionDialog({ trigger, position }: { trigger: React.ReactNode; position?: { id: string; code: string; name: string; isKpiSubject: boolean; isActive: boolean } }) {
  return <FormDialog trigger={trigger} title={position ? `Edit ${position.name}` : "Tambah jabatan"} description="Jabatan KPI otomatis memperoleh template draft." size="small">
    <ActionForm action={savePositionAction} className="dialog-body form-stack" closeOnSuccess resetOnSuccess>
      {position ? <input type="hidden" name="id" value={position.id} /> : null}
      <Field label="Kode" name="code" defaultValue={position?.code} required />
      <Field label="Nama jabatan" name="name" defaultValue={position?.name} required />
      <CheckboxField name="isKpiSubject" defaultChecked={position?.isKpiSubject ?? true} label="Jabatan dinilai KPI" />
      <CheckboxField name="isActive" defaultChecked={position?.isActive ?? true} label="Jabatan aktif" />
      <footer className="dialog-actions"><DialogCancel /><SubmitButton>{position ? "Simpan perubahan" : "Tambah jabatan"}</SubmitButton></footer>
    </ActionForm>
  </FormDialog>;
}

async function UsersSection({ accountId, page: pageQuery, q }: { accountId?: string; page?: string; q: string }) {
  const rawPage = parsePage(pageQuery);
  const where = q ? { OR: [
    { name: { contains: q, mode: "insensitive" as const } },
    { username: { contains: q, mode: "insensitive" as const } },
    { email: { contains: q, mode: "insensitive" as const } },
    { employee: { is: { employeeNumber: { contains: q, mode: "insensitive" as const } } } },
  ] } : {};
  const [branches, positions, totalUsers, employees, selectedUser] = await Promise.all([
    prisma.branch.findMany({ orderBy: { name: "asc" } }),
    prisma.position.findMany({ orderBy: { name: "asc" } }),
    prisma.user.count({ where }),
    prisma.employee.findMany({ where: { user: { isActive: true } }, orderBy: { name: "asc" }, include: { user: true } }),
    accountId ? prisma.user.findUnique({ where: { id: accountId }, include: { employee: true } }) : Promise.resolve(null),
  ]);
  const totalPages = getPageCount(totalUsers);
  const page = Math.min(rawPage, totalPages);
  const users = await prisma.user.findMany({ where, orderBy: { name: "asc" }, skip: (page - 1) * PAGINATION_PAGE_SIZE, take: PAGINATION_PAGE_SIZE, include: { employee: true } });
  const firstUser = totalUsers ? (page - 1) * PAGINATION_PAGE_SIZE + 1 : 0;
  const lastUser = Math.min(page * PAGINATION_PAGE_SIZE, totalUsers);
  const managers = employees.filter((employee) => employee.user.role === "MANAGER");
  const supervisors = employees.filter((employee) => employee.user.role === "SUPERVISOR");
  const branchOptions = branches.filter((item) => item.isActive).map(toOption);
  const positionOptions = positions.filter((item) => item.isActive).map(toOption);
  const managerOptions = managers.map(toOption);
  const supervisorOptions = supervisors.map(toOption);
  const createDialog = <AccountDialog action={createAccountAction} branches={branchOptions} positions={positionOptions} managers={managerOptions} supervisors={supervisorOptions} trigger={<Button><PlusIcon aria-hidden="true" />Buat akun</Button>} />;
  return <><PageHeader eyebrow="Super Admin" title="Pengguna" description="Buat akun, atur status, serta kelola penempatan dan penilai untuk periode berikutnya." actions={createDialog} />
    <form method="get" className="toolbar"><SearchField label="Cari pengguna" defaultValue={q} placeholder="Nama, username, email, atau nomor" /><Button type="submit" variant="secondary">Cari</Button>{q ? <Button asChild variant="ghost"><Link href="/app/pengaturan/pengguna">Hapus pencarian</Link></Button> : null}</form>
    {users.length ? <section className="panel"><div className="panel-header"><div><h2>Daftar akun</h2><p>{q ? `Menampilkan ${firstUser}–${lastUser} dari ${totalUsers} akun yang cocok` : `Menampilkan ${firstUser}–${lastUser} dari ${totalUsers} akun terdaftar`}</p></div></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Nama</th><th>Role</th><th>Status</th><th>Nomor pegawai</th><th></th></tr></thead><tbody>{users.map((user) => <tr key={user.id}><td data-label="Nama"><span className="cell-title">{user.name}</span><span className="cell-subtitle">{user.username}</span></td><td data-label="Role">{roleLabel(user.role)}</td><td data-label="Status"><StatusBadge status={user.isActive ? "ACTIVE" : "INACTIVE"} /></td><td data-label="Nomor pegawai">{user.employee?.employeeNumber ?? "Tidak ada"}</td><td data-label="Aksi"><Button asChild variant="secondary" size="small"><Link href={`/app/pengaturan/pengguna?accountId=${user.id}&page=${page}${q ? `&q=${encodeURIComponent(q)}` : ""}`}><PencilSimpleIcon aria-hidden="true" />Edit</Link></Button></td></tr>)}</tbody></table></div><Pagination page={page} totalPages={totalPages} hrefForPage={(nextPage) => `/app/pengaturan/pengguna?page=${nextPage}${q ? `&q=${encodeURIComponent(q)}` : ""}`} /></section> : <EmptyState icon={UsersIcon} title={q ? "Pengguna tidak ditemukan" : "Belum ada akun"} description={q ? `Tidak ada pengguna yang cocok dengan "${q}".` : "Buat akun pertama agar tim dapat mulai memakai KPI Harian."} action={q ? <Button asChild variant="secondary"><Link href="/app/pengaturan/pengguna">Hapus pencarian</Link></Button> : createDialog} />}
    {selectedUser ? <AccountDialog
      action={updateAccountProfileAction}
      branches={branchOptions}
      positions={positionOptions}
      managers={managerOptions}
      supervisors={supervisorOptions}
      closeHref="/app/pengaturan/pengguna"
      account={{
        id: selectedUser.id,
        name: selectedUser.name,
        username: selectedUser.username,
        role: selectedUser.role,
        isActive: selectedUser.isActive,
        employee: selectedUser.employee ? {
          employeeNumber: selectedUser.employee.employeeNumber,
          branchId: selectedUser.employee.branchId,
          positionId: selectedUser.employee.positionId,
          managerId: selectedUser.employee.managerId,
          supervisorId: selectedUser.employee.supervisorId,
          joinedAt: iso(selectedUser.employee.joinedAt),
          endedAt: selectedUser.employee.endedAt ? iso(selectedUser.employee.endedAt) : "",
        } : null,
      }}
    /> : null}
  </>;
}

async function IndicatorsSection({ positionId, q }: { positionId?: string; q: string }) {
  const positions = await prisma.position.findMany({
    where: { isKpiSubject: true },
    orderBy: { name: "asc" },
    include: { template: { include: { versions: { where: { status: { in: ["ACTIVE", "DRAFT"] } }, orderBy: { versionNumber: "desc" }, include: { indicators: { orderBy: [{ sortOrder: "asc" }, { code: "asc" }], include: { categoryOptions: { orderBy: { sortOrder: "asc" } } } } } } } } },
  });
  const selected = positions.find((position) => position.id === positionId) ?? positions[0];
  if (!selected?.template) return <><PageHeader eyebrow="Super Admin" title="Indikator KPI" description="Atur indikator per jabatan melalui draft yang aman." /><div className="notice">Buat jabatan yang dinilai KPI terlebih dahulu.</div></>;
  const active = selected.template.versions.find((version) => version.status === "ACTIVE");
  const draft = selected.template.versions.find((version) => version.status === "DRAFT");
  const total = draft?.indicators.reduce((sum, indicator) => sum + Number(indicator.weight), 0) ?? 0;
  const activeIndicators = active?.indicators.filter((indicator) => matchesSearch(q, indicator.name, indicator.code, indicator.description)) ?? [];
  const draftIndicators = draft?.indicators.filter((indicator) => matchesSearch(q, indicator.name, indicator.code, indicator.description)) ?? [];
  const indicatorHref = (nextQ = q) => {
    const params = new URLSearchParams({ positionId: selected.id });
    if (nextQ) params.set("q", nextQ);
    return `/app/pengaturan/indikator?${params.toString()}`;
  };
  return <><PageHeader eyebrow="Super Admin" title="Indikator KPI" description="Versi aktif tidak dapat diubah; revisi selalu dikerjakan sebagai draft untuk periode berikutnya." />
    <form method="get" className="toolbar"><SelectField label="Jabatan" name="positionId" defaultValue={selected.id} options={positions.map(toOption)} required /><SearchField label="Cari indikator" defaultValue={q} placeholder="Nama, kode, atau deskripsi" /><Button type="submit" variant="secondary">Tampilkan</Button>{q ? <Button asChild variant="ghost"><Link href={indicatorHref("")}>Hapus pencarian</Link></Button> : null}</form>
    <section className="panel"><div className="panel-header"><div><h2>{selected.template.name}</h2><p>{selected.name} · aktif {active ? `v${active.versionNumber}` : "belum ada"} · draft {draft ? `v${draft.versionNumber}` : "tidak ada"}</p></div><div className="table-actions"><TemplateNameDialog templateId={selected.template.id} name={selected.template.name} />{active && !draft ? <ActionForm action={startTemplateDraftAction}><input type="hidden" name="templateId" value={selected.template.id} /><SubmitButton variant="secondary">Buat revisi</SubmitButton></ActionForm> : null}</div></div>
      {active ? <VersionSummary title={`Versi aktif v${active.versionNumber}`} status="ACTIVE" indicators={activeIndicators} emptyMessage={q ? `Tidak ada indikator yang cocok dengan "${q}".` : undefined} /> : <div className="notice">Belum ada versi aktif. Lengkapi dan aktifkan draft pertama.</div>}
    </section>
    {draft ? <section className="panel stack-top"><div className="panel-header"><div><h2>Draft v{draft.versionNumber}</h2><p>{q ? `${draftIndicators.length} cocok dari ${draft.indicators.length} indikator` : `${draft.indicators.length} indikator`} · total bobot {formatNumber(total)}%</p></div><div className="table-actions"><StatusBadge status={total === 100 ? "READY" : "DRAFT"} /><IndicatorDialog versionId={draft.id} trigger={<Button><PlusIcon aria-hidden="true" />Tambah indikator</Button>} /></div></div>
      {draftIndicators.length ? <div className="table-wrap"><table className="data-table"><thead><tr><th>Indikator</th><th>Perhitungan</th><th>Bobot</th><th></th></tr></thead><tbody>{draftIndicators.map((indicator) => <tr key={indicator.id}>
        <td data-label="Indikator"><span className="cell-title">{indicator.sortOrder}. {indicator.name}</span><span className="cell-subtitle">{indicator.code} · {kindLabel(indicator.kind)}{indicator.categoryOptions.length ? ` · ${indicator.categoryOptions.filter((option) => option.isActive).length} tingkat` : ""}</span></td>
        <td data-label="Perhitungan">{aggregationLabel(indicator.aggregation)}<span className="cell-subtitle">Target {formatKpiTarget(indicator)}</span></td>
        <td data-label="Bobot"><strong>{indicator.weight.toString()}%</strong></td>
        <td data-label="Aksi"><div className="table-actions"><IndicatorDialog versionId={draft.id} indicator={indicator} trigger={<Button variant="secondary" size="small"><PencilSimpleIcon aria-hidden="true" />Edit</Button>} /><ConfirmAction trigger={<Button variant="danger" size="small" aria-label={`Hapus ${indicator.name}`}><TrashIcon aria-hidden="true" /></Button>} action={removeIndicatorAction} title="Hapus indikator?" description={`${indicator.name} akan dihapus dari draft. Versi aktif tidak berubah.`} fields={{ indicatorId: indicator.id }} confirmLabel="Hapus indikator" variant="danger" /></div></td>
      </tr>)}</tbody></table></div> : <EmptyState icon={SlidersHorizontalIcon} title={q ? "Indikator tidak ditemukan" : "Draft belum memiliki indikator"} description={q ? `Tidak ada indikator yang cocok dengan "${q}".` : "Tambahkan indikator pertama melalui alur dua langkah."} action={q ? <Button asChild variant="secondary"><Link href={indicatorHref("")}>Hapus pencarian</Link></Button> : <IndicatorDialog versionId={draft.id} trigger={<Button>Tambah indikator</Button>} />} />}
      <div className="panel-body form-actions"><ConfirmAction trigger={<Button>Validasi & aktifkan</Button>} action={activateTemplateAction} title="Aktifkan versi ini?" description="Sistem akan memvalidasi total bobot dan menjadikannya template aktif untuk periode berikutnya." fields={{ versionId: draft.id }} confirmLabel="Aktifkan versi" />{active ? <ConfirmAction trigger={<Button variant="danger">Batalkan draft</Button>} action={discardTemplateDraftAction} title="Batalkan draft?" description="Semua perubahan pada draft ini akan dihapus. Versi aktif tetap aman." fields={{ versionId: draft.id }} confirmLabel="Batalkan draft" variant="danger" /> : null}</div>
    </section> : null}
  </>;
}

function TemplateNameDialog({ templateId, name }: { templateId: string; name: string }) {
  return <FormDialog trigger={<Button variant="secondary" size="small"><PencilSimpleIcon aria-hidden="true" />Ubah nama</Button>} title="Ubah nama template" description="Nama ini membantu membedakan konfigurasi KPI." size="small">
    <ActionForm action={saveTemplateNameAction} className="dialog-body form-stack" closeOnSuccess>
      <input type="hidden" name="templateId" value={templateId} />
      <Field label="Nama template" name="name" defaultValue={name} required />
      <footer className="dialog-actions"><DialogCancel /><SubmitButton>Simpan nama</SubmitButton></footer>
    </ActionForm>
  </FormDialog>;
}

type IndicatorRow = KpiIndicator & { categoryOptions: Array<{ label: string; threshold: { toString(): string } | null; sortOrder: number; isActive: boolean }> };

function IndicatorDialog({ versionId, indicator, trigger }: { versionId: string; indicator?: IndicatorRow; trigger: React.ReactNode }) {
  return <FormDialog trigger={trigger} title={indicator ? `Edit ${indicator.name}` : "Tambah indikator"} description="Isi identitas indikator, tentukan cara perhitungannya, lalu susun pilihan kategori bila diperlukan." size="large">
    <IndicatorFormFields
      versionId={versionId}
      indicator={indicator ? {
        id: indicator.id,
        code: indicator.code,
        name: indicator.name,
        description: indicator.description ?? "",
        kind: indicator.kind,
        unit: indicator.unit,
        aggregation: indicator.aggregation,
        direction: indicator.direction,
        target: indicator.target.toString(),
        failureLimit: indicator.failureLimit?.toString() ?? "",
        weight: indicator.weight.toString(),
        sortOrder: indicator.sortOrder,
        categoryOptions: indicator.categoryOptions.map((option) => ({ label: option.label, threshold: option.threshold?.toString() ?? "", sortOrder: option.sortOrder, isActive: option.isActive })),
      } : undefined}
    />
  </FormDialog>;
}

function VersionSummary({ title, status, indicators, emptyMessage }: { title: string; status: string; indicators: KpiIndicator[]; emptyMessage?: string }) {
  if (!indicators.length) return <div className="panel-body"><p className="help">{emptyMessage ?? "Belum ada indikator."}</p></div>;
  return <div className="table-wrap"><table className="data-table"><thead><tr><th>{title}</th><th>Jenis</th><th>Target</th><th>Bobot</th><th>Status</th></tr></thead><tbody>{indicators.map((indicator) => <tr key={indicator.id}><td data-label={title}><span className="cell-title">{indicator.name}</span><span className="cell-subtitle">{indicator.code}</span></td><td data-label="Jenis">{kindLabel(indicator.kind)}<span className="cell-subtitle">{aggregationLabel(indicator.aggregation)}</span></td><td data-label="Target">{formatKpiTarget(indicator)}</td><td data-label="Bobot">{indicator.weight.toString()}%</td><td data-label="Status"><StatusBadge status={status} /></td></tr>)}</tbody></table></div>;
}



async function RatingsSection() {
  const schemes = await prisma.kpiRatingScheme.findMany({ where: { status: { in: ["ACTIVE", "DRAFT"] } }, orderBy: { version: "desc" }, include: { bands: { orderBy: { sortOrder: "asc" } } } });
  const active = schemes.find((scheme) => scheme.status === "ACTIVE");
  const draft = schemes.find((scheme) => scheme.status === "DRAFT");
  return <><PageHeader eyebrow="Super Admin" title="Predikat Nilai" description="Satu skala global dipakai periode baru; periode yang sudah dibuka menyimpan snapshotnya sendiri." />
    <section className="panel"><div className="panel-header"><div><h2>Skala aktif</h2><p>{active ? `Versi ${active.version}` : "Belum tersedia"}</p></div>{active && !draft ? <ActionForm action={startRatingDraftAction}><SubmitButton variant="secondary">Buat revisi</SubmitButton></ActionForm> : null}</div>{active ? <RatingTable bands={active.bands} /> : <div className="notice">Skala aktif wajib tersedia sebelum periode dapat dibuka.</div>}</section>
    {draft ? <section className="panel stack-top"><div className="panel-header"><div><h2>Draft versi {draft.version}</h2><p>Label dan nilai minimum dapat diubah; kode internal tetap.</p></div><StatusBadge status="DRAFT" /></div><div className="panel-body"><ActionForm action={saveRatingDraftAction} className="form-stack"><input type="hidden" name="schemeId" value={draft.id} />{draft.bands.map((band) => <div className="form-grid" key={band.code}><Field label={`${band.code} · label`} name={`label.${band.code}`} defaultValue={band.label} required /><Field label="Nilai minimum" name={`minScore.${band.code}`} type="number" min={0} max={100} step="0.01" defaultValue={band.minScore.toString()} required /></div>)}<SubmitButton>Simpan draft predikat</SubmitButton></ActionForm><div className="form-actions stack-top"><ConfirmAction trigger={<Button>Aktifkan versi</Button>} action={activateRatingDraftAction} title="Aktifkan skala predikat?" description="Skala ini akan dipakai saat periode berikutnya dibuka." fields={{ schemeId: draft.id }} confirmLabel="Aktifkan versi" /><ConfirmAction trigger={<Button variant="danger">Batalkan draft</Button>} action={discardRatingDraftAction} title="Batalkan draft predikat?" description="Perubahan label dan batas nilai pada draft akan dihapus." fields={{ schemeId: draft.id }} confirmLabel="Batalkan draft" variant="danger" /></div></div></section> : null}
    <div className="notice stack-top">Skala wajib terdiri dari POOR, FAIR, GOOD, VERY_GOOD, dan STAR dengan batas meningkat dari 0 sampai 100.</div>
  </>;
}

function RatingTable({ bands }: { bands: Array<{ id: string; code: string; label: string; minScore: { toString(): string } }> }) {
  return <div className="table-wrap"><table className="data-table"><thead><tr><th>Kode</th><th>Predikat</th><th>Nilai minimum</th></tr></thead><tbody>{bands.map((band) => <tr key={band.id}><td data-label="Kode">{band.code}</td><td data-label="Predikat"><strong>{band.label}</strong></td><td data-label="Nilai minimum">{band.minScore.toString()}</td></tr>)}</tbody></table></div>;
}

async function PeriodsSection({ q }: { q: string }) {
  const [periods, positions, activeScheme] = await Promise.all([
    prisma.kpiPeriod.findMany({
      where: q ? { name: { contains: q, mode: "insensitive" as const } } : {},
      orderBy: [{ year: "desc" }, { month: "desc" }],
      include: {
        templateSelections: { orderBy: { position: { name: "asc" } }, include: { position: true, templateVersion: true } },
        monthlyKpis: { select: { positionIdSnapshot: true } },
      },
    }),
    prisma.position.findMany({
      where: { isKpiSubject: true },
      orderBy: { name: "asc" },
      include: {
        employees: { where: { status: "ACTIVE" }, select: { id: true } },
        template: {
          include: {
            versions: {
              where: { status: { in: ["ACTIVE", "RETIRED"] } },
              orderBy: { versionNumber: "desc" },
              include: { _count: { select: { indicators: true } } },
            },
          },
        },
      },
    }),
    prisma.kpiRatingScheme.findFirst({ where: { status: "ACTIVE" }, select: { id: true } }),
  ]);
  const evaluatedPositions = positions.filter((position) => position.isActive && position.employees.length > 0);
  const unready = evaluatedPositions.filter((position) => !position.template?.versions.length);
  const ready = Boolean(activeScheme) && unready.length === 0;
  const now = new Date();
  const createDialog = <PeriodDialog year={now.getFullYear()} month={now.getMonth() + 1} />;
  return <><PageHeader eyebrow="Super Admin" title="Periode" description="Atur versi template indikator per periode; periode berjalan dapat disesuaikan sebelum ada nilai penilaian." actions={createDialog} />
    <form method="get" className="toolbar"><SearchField label="Cari periode" defaultValue={q} placeholder="Nama periode" /><Button type="submit" variant="secondary">Cari</Button>{q ? <Button asChild variant="ghost"><Link href="/app/pengaturan/periode">Hapus pencarian</Link></Button> : null}</form>
    {!ready ? <div className="notice">Periode belum siap. {!activeScheme ? "Skala predikat aktif belum tersedia. " : ""}{unready.length ? `Template aktif belum tersedia untuk: ${unready.map((position) => position.name).join(", ")}.` : ""}</div> : <div className="notice">Konfigurasi master siap digunakan untuk periode baru.</div>}
    {periods.length ? <section className="panel stack-top"><div className="panel-header"><div><h2>Daftar periode</h2><p>{q ? `${periods.length} periode cocok` : `${periods.length} periode tersimpan`}</p></div></div><div className="table-wrap"><table className="data-table"><thead><tr><th>Periode</th><th>Rentang</th><th>Template per jabatan</th><th>Status</th><th></th></tr></thead><tbody>{periods.map((period) => {
      const templateSummary = period.templateSelections.length
        ? period.templateSelections.map((selection) => `${selection.position.code} v${selection.templateVersion.versionNumber}`).join(" · ")
        : period.status === "COMPLETED" ? "Terkunci pada snapshot lama" : "Belum dipilih";
      const periodPositions = period.status === "OPEN"
        ? positions.filter((position) => period.monthlyKpis.some((monthlyKpi) => monthlyKpi.positionIdSnapshot === position.id))
        : evaluatedPositions;
      const canConfigureTemplates = period.status === "DRAFT" || period.status === "OPEN";
      return <tr key={period.id}><td data-label="Periode"><strong>{period.name}</strong></td><td data-label="Rentang">{formatDate(period.startDate)} – {formatDate(period.endDate)}</td><td data-label="Template per jabatan"><span className="cell-subtitle">{templateSummary}</span></td><td data-label="Status"><StatusBadge status={period.status} /></td><td data-label="Aksi"><div className="table-actions">{canConfigureTemplates ? <PeriodTemplateDialog period={period} positions={periodPositions} /> : null}{period.status === "DRAFT" ? ready ? <ConfirmAction trigger={<Button size="small">Buka periode</Button>} action={openPeriodAction} title={`Buka periode ${period.name}?`} description="Snapshot pegawai, template, dan predikat akan dibuat. Konfigurasi periode tidak dapat diganti setelah dibuka." fields={{ periodId: period.id }} confirmLabel="Buka periode" /> : <Button disabled size="small">Belum siap</Button> : <Button asChild variant="secondary" size="small"><Link href={`/app/rekap?periodId=${period.id}`}>Lihat rekap</Link></Button>}</div></td></tr>;
    })}</tbody></table></div></section> : <div className="stack-top"><EmptyState icon={CalendarBlankIcon} title={q ? "Periode tidak ditemukan" : "Belum ada periode"} description={q ? `Tidak ada periode yang cocok dengan "${q}".` : "Buat periode bulanan pertama setelah konfigurasi master siap."} action={q ? <Button asChild variant="secondary"><Link href="/app/pengaturan/periode">Hapus pencarian</Link></Button> : createDialog} /></div>}
  </>;
}

type PeriodTemplatePosition = {
  id: string;
  code: string;
  name: string;
  isActive: boolean;
  template: { versions: Array<{ id: string; versionNumber: number; status: string; _count: { indicators: number } }> } | null;
};

type PeriodTemplateSelection = {
  positionId: string;
  templateVersionId: string;
  position: { code: string; name: string };
  templateVersion: { versionNumber: number; status: string };
};

function PeriodTemplateDialog({ period, positions }: {
  period: { id: string; name: string; status: string; templateSelections: PeriodTemplateSelection[] };
  positions: PeriodTemplatePosition[];
}) {
  const selectedByPosition = new Map(period.templateSelections.map((selection) => [selection.positionId, selection.templateVersionId]));
  const isOpen = period.status === "OPEN";
  return <FormDialog trigger={<Button variant="secondary" size="small">Atur versi</Button>} title={`Template ${period.name}`} description={isOpen ? "Periode berjalan dapat disesuaikan selama belum ada nilai penilaian tersimpan." : "Pilih versi indikator untuk setiap jabatan sebelum periode dibuka."} size="large">
    <ActionForm action={savePeriodTemplateSelectionsAction} className="dialog-body form-stack" closeOnSuccess>
      <input type="hidden" name="periodId" value={period.id} />
      {positions.length ? positions.map((position) => {
        const versions = position.template?.versions ?? [];
        const selected = selectedByPosition.get(position.id) ?? versions.find((version) => version.status === "ACTIVE")?.id ?? "";
        return <SelectField
          key={position.id}
          label={`${position.name} (${position.code})`}
          name={`templateVersion:${position.id}`}
          defaultValue={selected}
          options={versions.map((version) => ({ value: version.id, label: `v${version.versionNumber} · ${version.status === "ACTIVE" ? "Aktif" : "Arsip"} · ${version._count.indicators} indikator` }))}
          required
          help={versions.length ? "Versi DRAFT belum dapat dipilih." : "Belum ada versi template yang pernah diaktifkan."}
        />;
      }) : <div className="notice">Belum ada jabatan KPI dengan pegawai aktif.</div>}
      <footer className="dialog-actions"><DialogCancel /><SubmitButton>Simpan pilihan</SubmitButton></footer>
    </ActionForm>
  </FormDialog>;
}

function PeriodDialog({ year, month }: { year: number; month: number }) {
  return <FormDialog trigger={<Button><PlusIcon aria-hidden="true" />Buat periode</Button>} title="Buat periode bulanan" description="Periode baru disimpan sebagai draft sebelum dibuka." size="small">
    <ActionForm action={createPeriodAction} className="dialog-body form-stack" closeOnSuccess resetOnSuccess>
      <Field label="Tahun" name="year" type="number" min={2020} max={2100} defaultValue={year} required />
      <SelectField label="Bulan" name="month" defaultValue={String(month)} options={monthOptions} required />
      <footer className="dialog-actions"><DialogCancel /><SubmitButton>Buat periode</SubmitButton></footer>
    </ActionForm>
  </FormDialog>;
}

function Field({ label, name, type = "text", ...props }: { label: string; name: string; type?: string } & InputHTMLAttributes<HTMLInputElement>) {
  return <label className="field"><span className="field-label">{label}</span><input className="control" name={name} type={type} {...props} /></label>;
}

const iso = (date: Date) => date.toISOString().slice(0, 10);
const roleLabel = (role: string) => ({ ADMIN: "Super Admin", MANAGER: "Manager", SUPERVISOR: "Supervisor", EMPLOYEE: "Pegawai" } as Record<string,string>)[role] ?? role;
const toOption = (item: { id: string; name: string }): SelectOption => ({ value: item.id, label: item.name });
const monthOptions = ["Januari", "Februari", "Maret", "April", "Mei", "Juni", "Juli", "Agustus", "September", "Oktober", "November", "Desember"].map((label, index) => ({ value: String(index + 1), label }));
