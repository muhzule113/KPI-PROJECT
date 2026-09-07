import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ArrowLeft,
    BookOpen,
    CheckCircle2,
    ChevronRight,
    FileSpreadsheet,
    ListChecks,
    Loader2,
    Plus,
    Settings2,
    SlidersHorizontal,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useFeedback } from '@/components/feedback/ActionFeedback';

const taskDetails = {
    predicates: { title: 'Ubah predikat', description: 'Atur lima pilihan nilai dan rentangnya.', icon: SlidersHorizontal },
    templates: { title: 'Atur KPI jabatan', description: 'Sesuaikan indikator, target, bobot, dan rubrik.', icon: ListChecks },
    assignments: { title: 'Atur penilai', description: 'Tetapkan penilai staf dan Supervisor per cabang.', icon: Users },
    imports: { title: 'Atur format import', description: 'Cocokkan delapan header laporan kasir.', icon: FileSpreadsheet },
};

const inputClass = 'min-h-11';
const selectClass = 'min-h-11 w-full rounded-lg border border-input bg-background px-3 text-sm text-foreground outline-none focus-visible:border-ring focus-visible:ring-3 focus-visible:ring-ring/50';

export default function KpiAdministration(props) {
    const [helpOpen, setHelpOpen] = useState(false);

    return (
        <>
            <Head title="Pusat Administrasi KPI" />
            <div className="min-h-[calc(100dvh-76px)] bg-muted/15 px-4 py-6 sm:px-6 lg:px-8">
                <div className="mx-auto max-w-6xl space-y-6">
                    <header className="flex flex-wrap items-start justify-between gap-4">
                        <div>
                            {props.task && (
                                <Link href="/app/kpi-administration" className="mb-3 inline-flex min-h-11 items-center gap-2 text-sm font-medium text-muted-foreground hover:text-foreground">
                                    <ArrowLeft className="size-4" /> Kembali ke pusat administrasi
                                </Link>
                            )}
                            <h1 className="text-2xl font-semibold tracking-tight text-foreground sm:text-3xl">
                                {props.task ? taskDetails[props.task]?.title : 'Pusat Administrasi KPI'}
                            </h1>
                            <p className="mt-1.5 max-w-2xl text-sm leading-6 text-muted-foreground">
                                {props.task ? taskDetails[props.task]?.description : 'Pilih pekerjaan yang perlu diselesaikan. Data dan status di bawah berasal dari konfigurasi saat ini.'}
                            </p>
                        </div>
                        <Button type="button" variant="outline" className="min-h-11" onClick={() => setHelpOpen(true)}>
                            <BookOpen /> Cara pakai
                        </Button>
                    </header>

                    {!props.task && <AdministrationHome {...props} />}
                    {props.task === 'predicates' && props.access.catalog && <PredicateWizard {...props} />}
                    {props.task === 'templates' && props.access.catalog && <TemplateWizard {...props} />}
                    {props.task === 'assignments' && props.access.assignments && <AssignmentWizard {...props} />}
                    {props.task === 'imports' && props.access.imports && <ImportWizard {...props} />}
                </div>
            </div>
            {helpOpen && <HelpDialog onClose={() => setHelpOpen(false)} />}
        </>
    );
}

function AdministrationHome({ access, summary, advancedLinks }) {
    const tasks = [
        access.catalog && { key: 'predicates', status: summary.active_scheme ? `Skema aktif versi ${summary.active_scheme}` : 'Belum ada skema aktif' },
        access.catalog && { key: 'templates', status: summary.template_drafts ? `${summary.template_drafts} draft dapat dilanjutkan` : 'Tidak ada draft tertunda' },
        access.assignments && { key: 'assignments', status: summary.invalid_assignments ? `${summary.invalid_assignments} penugasan perlu diperbaiki` : 'Semua penugasan valid' },
        access.imports && { key: 'imports', status: summary.mapping_sources ? `${summary.mapping_sources} sumber kasir tersedia` : 'Belum ada sumber kasir' },
    ].filter(Boolean);

    return (
        <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_19rem]">
            <section aria-labelledby="job-list-title" className="overflow-hidden rounded-xl bg-card ring-1 ring-foreground/10">
                <div className="border-b border-border/70 px-5 py-4">
                    <h2 id="job-list-title" className="font-heading text-base font-medium">Pekerjaan konfigurasi</h2>
                    <p className="mt-1 text-sm text-muted-foreground">Mulai dari pekerjaan, bukan dari tabel teknis.</p>
                </div>
                <div className="divide-y divide-border/70">
                    {tasks.map(({ key, status }) => {
                        const detail = taskDetails[key];
                        const Icon = detail.icon;
                        return (
                            <Link key={key} href={`/app/kpi-administration?task=${key}`} className="group flex min-h-24 items-center gap-4 px-5 py-4 transition-colors hover:bg-muted/50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring">
                                <span className="flex size-11 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"><Icon className="size-5" /></span>
                                <span className="min-w-0 flex-1">
                                    <span className="block font-semibold text-foreground">{detail.title}</span>
                                    <span className="mt-1 block text-sm text-muted-foreground">{detail.description}</span>
                                    <span className="mt-1.5 block text-xs font-medium text-foreground/70">{status}</span>
                                </span>
                                <ChevronRight className="size-5 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 motion-reduce:transition-none" />
                            </Link>
                        );
                    })}
                </div>
            </section>

            <aside className="space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Perubahan aman</CardTitle>
                        <CardDescription>Wizard menyimpan perubahan sebagai draft. Snapshot periode READY, OPEN, dan histori tidak diubah.</CardDescription>
                    </CardHeader>
                </Card>
                <details className="rounded-xl bg-card ring-1 ring-foreground/10">
                    <summary className="flex min-h-12 cursor-pointer list-none items-center gap-2 px-4 font-medium focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring">
                        <Settings2 className="size-4" /> Mode Lanjutan
                    </summary>
                    <div className="border-t border-border/70 p-2">
                        {advancedLinks.map((link) => (
                            <Link key={link.href} href={link.href} className="flex min-h-11 items-center rounded-lg px-3 text-sm text-muted-foreground hover:bg-muted hover:text-foreground">
                                {link.label}
                            </Link>
                        ))}
                    </div>
                </details>
            </aside>
        </div>
    );
}

