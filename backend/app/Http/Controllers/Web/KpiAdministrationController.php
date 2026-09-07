<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportMappingTemplate;
use App\Models\ImportMappingVersion;
use App\Models\KpiDefinition;
use App\Models\KpiRatingScheme;
use App\Models\KpiTemplate;
use App\Models\KpiTemplateVersion;
use App\Modules\Configuration\KpiConfigurationService;
use App\Support\CapabilityMatrix;
use App\Support\KpiConfigurationResources;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

final class KpiAdministrationController extends Controller
{
    public function __construct(private readonly KpiConfigurationService $configuration) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $canCatalog = CapabilityMatrix::has($user, 'kpi.catalog.configure');
        $canAssignments = CapabilityMatrix::has($user, 'kpi.assignments.manage');
        $canImports = CapabilityMatrix::has($user, 'imports.configure');
        abort_unless($canCatalog || $canAssignments || $canImports, 403);

        $employees = collect();
        if ($canAssignments) {
            $employees = Employee::with(['position', 'branch', 'supervisor.user.roles', 'user.roles'])
                ->where('status', 'active')
                ->whereHas('position', fn ($query) => $query->whereNotIn('code', ['POS-OWN', 'POS-EXEC']))
                ->get()
                ->map(function (Employee $employee): array {
                    $valid = $employee->supervisor && $this->validReviewer($employee, $employee->supervisor);

                    return [
                        'id' => (string) $employee->id,
                        'name' => $employee->name,
                        'employee_number' => $employee->employee_number,
                        'position' => $employee->position?->name,
                        'position_code' => $employee->position?->code,
                        'branch_id' => (string) $employee->branch_id,
                        'branch' => $employee->branch?->name,
                        'supervisor_id' => $employee->supervisor_id ? (string) $employee->supervisor_id : '',
                        'valid' => $valid,
                    ];
                })
                ->sortBy([['valid', 'asc'], ['name', 'asc']])
                ->values();
        }

        $templates = collect();
        $selectedDraft = null;
        if ($canCatalog) {
            $templates = KpiTemplate::with(['position', 'activeVersion', 'versions' => fn ($query) => $query->where('status', 'draft')])
                ->where('is_active', true)
                ->whereHas('position', fn ($query) => $query->where('code', '!=', 'POS-SPV'))
                ->orderBy('name')
                ->get()
                ->map(fn (KpiTemplate $template): array => [
                    'id' => $template->id,
                    'name' => $template->name,
                    'position' => $template->position?->name,
                    'active_version_id' => $template->activeVersion?->id,
                    'active_version' => $template->activeVersion?->version_number,
                    'draft_id' => $template->versions->first()?->id,
                    'draft_version' => $template->versions->first()?->version_number,
                ]);

            if ($request->integer('draft')) {
                $draft = KpiTemplateVersion::with(['template.position', 'ratingScheme', 'items.definition', 'items.rubric.criteria'])
                    ->where('status', 'draft')->findOrFail($request->integer('draft'));
                abort_if($draft->template->position?->code === 'POS-SPV', 404);
                $selectedDraft = $this->templatePayload($draft);
            }
        }

        $mappingTemplates = collect();
        $selectedMapping = null;
        if ($canImports) {
            $mappingTemplates = ImportMappingTemplate::with('versions')->where('is_active', true)->orderBy('name')->get();
            $usedVersionIds = ImportBatch::whereIn('mapping_version_id', $mappingTemplates->flatMap(fn ($template) => $template->versions)->pluck('id')->filter())
                ->pluck('mapping_version_id')->all();
            $mappingTemplates = $mappingTemplates->map(fn (ImportMappingTemplate $template): array => [
                'id' => $template->id,
                'name' => $template->name,
                'source_application' => $template->source_application,
                'active_version' => $template->versions->firstWhere('is_active', true)?->version_number,
                'draft_id' => $template->versions->first(fn ($version) => ! $version->is_active && ! in_array($version->id, $usedVersionIds, true))?->id,
            ]);
            if ($request->integer('mapping')) {
                $version = ImportMappingVersion::with('template')->findOrFail($request->integer('mapping'));
                $selectedMapping = [
                    'id' => $version->id,
                    'template' => $version->template->name,
                    'source_application' => $version->template->source_application,
                    'version_number' => $version->version_number,
                    'mappings' => $version->mappings_json,
                    'is_active' => $version->is_active,
                    'used' => ImportBatch::where('mapping_version_id', $version->id)->exists(),
                ];
            }
        }