function WizardFrame({ step, instruction, children }) {
    return (
        <div className="space-y-4">
            <ol className="grid grid-cols-3 overflow-hidden rounded-xl bg-card text-xs ring-1 ring-foreground/10" aria-label="Tahap wizard">
                {['Pilih', 'Atur', 'Periksa'].map((label, index) => (
                    <li key={label} className={`flex min-h-12 items-center justify-center border-r border-border/70 px-2 last:border-r-0 ${step === index + 1 ? 'bg-primary text-primary-foreground' : step > index + 1 ? 'bg-primary/10 text-foreground' : 'text-muted-foreground'}`} aria-current={step === index + 1 ? 'step' : undefined}>
                        <span className="font-semibold">{index + 1}. {label}</span>
                    </li>
                ))}
            </ol>
            <div className="rounded-lg border-l-4 border-primary bg-primary/5 px-4 py-3 text-sm">
                <span className="font-semibold">Yang perlu Anda lakukan:</span> {instruction}
            </div>
            {children}
        </div>
    );
}

function PredicateWizard({ schemes, selectedScheme, templates, step }) {
    if (!selectedScheme) {
        return <PredicateStart schemes={schemes} />;
    }
    return <PredicateEditor scheme={selectedScheme} templates={templates} step={step} />;
}

function PredicateStart({ schemes }) {
    const active = schemes.find((scheme) => scheme.is_active);
    const drafts = schemes.filter((scheme) => !scheme.is_active && (!active || scheme.version > active.version));
    const form = useForm({ scheme_id: active?.id ?? '' });

    return (
        <WizardFrame step={1} instruction="Pilih draft yang masih dikerjakan, atau salin skema aktif.">
            <Card>
                <CardHeader><CardTitle>Pilih versi predikat</CardTitle><CardDescription>Skema aktif tidak diubah langsung.</CardDescription></CardHeader>
                <CardContent className="space-y-5">
                    {drafts.length > 0 && (
                        <div className="space-y-2">
                            <p className="text-sm font-medium">Draft tersimpan</p>
                            {drafts.map((draft) => (
                                <Link key={draft.id} href={`/app/kpi-administration?task=predicates&scheme=${draft.id}&step=2`} className="flex min-h-12 items-center justify-between rounded-lg border border-border px-4 text-sm hover:bg-muted">
                                    <span>{draft.name} versi {draft.version}</span><span className="font-semibold text-primary">Lanjutkan draft</span>
                                </Link>
                            ))}
                        </div>
                    )}
                    {active ? (
                        <form onSubmit={(event) => { event.preventDefault(); form.post('/app/kpi-administration/predicates/start'); }} className="flex flex-wrap items-end gap-3 border-t border-border/70 pt-5">
                            <div className="min-w-60 flex-1"><FieldLabel label="Skema aktif" htmlFor="active-rating-scheme" /><select id="active-rating-scheme" className={selectClass} value={form.data.scheme_id} onChange={(event) => form.setData('scheme_id', event.target.value)}><option value={active.id}>{active.name} versi {active.version}</option></select></div>
                            <Button className="min-h-11" disabled={form.processing}>{form.processing && <Loader2 className="animate-spin" />}Buat draft dari skema aktif</Button>
                        </form>
                    ) : <EmptyState text="Belum ada skema predikat aktif." action="Buat skema melalui Mode Lanjutan terlebih dahulu." />}
                </CardContent>
            </Card>
        </WizardFrame>
    );
}

function PredicateEditor({ scheme, templates, step }) {
    const form = useForm({ bands: scheme.bands, template_ids: templates.map((template) => template.id) });
    const navigationAllowed = useUnsavedGuard(form.isDirty);
    const { requestAction } = useFeedback();

    const setBand = (index, field, value) => form.setData('bands', form.data.bands.map((band, bandIndex) => bandIndex === index ? { ...band, [field]: value } : band));
    const submit = (event) => {
        event.preventDefault();
        navigationAllowed.current = true;
        form.put(`/app/kpi-administration/predicates/${scheme.id}`, { preserveScroll: true, onError: () => { navigationAllowed.current = false; } });
    };
    const activate = async () => {
        const result = await requestAction({ title: 'Aktifkan predikat', description: 'Template jabatan terpilih akan dibuatkan versi baru. Snapshot periode yang sudah ada tidak berubah.', confirmLabel: 'Aktifkan untuk periode berikutnya' });
        if (result.confirmed) router.post(`/app/kpi-administration/predicates/${scheme.id}/activate`, { template_ids: form.data.template_ids });
    };

    return (
        <WizardFrame step={step} instruction={step === 3 ? 'Periksa masalah dan template terdampak, lalu aktifkan.' : 'Lengkapi lima predikat dalam satu layar, lalu simpan.'}>
            <form onSubmit={submit} className="space-y-4">
                <Card>
                    <CardHeader><CardTitle>{scheme.name} versi {scheme.version}</CardTitle><CardDescription>Nilai pilihan adalah nilai yang dipakai saat Supervisor memilih predikat.</CardDescription></CardHeader>
                    <CardContent className="space-y-3">
                        {form.data.bands.map((band, index) => (
                            <fieldset key={band.id} className="grid gap-3 rounded-lg border border-border p-4 sm:grid-cols-2 lg:grid-cols-6">
                                <legend className="px-1 text-sm font-semibold">{band.code}</legend>
                                <LabeledInput label="Label" value={band.label} onChange={(value) => setBand(index, 'label', value)} />
                                <LabeledInput label="Nilai pilihan" type="number" min="0" max="100" step="0.01" value={band.manual_score} onChange={(value) => setBand(index, 'manual_score', value)} hint="Antara 0 dan 100." />
                                <LabeledInput label="Rentang awal" type="number" min="0" max="100" step="0.01" value={band.min_score} onChange={(value) => setBand(index, 'min_score', value)} />
                                <LabeledInput label="Rentang akhir" type="number" min="0" max="100" step="0.01" value={band.max_score} onChange={(value) => setBand(index, 'max_score', value)} />
                                <LabeledInput label="Warna" type="color" value={band.color} onChange={(value) => setBand(index, 'color', value)} />
                                <LabeledInput label="Urutan" type="number" min="1" max="5" value={band.sort_order} onChange={(value) => setBand(index, 'sort_order', value)} />
                            </fieldset>
                        ))}
                        <FormErrors errors={form.errors} />
                    </CardContent>
                </Card>

                {step === 3 && (
                    <Card>
                        <CardHeader><CardTitle>Dampak aktivasi</CardTitle><CardDescription>Pilih template staf yang memakai skema baru. Template Supervisor tidak disertakan.</CardDescription></CardHeader>
                        <CardContent className="space-y-4">
                            <IssueList issues={scheme.issues} />
                            <div className="grid gap-2 sm:grid-cols-2">
                                {templates.map((template) => (
                                    <label key={template.id} className="flex min-h-12 items-center gap-3 rounded-lg border border-border px-4 text-sm">
                                        <input type="checkbox" checked={form.data.template_ids.includes(template.id)} onChange={(event) => form.setData('template_ids', event.target.checked ? [...form.data.template_ids, template.id] : form.data.template_ids.filter((id) => id !== template.id))} />
                                        <span><span className="block font-medium">{template.position}</span><span className="text-xs text-muted-foreground">{template.draft_id ? 'Memiliki draft lain, aktivasi akan ditolak' : `Versi aktif ${template.active_version}`}</span></span>
                                    </label>
                                ))}
                            </div>
                            <p className="text-sm text-muted-foreground">Periode READY, OPEN, dan histori yang sudah memiliki snapshot tetap memakai versi lama.</p>
                        </CardContent>
                    </Card>
                )}

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    {step === 3 && <Button type="button" variant="outline" className="min-h-11" onClick={() => router.get(`/app/kpi-administration?task=predicates&scheme=${scheme.id}&step=2`)}>Kembali mengubah</Button>}
                    {step === 3 ? (
                        <Button type="button" className="min-h-11" disabled={scheme.issues.length > 0 || form.data.template_ids.length === 0} onClick={activate}>Aktifkan untuk periode berikutnya</Button>
                    ) : (
                        <Button className="min-h-11" disabled={form.processing}>{form.processing && <Loader2 className="animate-spin" />}Simpan dan lanjut</Button>
                    )}
                </div>
            </form>
        </WizardFrame>
    );
}