        $selectedScheme = null;
        if ($canCatalog && $request->integer('scheme')) {
            $scheme = KpiRatingScheme::with('bands')->findOrFail($request->integer('scheme'));
            $selectedScheme = [
                'id' => $scheme->id,
                'name' => $scheme->name,
                'version' => $scheme->version,
                'is_active' => $scheme->is_active,
                'bands' => $scheme->bands->map(fn ($band) => [
                    'id' => $band->id,
                    'code' => $band->code,
                    'label' => $band->label,
                    'manual_score' => (float) $band->manual_score,
                    'min_score' => (float) $band->min_score,
                    'max_score' => (float) $band->max_score,
                    'color' => $band->color,
                    'sort_order' => $band->sort_order,
                ])->values(),
                'issues' => $scheme->coverageIssues(),
            ];
        }

        return Inertia::render('Admin/KpiAdministration', [
            'access' => ['catalog' => $canCatalog, 'assignments' => $canAssignments, 'imports' => $canImports],
            'summary' => [
                'active_scheme' => $canCatalog ? KpiRatingScheme::where('is_active', true)->latest('version')->value('version') : null,
                'template_drafts' => $canCatalog ? KpiTemplateVersion::where('status', 'draft')->whereHas('template.position', fn ($query) => $query->where('code', '!=', 'POS-SPV'))->count() : null,
                'invalid_assignments' => $canAssignments ? $employees->where('valid', false)->count() : null,
                'mapping_sources' => $canImports ? $mappingTemplates->count() : null,
            ],
            'schemes' => $canCatalog ? KpiRatingScheme::orderByDesc('version')->get(['id', 'name', 'version', 'is_active']) : [],
            'selectedScheme' => $selectedScheme,
            'templates' => $templates,
            'selectedDraft' => $selectedDraft,
            'definitions' => $canCatalog ? KpiDefinition::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name', 'unit', 'default_formula']) : [],
            'branches' => $canAssignments ? Branch::where('is_active', true)->orderBy('name')->get(['id', 'name']) : [],
            'employees' => $employees,
            'reviewers' => $canAssignments ? $this->reviewers() : [],
            'importColumns' => KpiConfigurationResources::IMPORT_COLUMNS,
            'mappingTemplates' => $mappingTemplates,
            'selectedMapping' => $selectedMapping,
            'advancedLinks' => $this->advancedLinks($user),
            'task' => $request->string('task')->toString(),
            'step' => max(1, min(3, $request->integer('step', 1))),
        ]);
    }

    public function startPredicates(Request $request): RedirectResponse
    {
        $data = $request->validate(['scheme_id' => ['required', 'integer', 'exists:kpi_rating_schemes,id']]);
        $source = KpiRatingScheme::where('is_active', true)->findOrFail($data['scheme_id']);
        $draft = $this->configuration->startRatingSchemeDraft($source, $request->user());

        return $this->wizardRedirect('predicates', ['scheme' => $draft->id, 'step' => 2]);
    }

    public function updatePredicates(Request $request, KpiRatingScheme $scheme): RedirectResponse
    {
        $data = $request->validate([
            'bands' => ['required', 'array', 'size:5'],
            'bands.*.id' => ['required', 'integer', 'distinct'],
            'bands.*.label' => ['required', 'string', 'max:100'],
            'bands.*.manual_score' => ['required', 'numeric', 'between:0,100', 'distinct'],
            'bands.*.min_score' => ['required', 'numeric', 'between:0,100'],
            'bands.*.max_score' => ['required', 'numeric', 'between:0,100'],
            'bands.*.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'bands.*.sort_order' => ['required', 'integer', 'between:1,5', 'distinct'],
        ]);
        $this->configuration->saveRatingScheme($scheme, $data['bands'], $request->user());

        return $this->wizardRedirect('predicates', ['scheme' => $scheme->id, 'step' => 3])->with('success', 'Draft predikat tersimpan. Periksa dampaknya sebelum aktivasi.');
    }

    public function activatePredicates(Request $request, KpiRatingScheme $scheme): RedirectResponse
    {
        $data = $request->validate(['template_ids' => ['required', 'array', 'min:1'], 'template_ids.*' => ['required', 'integer', 'distinct', 'exists:kpi_templates,id']]);
        $message = $this->configuration->activateRatingSchemeForTemplates($scheme, $data['template_ids'], $request->user());

        return redirect()->route('app.kpi-administration.index')->with('success', $message);
    }

    public function startTemplate(Request $request, KpiTemplateVersion $version): RedirectResponse
    {
        $draft = $this->configuration->startTemplateDraft($version, $request->user());

        return $this->wizardRedirect('templates', ['draft' => $draft->id, 'step' => 2]);
    }

    public function updateTemplate(Request $request, KpiTemplateVersion $draft): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['present', 'array'],
            'items.*.id' => ['nullable', 'integer', 'distinct'],
            'items.*.kpi_definition_id' => ['required', 'integer', 'distinct', 'exists:kpi_definitions,id'],
            'items.*.target_value' => ['nullable', 'numeric', 'min:0'],
            'items.*.weight' => ['required', 'numeric', 'between:0,100'],
            'items.*.sort_order' => ['required', 'integer', 'min:1'],
            'items.*.rubric.name' => ['nullable', 'string', 'max:150'],
            'items.*.rubric.description' => ['nullable', 'string', 'max:1000'],
            'items.*.rubric.criteria' => ['nullable', 'array'],
            'items.*.rubric.criteria.*.id' => ['nullable', 'integer', 'distinct'],
            'items.*.rubric.criteria.*.criterion_text' => ['required', 'string', 'max:255'],
            'items.*.rubric.criteria.*.sort_order' => ['required', 'integer', 'min:1'],
        ]);
        $this->configuration->saveTemplateDraft($draft, $data['items'], $request->user());

        return $this->wizardRedirect('templates', ['draft' => $draft->id, 'step' => 3])->with('success', 'Draft template tersimpan.');
    }

    public function activateTemplate(Request $request, KpiTemplateVersion $draft): RedirectResponse
    {
        $message = $this->configuration->activate($draft, $request->user());

        return redirect()->route('app.kpi-administration.index')->with('success', $message);
    }

    public function updateAssignments(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'assignments' => ['required', 'array', 'min:1'],
            'assignments.*.employee_id' => ['required', 'string', 'distinct', 'exists:employees,id'],
            'assignments.*.supervisor_id' => ['required', 'string', 'exists:employees,id'],
        ]);
        $validator->after(function ($validator) use ($request): void {
            $rows = collect($request->input('assignments', []));
            $employees = Employee::with(['position', 'user.roles'])->whereIn('id', $rows->pluck('employee_id'))->get()->keyBy('id');
            $reviewers = Employee::with(['position', 'user.roles'])->whereIn('id', $rows->pluck('supervisor_id'))->get()->keyBy('id');
            foreach ($rows as $index => $row) {
                $employee = $employees->get($row['employee_id'] ?? '');
                $reviewer = $reviewers->get($row['supervisor_id'] ?? '');
                if (! $employee || ! $reviewer || ! $this->validReviewer($employee, $reviewer)) {
                    $validator->errors()->add("assignments.{$index}.supervisor_id", 'Penilai harus aktif, satu cabang, sesuai peran, dan bukan karyawan sendiri.');
                }
            }
        });
        $data = $validator->validate();
        $this->configuration->syncAssignments($data['assignments'], $request->user());

        return $this->wizardRedirect('assignments', ['step' => 3])->with('success', 'Penugasan penilai tersimpan sekaligus.');
    }

    public function startImport(Request $request, ImportMappingTemplate $template): RedirectResponse
    {
        $defaults = array_combine(array_keys(KpiConfigurationResources::IMPORT_COLUMNS), array_keys(KpiConfigurationResources::IMPORT_COLUMNS));
        $draft = $this->configuration->startImportMappingDraft($template, $defaults, $request->user());

        return $this->wizardRedirect('imports', ['mapping' => $draft->id, 'step' => 2]);
    }

    public function updateImport(Request $request, ImportMappingVersion $version): RedirectResponse
    {
        $mappings = $this->validatedMappings($request);
        $this->configuration->saveImportMappingDraft($version, $mappings, $request->user());

        return $this->wizardRedirect('imports', ['mapping' => $version->id, 'step' => 3])->with('success', 'Draft format import tersimpan.');
    }

    public function activateImport(Request $request, ImportMappingVersion $version): RedirectResponse
    {
        $this->validatedMappings(new Request(['mappings' => $version->mappings_json]));
        $message = $this->configuration->activateImportMapping($version, $request->user());

        return redirect()->route('app.kpi-administration.index')->with('success', $message);
    }

    private function templatePayload(KpiTemplateVersion $draft): array
    {
        return [
            'id' => $draft->id,
            'template' => $draft->template->name,
            'position' => $draft->template->position?->name,
            'version_number' => $draft->version_number,
            'total_weight' => (float) $draft->items->sum('weight'),
            'issues' => $this->configuration->templateIssues($draft),
            'items' => $draft->items->map(fn ($item) => [
                'id' => $item->id,
                'kpi_definition_id' => $item->kpi_definition_id,
                'code' => $item->definition->code,
                'name' => $item->definition->name,
                'target_value' => $item->target_value !== null ? (float) $item->target_value : '',
                'target_unit' => $item->target_unit,
                'weight' => (float) $item->weight,
                'sort_order' => $item->sort_order,
                'formula_key' => $item->formula_key,
                'rubric' => $item->rubric ? [
                    'name' => $item->rubric->name,
                    'description' => $item->rubric->description ?? '',
                    'criteria' => $item->rubric->criteria->map(fn ($criterion) => [
                        'id' => $criterion->id,
                        'criterion_text' => $criterion->criterion_text,
                        'sort_order' => $criterion->sort_order,
                    ])->values(),
                ] : null,
            ])->values(),
        ];
    }

    private function reviewers(): array
    {
        return Employee::with(['position', 'branch', 'user.roles'])
            ->where('status', 'active')
            ->whereHas('user', fn ($query) => $query->where('is_active', true)->whereHas('roles', fn ($roles) => $roles->whereIn('name', ['supervisor', 'owner_manager'])))
            ->orderBy('name')->get()->map(fn (Employee $employee): array => [
                'id' => (string) $employee->id,
                'name' => $employee->name,
                'branch_id' => (string) $employee->branch_id,
                'roles' => $employee->user->roles->pluck('name')->values(),
            ])->all();
    }

    private function validReviewer(Employee $employee, Employee $reviewer): bool
    {
        $requiredRole = $employee->position?->code === 'POS-SPV' ? 'owner_manager' : 'supervisor';

        return (string) $employee->id !== (string) $reviewer->id
            && $employee->status === 'active'
            && $reviewer->status === 'active'
            && (string) $employee->branch_id === (string) $reviewer->branch_id
            && $reviewer->user?->is_active
            && $reviewer->user->hasRole($requiredRole)
            && CapabilityMatrix::accessError($reviewer->user) === null;
    }

    private function validatedMappings(Request $request): array
    {
        $keys = array_keys(KpiConfigurationResources::IMPORT_COLUMNS);
        $rules = ['mappings' => ['required', 'array:'.implode(',', $keys)]];
        foreach ($keys as $key) {
            $rules["mappings.{$key}"] = ['required', 'string', 'max:150'];
        }
        $data = Validator::make($request->all(), $rules)->after(function ($validator) use ($request): void {
            $normalized = collect($request->input('mappings', []))->map(fn ($header) => preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $header))));
            if ($normalized->contains('') || $normalized->unique()->count() !== $normalized->count()) {
                $validator->errors()->add('mappings', 'Setiap data harus memakai header yang berbeda dan tidak kosong.');
            }
        })->validate();

        return collect($data['mappings'])->map(fn ($header) => trim($header))->all();
    }

    private function advancedLinks($user): array
    {
        $labels = [
            'kpi-definitions' => 'Katalog indikator', 'kpi-rating-bands' => 'Band predikat', 'kpi-rating-schemes' => 'Versi skema predikat',
            'kpi-templates' => 'Template jabatan', 'kpi-template-versions' => 'Versi template', 'kpi-template-items' => 'Target dan bobot',
            'kpi-rubrics' => 'Rubrik penilaian', 'kpi-rubric-criteria' => 'Kriteria rubrik', 'kpi-assignments' => 'Penugasan penilai',
            'import-mapping-templates' => 'Sumber import', 'import-mapping-versions' => 'Versi mapping',
        ];

        return collect($labels)->filter(fn ($label, $resource) => CapabilityMatrix::canAccessResource($user, $resource))
            ->map(fn ($label, $resource) => ['label' => $label, 'href' => "/app/{$resource}"])->values()->all();
    }

    private function wizardRedirect(string $task, array $parameters = []): RedirectResponse
    {
        return redirect()->route('app.kpi-administration.index', ['task' => $task, ...$parameters]);
    }
}