function TemplateWizard({ templates, selectedDraft, definitions, step }) {
    if (!selectedDraft) return <TemplateStart templates={templates} />;
    return <TemplateEditor draft={selectedDraft} definitions={definitions} step={step} />;
}

function TemplateStart({ templates }) {
    const [templateId, setTemplateId] = useState(String(templates[0]?.id ?? ''));
    const template = templates.find((item) => String(item.id) === templateId);
    const form = useForm({});

    return (
        <WizardFrame step={1} instruction="Pilih jabatan, lalu buat draft atau lanjutkan draft yang tersimpan.">
            <Card>
                <CardHeader><CardTitle>Pilih jabatan</CardTitle><CardDescription>Wizard hanya menampilkan template staf. Template Supervisor tersedia di Mode Lanjutan.</CardDescription></CardHeader>
                <CardContent className="space-y-5">
                    {templates.length ? <>
                        <div><FieldLabel label="Jabatan" htmlFor="template-position" /><select id="template-position" className={selectClass} value={templateId} onChange={(event) => setTemplateId(event.target.value)}>{templates.map((item) => <option key={item.id} value={item.id}>{item.position}, {item.name}</option>)}</select></div>
                        <div className="flex flex-wrap justify-end gap-2 border-t border-border/70 pt-5">
                            {template?.draft_id && <Button asChild variant="outline" className="min-h-11"><Link href={`/app/kpi-administration?task=templates&draft=${template.draft_id}&step=2`}>Lanjutkan draft versi {template.draft_version}</Link></Button>}
                            {template?.active_version_id && <Button className="min-h-11" disabled={form.processing} onClick={() => form.post(`/app/kpi-administration/templates/${template.active_version_id}/start`)}>Buat draft dari versi aktif</Button>}
                        </div>
                    </> : <EmptyState text="Belum ada template staf aktif." action="Buat template melalui Mode Lanjutan terlebih dahulu." />}
                </CardContent>
            </Card>
        </WizardFrame>
    );
}

function TemplateEditor({ draft, definitions, step }) {
    const form = useForm({ items: draft.items });
    const navigationAllowed = useUnsavedGuard(form.isDirty);
    const { requestAction } = useFeedback();
    const totalWeight = form.data.items.reduce((total, item) => total + Number(item.weight || 0), 0);
    const available = definitions.filter((definition) => !form.data.items.some((item) => Number(item.kpi_definition_id) === Number(definition.id)));
    const [definitionId, setDefinitionId] = useState(String(available[0]?.id ?? ''));

    const setItem = (index, patch) => form.setData('items', form.data.items.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item));
    const addItem = () => {
        const definition = definitions.find((item) => String(item.id) === definitionId);
        if (!definition) return;
        form.setData('items', [...form.data.items, {
            kpi_definition_id: definition.id, code: definition.code, name: definition.name, target_value: '', target_unit: definition.unit,
            weight: 0, sort_order: form.data.items.length + 1, formula_key: definition.default_formula,
            rubric: definition.default_formula === 'rubric' ? { name: definition.name, description: '', criteria: [] } : null,
        }]);
        setDefinitionId('');
    };
    const submit = (event) => {
        event.preventDefault(); navigationAllowed.current = true;
        form.put(`/app/kpi-administration/templates/${draft.id}`, { preserveScroll: true, onError: () => { navigationAllowed.current = false; } });
    };
    const activate = async () => {
        const result = await requestAction({ title: 'Aktifkan KPI jabatan', description: 'Versi aktif saat ini akan dipensiunkan untuk periode berikutnya. Snapshot periode yang sudah ada tetap sama.', confirmLabel: 'Aktifkan untuk periode berikutnya' });
        if (result.confirmed) router.post(`/app/kpi-administration/templates/${draft.id}/activate`);
    };

    return (
        <WizardFrame step={step} instruction={step === 3 ? 'Pastikan total bobot 100 persen dan tidak ada masalah.' : 'Atur indikator yang dibutuhkan. Formula dan sumber data tetap dipertahankan.'}>
            <form onSubmit={submit} className="space-y-4">
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-card px-5 py-4 ring-1 ring-foreground/10">
                    <div><p className="font-semibold">{draft.position}</p><p className="text-sm text-muted-foreground">{draft.template}, draft versi {draft.version_number}</p></div>
                    <Badge variant={Math.abs(totalWeight - 100) < 0.001 ? 'secondary' : 'destructive'}>Total bobot {totalWeight.toLocaleString('id-ID')}%</Badge>
                </div>

                {form.data.items.length ? form.data.items.map((item, index) => (
                    <Card key={item.id ?? `new-${item.kpi_definition_id}`}>
                        <CardHeader className="border-b border-border/70 sm:flex-row sm:items-start sm:justify-between">
                            <div><CardTitle>{index + 1}. {item.name}</CardTitle><CardDescription>{item.code} · {item.formula_key === 'rubric' ? 'KPI subjektif' : 'KPI terukur'}</CardDescription></div>
                            <Button type="button" variant="destructive" className="min-h-11" onClick={() => form.setData('items', form.data.items.filter((_, itemIndex) => itemIndex !== index))}><Trash2 /> Keluarkan indikator</Button>
                        </CardHeader>
                        <CardContent className="space-y-4 pt-1">
                            <div className="grid gap-3 sm:grid-cols-3">
                                <LabeledInput label={`Target (${item.target_unit})`} type="number" min="0" step="0.01" value={item.target_value} onChange={(value) => setItem(index, { target_value: value })} hint="Formula teknis tetap memakai pengaturan sebelumnya." />
                                <LabeledInput label="Bobot (%)" type="number" min="0" max="100" step="0.01" value={item.weight} onChange={(value) => setItem(index, { weight: value })} />
                                <LabeledInput label="Urutan" type="number" min="1" value={item.sort_order} onChange={(value) => setItem(index, { sort_order: value })} />
                            </div>
                            {item.formula_key === 'rubric' && <RubricEditor rubric={item.rubric ?? { name: item.name, description: '', criteria: [] }} onChange={(rubric) => setItem(index, { rubric })} />}
                        </CardContent>
                    </Card>
                )) : <EmptyState text="Draft belum memiliki indikator." action="Tambahkan indikator dari katalog di bawah." />}

                {step !== 3 && (
                    <Card>
                        <CardHeader><CardTitle>Tambah indikator</CardTitle><CardDescription>Pilih indikator yang sudah ada di katalog.</CardDescription></CardHeader>
                        <CardContent className="flex flex-col gap-3 sm:flex-row">
                            <select className={selectClass} value={definitionId} onChange={(event) => setDefinitionId(event.target.value)}><option value="">Pilih indikator</option>{available.map((definition) => <option key={definition.id} value={definition.id}>{definition.code}, {definition.name}</option>)}</select>
                            <Button type="button" variant="outline" className="min-h-11" disabled={!definitionId} onClick={addItem}><Plus /> Tambah indikator</Button>
                        </CardContent>
                    </Card>
                )}

                {step === 3 && <Card><CardHeader><CardTitle>Hasil pemeriksaan</CardTitle></CardHeader><CardContent><IssueList issues={draft.issues} /><p className="mt-4 text-sm text-muted-foreground">Periode READY, OPEN, dan histori yang sudah memiliki snapshot tidak berubah.</p></CardContent></Card>}
                <FormErrors errors={form.errors} />
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    {step === 3 && <Button type="button" variant="outline" className="min-h-11" onClick={() => router.get(`/app/kpi-administration?task=templates&draft=${draft.id}&step=2`)}>Kembali mengubah</Button>}
                    {step === 3 ? <Button type="button" className="min-h-11" disabled={draft.issues.length > 0} onClick={activate}>Aktifkan untuk periode berikutnya</Button> : <Button className="min-h-11" disabled={form.processing}>{form.processing && <Loader2 className="animate-spin" />}Simpan dan lanjut</Button>}
                </div>
            </form>
        </WizardFrame>
    );
}

function RubricEditor({ rubric, onChange }) {
    const descriptionId = useId();
    const setCriterion = (index, value) => onChange({ ...rubric, criteria: rubric.criteria.map((criterion, criterionIndex) => criterionIndex === index ? { ...criterion, criterion_text: value } : criterion) });
    return (
        <fieldset className="space-y-3 rounded-lg bg-muted/40 p-4">
            <legend className="px-1 text-sm font-semibold">Rubrik dan kriteria</legend>
            <LabeledInput label="Nama rubrik" value={rubric.name} onChange={(value) => onChange({ ...rubric, name: value })} />
            <div><FieldLabel label="Petunjuk penilaian" htmlFor={descriptionId} /><textarea id={descriptionId} className={`${selectClass} min-h-24 py-2`} value={rubric.description} onChange={(event) => onChange({ ...rubric, description: event.target.value })} /><p className="mt-1 text-xs text-muted-foreground">Poin teknis disimpan otomatis dan tidak perlu diatur.</p></div>
            {rubric.criteria.map((criterion, index) => (
                <div key={criterion.id ?? index} className="flex items-end gap-2">
                    <div className="flex-1"><LabeledInput label={`Kriteria ${index + 1}`} value={criterion.criterion_text} onChange={(value) => setCriterion(index, value)} /></div>
                    <Button type="button" variant="destructive" className="min-h-11" onClick={() => onChange({ ...rubric, criteria: rubric.criteria.filter((_, criterionIndex) => criterionIndex !== index) })}><Trash2 /> Hapus</Button>
                </div>
            ))}
            <Button type="button" variant="outline" className="min-h-11" onClick={() => onChange({ ...rubric, criteria: [...rubric.criteria, { criterion_text: '', sort_order: rubric.criteria.length + 1 }] })}><Plus /> Tambah kriteria</Button>
        </fieldset>
    );
}

function AssignmentWizard({ branches, employees, reviewers, step }) {
    const [branchId, setBranchId] = useState(String(branches[0]?.id ?? ''));
    const branchEmployees = employees.filter((employee) => employee.branch_id === branchId);
    const form = useForm({ assignments: branchEmployees.map((employee) => ({ employee_id: employee.id, supervisor_id: employee.supervisor_id })) });
    const navigationAllowed = useUnsavedGuard(form.isDirty);

    const changeBranch = (value) => {
        setBranchId(value);
        form.setData('assignments', employees.filter((employee) => employee.branch_id === value).map((employee) => ({ employee_id: employee.id, supervisor_id: employee.supervisor_id })));
    };
    const setReviewer = (employeeId, supervisorId) => form.setData('assignments', form.data.assignments.map((row) => row.employee_id === employeeId ? { ...row, supervisor_id: supervisorId } : row));
    const submit = (event) => { event.preventDefault(); navigationAllowed.current = true; form.put('/app/kpi-administration/assignments', { preserveScroll: true, onError: () => { navigationAllowed.current = false; } }); };

    if (step === 3) {
        return <WizardFrame step={3} instruction="Penugasan sudah tersimpan. Periksa kembali saat susunan tim berubah."><Card><CardContent className="flex flex-col items-center gap-3 py-10 text-center"><CheckCircle2 className="size-10 text-emerald-600" /><CardTitle>Penilai berhasil disimpan</CardTitle><CardDescription>Penugasan baru akan disalin ke snapshot saat periode berikutnya disiapkan.</CardDescription><Button asChild variant="outline" className="mt-2 min-h-11"><Link href="/app/kpi-administration?task=assignments">Atur cabang lain</Link></Button></CardContent></Card></WizardFrame>;
    }

    return (
        <WizardFrame step={branchId ? 2 : 1} instruction="Pilih cabang, lalu isi Supervisor untuk staf dan Manager untuk Supervisor.">
            <form onSubmit={submit} className="space-y-4">
                <Card><CardHeader><CardTitle>Cabang</CardTitle></CardHeader><CardContent>{branches.length ? <><FieldLabel label="Pilih cabang" htmlFor="assignment-branch" /><select id="assignment-branch" className={selectClass} value={branchId} onChange={(event) => changeBranch(event.target.value)}>{branches.map((branch) => <option key={branch.id} value={branch.id}>{branch.name}</option>)}</select></> : <EmptyState text="Belum ada cabang aktif." action="Aktifkan cabang melalui administrasi sistem." />}</CardContent></Card>
                {branchEmployees.length ? (
                    <Card>
                        <CardHeader><CardTitle>Daftar penilai</CardTitle><CardDescription>Baris yang belum valid tampil lebih dulu.</CardDescription></CardHeader>
                        <CardContent className="space-y-3">
                            {branchEmployees.map((employee) => {
                                const row = form.data.assignments.find((item) => item.employee_id === employee.id);
                                const requiredRole = employee.position_code === 'POS-SPV' ? 'owner_manager' : 'supervisor';
                                const options = reviewers.filter((reviewer) => reviewer.branch_id === branchId && reviewer.id !== employee.id && reviewer.roles.includes(requiredRole));
                                return (
                                    <div key={employee.id} className={`grid gap-3 rounded-lg border p-4 md:grid-cols-[minmax(0,1fr)_minmax(15rem,1fr)] md:items-center ${employee.valid ? 'border-border' : 'border-amber-400/70 bg-amber-50/50 dark:bg-amber-950/10'}`}>
                                        <div><p className="font-medium">{employee.name}</p><p className="text-xs text-muted-foreground">{employee.employee_number} · {employee.position}</p>{!employee.valid && <p className="mt-1 text-xs font-medium text-amber-700 dark:text-amber-300">Penilai perlu dilengkapi atau diperbaiki.</p>}</div>
                                        <div><FieldLabel label={employee.position_code === 'POS-SPV' ? 'Manager penilai' : 'Supervisor penilai'} htmlFor={`reviewer-${employee.id}`} /><select id={`reviewer-${employee.id}`} className={selectClass} value={row?.supervisor_id ?? ''} onChange={(event) => setReviewer(employee.id, event.target.value)}><option value="">Pilih penilai</option>{options.map((reviewer) => <option key={reviewer.id} value={reviewer.id}>{reviewer.name}</option>)}</select><FieldError message={form.errors[`assignments.${form.data.assignments.findIndex((item) => item.employee_id === employee.id)}.supervisor_id`]} /></div>
                                    </div>
                                );
                            })}
                        </CardContent>
                    </Card>
                ) : branchId && <EmptyState text="Tidak ada staf aktif di cabang ini." action="Pilih cabang lain atau periksa data karyawan." />}
                <FormErrors errors={form.errors} />
                {branchEmployees.length > 0 && <div className="flex justify-end"><Button className="min-h-11" disabled={form.processing}>{form.processing && <Loader2 className="animate-spin" />}Simpan semua penilai</Button></div>}
            </form>
        </WizardFrame>
    );
}

function ImportWizard({ mappingTemplates, selectedMapping, importColumns, step }) {
    if (!selectedMapping) return <ImportStart templates={mappingTemplates} />;
    return <ImportEditor version={selectedMapping} columns={importColumns} step={step} />;
}

function ImportStart({ templates }) {
    const [templateId, setTemplateId] = useState(String(templates[0]?.id ?? ''));
    const template = templates.find((item) => String(item.id) === templateId);
    const form = useForm({});
    return (
        <WizardFrame step={1} instruction="Pilih sumber kasir, lalu salin versi terakhir atau lanjutkan draft.">
            <Card><CardHeader><CardTitle>Pilih sumber kasir</CardTitle></CardHeader><CardContent className="space-y-5">{templates.length ? <><div><FieldLabel label="Sumber kasir" htmlFor="mapping-template" /><select id="mapping-template" className={selectClass} value={templateId} onChange={(event) => setTemplateId(event.target.value)}>{templates.map((item) => <option key={item.id} value={item.id}>{item.name}, {item.source_application}</option>)}</select></div><div className="flex flex-wrap justify-end gap-2 border-t border-border/70 pt-5">{template?.draft_id && <Button asChild variant="outline" className="min-h-11"><Link href={`/app/kpi-administration?task=imports&mapping=${template.draft_id}&step=2`}>Lanjutkan draft</Link></Button>}<Button className="min-h-11" disabled={form.processing || !template} onClick={() => form.post(`/app/kpi-administration/imports/${template.id}/start`)}>Salin versi terakhir</Button></div></> : <EmptyState text="Belum ada sumber kasir aktif." action="Buat sumber import melalui Mode Lanjutan." />}</CardContent></Card>
        </WizardFrame>
    );
}

function ImportEditor({ version, columns, step }) {
    const form = useForm({ mappings: version.mappings });
    const navigationAllowed = useUnsavedGuard(form.isDirty);
    const { requestAction } = useFeedback();
    const normalized = Object.values(form.data.mappings).map((header) => header.trim().toLowerCase().replace(/[^a-z0-9]/g, ''));
    const valid = normalized.length === 8 && normalized.every(Boolean) && new Set(normalized).size === normalized.length;
    const submit = (event) => { event.preventDefault(); navigationAllowed.current = true; form.put(`/app/kpi-administration/imports/${version.id}`, { preserveScroll: true, onError: () => { navigationAllowed.current = false; } }); };
    const activate = async () => {
        const result = await requestAction({ title: 'Aktifkan format import', description: 'Format ini akan dipakai untuk unggahan berikutnya. Versi yang pernah digunakan tetap tersimpan dan terkunci.', confirmLabel: 'Aktifkan format' });
        if (result.confirmed) router.post(`/app/kpi-administration/imports/${version.id}/activate`);
    };
    return (
        <WizardFrame step={step} instruction={step === 3 ? 'Periksa delapan header, lalu aktifkan format.' : 'Isi nama header persis seperti pada berkas laporan kasir.'}>
            <form onSubmit={submit} className="space-y-4">
                <Card><CardHeader><CardTitle>{version.template}, versi {version.version_number}</CardTitle><CardDescription>Sumber: {version.source_application}. Setiap header wajib berbeda.</CardDescription></CardHeader><CardContent className="grid gap-4 sm:grid-cols-2">{Object.entries(columns).map(([key, label]) => <LabeledInput key={key} label={label} value={form.data.mappings[key] ?? ''} onChange={(value) => form.setData('mappings', { ...form.data.mappings, [key]: value })} hint="Gunakan teks pada baris header file." />)}</CardContent></Card>
                {step === 3 && <Card><CardHeader><CardTitle>Hasil pemeriksaan</CardTitle></CardHeader><CardContent><IssueList issues={valid ? [] : ['Header tidak boleh kosong atau sama.']} /><p className="mt-4 text-sm text-muted-foreground">Versi yang sudah pernah dipakai pada import tidak diubah.</p></CardContent></Card>}
                <FormErrors errors={form.errors} />
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">{step === 3 && <Button type="button" variant="outline" className="min-h-11" onClick={() => router.get(`/app/kpi-administration?task=imports&mapping=${version.id}&step=2`)}>Kembali mengubah</Button>}{step === 3 ? <Button type="button" className="min-h-11" disabled={!valid || version.used} onClick={activate}>Aktifkan format import</Button> : <Button className="min-h-11" disabled={form.processing || !valid}>{form.processing && <Loader2 className="animate-spin" />}Simpan dan lanjut</Button>}</div>
            </form>
        </WizardFrame>
    );
}

function HelpDialog({ onClose }) {
    useEffect(() => {
        const close = (event) => event.key === 'Escape' && onClose();
        window.addEventListener('keydown', close);
        return () => window.removeEventListener('keydown', close);
    }, [onClose]);

    return (
        <div className="fixed inset-0 z-[90] flex items-center justify-center bg-slate-950/40 p-4" onMouseDown={(event) => event.target === event.currentTarget && onClose()}>
            <section role="dialog" aria-modal="true" aria-labelledby="help-title" className="w-full max-w-lg rounded-xl bg-card shadow-2xl ring-1 ring-foreground/10">
                <div className="flex items-start justify-between gap-4 border-b border-border/70 p-5"><div><h2 id="help-title" className="text-lg font-semibold">Cara memakai pusat administrasi</h2><p className="mt-1 text-sm text-muted-foreground">Setiap pekerjaan mengikuti pola yang sama.</p></div><Button type="button" variant="ghost" className="min-h-11" onClick={onClose} autoFocus><X /> Tutup</Button></div>
                <ol className="space-y-4 p-5 text-sm leading-6"><li><strong>1. Pilih</strong><br /><span className="text-muted-foreground">Pilih data aktif atau lanjutkan draft yang tersimpan.</span></li><li><strong>2. Atur</strong><br /><span className="text-muted-foreground">Isi hanya pengaturan rutin yang ditampilkan, lalu simpan.</span></li><li><strong>3. Periksa</strong><br /><span className="text-muted-foreground">Baca masalah dan dampaknya sebelum aktivasi.</span></li></ol>
                <p className="border-t border-border/70 px-5 py-4 text-xs text-muted-foreground">Tekan Escape untuk menutup. Pengaturan teknis lengkap tetap tersedia melalui Mode Lanjutan.</p>
            </section>
        </div>
    );
}

function useUnsavedGuard(dirty) {
    const navigationAllowed = useRef(false);
    useEffect(() => {
        const message = 'Perubahan form belum disimpan. Tetap keluar?';
        const unload = (event) => { if (dirty && !navigationAllowed.current) { event.preventDefault(); event.returnValue = message; } };
        const removeBefore = router.on('before', (event) => {
            if (dirty && !navigationAllowed.current && !window.confirm(message)) event.preventDefault();
        });
        window.addEventListener('beforeunload', unload);
        return () => { removeBefore(); window.removeEventListener('beforeunload', unload); };
    }, [dirty]);
    return navigationAllowed;
}

function LabeledInput({ label, hint, onChange, ...props }) {
    const id = useId();
    return <div><FieldLabel label={label} htmlFor={id} /><Input id={id} className={inputClass} {...props} onChange={(event) => onChange(event.target.value)} />{hint && <p className="mt-1 text-xs text-muted-foreground">{hint}</p>}</div>;
}

function FieldLabel({ label, htmlFor }) { return <label htmlFor={htmlFor} className="mb-1.5 block text-sm font-medium text-foreground">{label}</label>; }
function FieldError({ message }) { return message ? <p className="mt-1 text-xs font-medium text-destructive">{message}</p> : null; }

function FormErrors({ errors }) {
    const messages = [...new Set(Object.values(errors).filter(Boolean))];
    return messages.length ? <div role="alert" className="rounded-lg border border-destructive/30 bg-destructive/5 p-3 text-sm text-destructive"><p className="font-semibold">Periksa isian berikut:</p><ul className="mt-1 list-disc pl-5">{messages.map((message) => <li key={message}>{message}</li>)}</ul></div> : null;
}

function IssueList({ issues }) {
    return issues.length ? <div role="alert" className="rounded-lg border border-amber-400/60 bg-amber-50 p-4 text-sm text-amber-900 dark:bg-amber-950/20 dark:text-amber-200"><p className="font-semibold">Belum dapat diaktifkan:</p><ul className="mt-2 list-disc space-y-1 pl-5">{issues.map((issue) => <li key={issue}>{issue}</li>)}</ul></div> : <div className="flex items-center gap-2 rounded-lg border border-emerald-500/30 bg-emerald-50 p-4 text-sm font-medium text-emerald-800 dark:bg-emerald-950/20 dark:text-emerald-200"><CheckCircle2 className="size-5" /> Semua pemeriksaan terpenuhi.</div>;
}

function EmptyState({ text, action }) {
    return <div className="rounded-lg border border-dashed border-border p-6 text-center"><p className="font-medium">{text}</p><p className="mt-1 text-sm text-muted-foreground">{action}</p></div>;
}
