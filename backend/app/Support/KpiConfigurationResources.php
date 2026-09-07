<?php

namespace App\Support;

use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportMappingTemplate;
use App\Models\ImportMappingVersion;
use App\Models\KpiDefinition;
use App\Models\KpiRatingScheme;
use App\Models\KpiRubric;
use App\Models\KpiRubricCriterion;
use App\Models\KpiTemplate;
use App\Models\KpiTemplateItem;
use App\Models\KpiTemplateVersion;
use App\Modules\Configuration\KpiConfigurationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class KpiConfigurationResources
{
    public const IMPORT_COLUMNS = [
        'transaction_number' => 'Nomor transaksi', 'transaction_date' => 'Tanggal transaksi',
        'cashier_name' => 'Nama kasir', 'transaction_amount' => 'Nilai transaksi',
        'system_cash_amount' => 'Kas sistem', 'actual_cash_amount' => 'Kas aktual',
        'duration_seconds' => 'Durasi (detik)', 'status' => 'Status transaksi',
    ];

    public static function definitions(): array
    {
        $catalogPermission = ['roles' => ['super_admin'], 'positions' => []];
        $operationsPermission = ['roles' => ['kpi_admin'], 'positions' => []];
        $versionEditable = static fn ($user, ?KpiTemplateVersion $record = null): bool => ! $record || $record->status === 'draft';
        $itemEditable = static fn ($user, ?KpiTemplateItem $record = null): bool => ! $record || $record->version->status === 'draft';
        $draftVersionRule = Rule::exists('kpi_template_versions', 'id')->where('status', 'draft');

        return [
            'kpi-rating-schemes' => [
                'model' => KpiRatingScheme::class, 'label' => 'Versi Scheme Rating', 'plural_label' => 'Versi Scheme Rating',
                'description' => 'Urutan perubahan: salin versi aktif, edit lima band pada draft, validasi rentang dan nilai, lalu aktifkan untuk periode berikutnya.',
                'permission' => $catalogPermission, 'search' => ['name'], 'columns' => [
                    ['key' => 'name', 'label' => 'Nama'], ['key' => 'version', 'label' => 'Versi'],
                    ['key' => 'score_cap', 'label' => 'Score Cap'], ['key' => 'is_active', 'label' => 'Aktif', 'type' => 'boolean'],
                ],
                'fields' => [
                    ['name' => 'name', 'label' => 'Nama', 'type' => 'text', 'required' => true],
                    ['name' => 'version', 'label' => 'Versi', 'type' => 'number', 'required' => true],
                    ['name' => 'score_cap', 'label' => 'Score Cap', 'type' => 'number', 'step' => '0.01', 'required' => true],
                    ['name' => 'description', 'label' => 'Deskripsi', 'type' => 'textarea'],
                    ['name' => 'is_default', 'label' => 'Scheme Default', 'type' => 'checkbox', 'default' => false],
                ],
                'rules' => static fn (?KpiRatingScheme $record): array => [
                    'name' => ['required', 'string', 'max:150'], 'version' => ['required', 'integer', 'min:1',
                        Rule::unique('kpi_rating_schemes', 'version')->where('name', request('name'))->ignore($record?->id)],
                    'score_cap' => ['required', 'numeric', 'gt:0'], 'description' => ['nullable', 'string'], 'is_default' => ['required', 'boolean'],
                ],
                'prepare' => static fn (array $data): array => [...$data, 'is_active' => false],
                'persist' => ['is_active'],
                'can_edit' => static fn ($user, ?KpiRatingScheme $record = null): bool => ! $record?->is_active,
                'can_delete' => false,
                'actions' => [
                    'copy' => ['label' => 'Salin ke versi baru', 'handler' => static fn (Request $request, Model $record) => app(KpiConfigurationService::class)->copyRatingScheme($record, $request->user())],
                    'activate' => ['label' => 'Aktifkan', 'visible' => static fn ($record) => ! $record->is_active,
                        'handler' => static fn (Request $request, Model $record) => app(KpiConfigurationService::class)->activateRatingScheme($record, $request->user())],
                ],
            ],
            'kpi-template-versions' => [
                'model' => KpiTemplateVersion::class, 'label' => 'Versi Template', 'plural_label' => 'Versi Template KPI',
                'description' => 'Urutan perubahan: salin versi aktif, edit target, bobot, sumber, dan rubrik pada draft, validasi, lalu aktifkan untuk periode berikutnya. Snapshot periode lama tetap tersimpan.',
                'permission' => $catalogPermission, 'search' => ['template.name', 'status'], 'with' => ['template', 'ratingScheme'],
                'columns' => [['key' => 'template.name', 'label' => 'Template'], ['key' => 'version_number', 'label' => 'Versi'], ['key' => 'status', 'label' => 'Status'], ['key' => 'total_weight', 'label' => 'Bobot', 'suffix' => '%']],
                'fields' => [
                    ['name' => 'kpi_template_id', 'label' => 'Template', 'type' => 'select', 'required' => true],
                    ['name' => 'version_number', 'label' => 'Nomor Versi', 'type' => 'number', 'required' => true],
                    ['name' => 'rating_scheme_id', 'label' => 'Skema Predikat', 'type' => 'select', 'required' => true],
                    ['name' => 'effective_from', 'label' => 'Berlaku Mulai', 'type' => 'date'],
                ],
                'rules' => static fn (?Model $record): array => [
                    'kpi_template_id' => ['required', 'integer', 'exists:kpi_templates,id'],
                    'version_number' => ['required', 'integer', 'min:1', Rule::unique('kpi_template_versions')->where('kpi_template_id', request('kpi_template_id'))->ignore($record?->id)],
                    'rating_scheme_id' => ['required', 'integer', 'exists:kpi_rating_schemes,id'],
                    'effective_from' => ['nullable', 'date'],
                ],
                'can_edit' => $versionEditable, 'can_delete' => $versionEditable,
                'options' => ['kpi_template_id' => static fn () => self::options(KpiTemplate::query()), 'rating_scheme_id' => static fn () => self::options(KpiRatingScheme::query())],
                'actions' => [
                    'activate' => ['label' => 'Aktifkan', 'confirm' => 'Aktifkan versi ini untuk periode berikutnya?', 'visible' => static fn ($record) => $record->status === 'draft',
                        'handler' => static fn (Request $request, Model $record) => app(KpiConfigurationService::class)->activate($record, $request->user())],
                    'copy' => ['label' => 'Salin ke draft', 'handler' => static fn (Request $request, Model $record) => app(KpiConfigurationService::class)->copy($record)],
                ],
            ],
            'kpi-template-items' => [
                'model' => KpiTemplateItem::class, 'label' => 'Target dan Bobot', 'plural_label' => 'Target dan Bobot Indikator',
                'description' => 'Edit indikator pada versi draft. Total bobot harus 100% sebelum versi dapat diaktifkan.',
                'permission' => $catalogPermission, 'search' => ['definition.name', 'version.template.name'], 'with' => ['definition', 'version.template'],
                'columns' => [['key' => 'version.template.name', 'label' => 'Template'], ['key' => 'version.version_number', 'label' => 'Versi'], ['key' => 'definition.name', 'label' => 'Indikator'], ['key' => 'target_value', 'label' => 'Target'], ['key' => 'weight', 'label' => 'Bobot', 'suffix' => '%']],
                'fields' => [
                    ['name' => 'template_version_id', 'label' => 'Versi Draft', 'type' => 'select', 'required' => true],
                    ['name' => 'kpi_definition_id', 'label' => 'Indikator', 'type' => 'select', 'required' => true],
                    ['name' => 'weight', 'label' => 'Bobot (%)', 'type' => 'number', 'step' => '0.01', 'required' => true],
                    ['name' => 'target_value', 'label' => 'Target', 'type' => 'number', 'step' => '0.01'],
                    ['name' => 'target_unit', 'label' => 'Satuan', 'type' => 'text', 'required' => true, 'default' => '%'],
                    ['name' => 'formula_key', 'label' => 'Formula', 'type' => 'select', 'required' => true, 'options' => self::labels(['higher_is_better' => 'Semakin tinggi semakin baik', 'lower_is_better' => 'Semakin rendah semakin baik', 'zero_tolerance' => 'Toleransi nol', 'rubric' => 'Rubrik'])],
                    ['name' => 'source_type', 'label' => 'Sumber', 'type' => 'select', 'required' => true, 'options' => self::labels(['system' => 'Operasional otomatis', 'import' => 'Import kasir', 'employee' => 'Fakta manual', 'supervisor' => 'Penilaian Supervisor', 'cross_role' => 'Verifikasi lintas peran'])],
                    ['name' => 'failure_limit', 'label' => 'Batas Gagal', 'type' => 'number', 'step' => '0.01'],
                    ['name' => 'full_score_limit', 'label' => 'Batas Skor Penuh', 'type' => 'number', 'step' => '0.01'],
                    ['name' => 'sort_order', 'label' => 'Urutan', 'type' => 'number', 'default' => 1, 'required' => true],
                    ['name' => 'evidence_required', 'label' => 'Bukti Wajib', 'type' => 'checkbox', 'default' => false],
                    ['name' => 'is_mandatory', 'label' => 'Indikator Wajib', 'type' => 'checkbox', 'default' => true],
                ],
                'rules' => static fn (?Model $record): array => [
                    'template_version_id' => ['required', 'integer', $draftVersionRule],
                    'kpi_definition_id' => ['required', 'integer', 'exists:kpi_definitions,id', Rule::unique('kpi_template_items')->where('template_version_id', request('template_version_id'))->ignore($record?->id)],
                    'weight' => ['required', 'numeric', 'min:0', 'max:100'], 'target_value' => ['nullable', 'numeric', 'min:0'], 'target_unit' => ['required', 'string', 'max:30'],
                    'formula_key' => ['required', Rule::in(['higher_is_better', 'lower_is_better', 'zero_tolerance', 'rubric'])],
                    'source_type' => ['required', Rule::in(['system', 'import', 'employee', 'supervisor', 'cross_role'])],
                    'failure_limit' => ['nullable', 'numeric', 'min:0'], 'full_score_limit' => ['nullable', 'numeric', 'min:0'],
                    'sort_order' => ['required', 'integer', 'min:1'], 'evidence_required' => ['required', 'boolean'], 'is_mandatory' => ['required', 'boolean'],
                ],
                'prepare' => static function (array $data): array {
                    $data['target_json'] = array_filter(['failure_limit' => $data['failure_limit'] ?? null, 'full_score_limit' => $data['full_score_limit'] ?? null], static fn ($value) => $value !== null && $value !== '');

                    return $data;
                },
                'persist' => ['target_json'], 'form_values' => static fn (KpiTemplateItem $record): array => $record->target_json ?? [],
                'can_edit' => $itemEditable, 'can_delete' => $itemEditable,
                'options' => ['template_version_id' => static fn () => self::versionOptions(), 'kpi_definition_id' => static fn () => self::options(KpiDefinition::where('is_active', true))],
            ],
            'kpi-rubrics' => [
                'model' => KpiRubric::class, 'label' => 'Rubrik', 'plural_label' => 'Rubrik Penilaian',
                'description' => 'Lengkapi rubrik dan kriteria untuk indikator dengan formula rubrik pada versi draft.',
                'permission' => $catalogPermission, 'search' => ['name'], 'with' => ['templateItem.definition'],
                'columns' => [['key' => 'name', 'label' => 'Rubrik'], ['key' => 'templateItem.definition.name', 'label' => 'Indikator']],
                'fields' => [['name' => 'template_item_id', 'label' => 'Indikator Draft', 'type' => 'select', 'required' => true], ['name' => 'name', 'label' => 'Nama Rubrik', 'type' => 'text', 'required' => true], ['name' => 'description', 'label' => 'Petunjuk Penilaian', 'type' => 'textarea']],
                'rules' => static fn (?Model $record): array => ['template_item_id' => ['required', 'integer', Rule::exists('kpi_template_items', 'id')->whereIn('template_version_id', KpiTemplateVersion::where('status', 'draft')->select('id')), Rule::unique('kpi_rubrics', 'template_item_id')->ignore($record?->id)], 'name' => ['required', 'string', 'max:150'], 'description' => ['nullable', 'string']],
                'can_edit' => static fn ($user, ?KpiRubric $record = null) => ! $record || $record->templateItem->version->status === 'draft',
                'can_delete' => static fn ($user, ?KpiRubric $record = null) => ! $record || $record->templateItem->version->status === 'draft',
                'options' => ['template_item_id' => static fn () => KpiTemplateItem::with(['definition', 'version.template'])->whereHas('version', fn ($query) => $query->where('status', 'draft'))->where('formula_key', 'rubric')->get()->map(fn ($item) => ['value' => (string) $item->id, 'label' => $item->version->template->name.' v'.$item->version->version_number.' — '.$item->definition->name])->all()],
            ],
            'kpi-rubric-criteria' => [
                'model' => KpiRubricCriterion::class, 'label' => 'Kriteria Rubrik', 'plural_label' => 'Kriteria Rubrik',
                'description' => 'Tentukan kriteria dan poin penilaian pada rubrik versi draft.', 'permission' => $catalogPermission,
                'search' => ['criterion_text', 'rubric.name'], 'with' => ['rubric'],
                'columns' => [['key' => 'rubric.name', 'label' => 'Rubrik'], ['key' => 'criterion_text', 'label' => 'Kriteria'], ['key' => 'points', 'label' => 'Poin']],
                'fields' => [['name' => 'rubric_id', 'label' => 'Rubrik Draft', 'type' => 'select', 'required' => true], ['name' => 'criterion_text', 'label' => 'Kriteria', 'type' => 'textarea', 'required' => true], ['name' => 'points', 'label' => 'Poin', 'type' => 'number', 'step' => '0.01', 'required' => true], ['name' => 'sort_order', 'label' => 'Urutan', 'type' => 'number', 'default' => 1, 'required' => true], ['name' => 'is_mandatory', 'label' => 'Kriteria Wajib', 'type' => 'checkbox', 'default' => true]],
                'rules' => static fn (?Model $record): array => ['rubric_id' => ['required', 'integer', Rule::exists('kpi_rubrics', 'id')->whereIn('template_item_id', KpiTemplateItem::whereIn('template_version_id', KpiTemplateVersion::where('status', 'draft')->select('id'))->select('id'))], 'criterion_text' => ['required', 'string', 'max:500'], 'points' => ['required', 'numeric', 'gt:0'], 'sort_order' => ['required', 'integer', 'min:1'], 'is_mandatory' => ['required', 'boolean']],
                'can_edit' => static fn ($user, ?KpiRubricCriterion $record = null) => ! $record || $record->rubric->templateItem->version->status === 'draft',
                'can_delete' => static fn ($user, ?KpiRubricCriterion $record = null) => ! $record || $record->rubric->templateItem->version->status === 'draft',
                'options' => ['rubric_id' => static fn () => self::options(KpiRubric::whereHas('templateItem.version', fn ($query) => $query->where('status', 'draft')))],
            ],
            'kpi-assignments' => [
                'model' => Employee::class, 'label' => 'Penugasan Penilai', 'plural_label' => 'Penugasan Penilai KPI',
                'description' => 'Tetapkan Supervisor untuk staf dan Manager untuk Supervisor sebelum periode dibuka. Assignment disalin ke snapshot periode.',
                'permission' => $operationsPermission, 'search' => ['name', 'employee_number'], 'with' => ['position', 'branch', 'supervisor'],
                'columns' => [['key' => 'name', 'label' => 'Karyawan'], ['key' => 'position.name', 'label' => 'Jabatan'], ['key' => 'branch.name', 'label' => 'Cabang'], ['key' => 'supervisor.name', 'label' => 'Penilai']],
                'scope' => static fn ($query) => $query->where('status', 'active')->whereHas('position', fn ($positions) => $positions->whereNotIn('code', ['POS-OWN', 'POS-EXEC'])),
                'fields' => [['name' => 'supervisor_id', 'label' => 'Penilai', 'type' => 'select', 'required' => true]],
                'rules' => static fn (?Employee $record): array => ['supervisor_id' => ['required', 'string', Rule::exists('employees', 'id')->where('branch_id', $record?->branch_id)->where('status', 'active'), static function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                    $reviewer = Employee::with(['user.roles', 'position'])->find($value);
                    $role = $record?->position?->code === 'POS-SPV' ? 'owner_manager' : 'supervisor';
                    if (! $reviewer?->user || (string) $value === (string) $record?->id || ! $reviewer->user->hasRole($role) || CapabilityMatrix::accessError($reviewer->user) !== null) {
                        $fail('Penilai harus akun aktif dengan peran yang sesuai, pada cabang sama, dan bukan karyawan sendiri.');
                    }
                }]],
                'can_create' => false, 'can_delete' => false,
                'options' => ['supervisor_id' => static fn (?Employee $record) => self::options(Employee::where('status', 'active')->where('branch_id', $record?->branch_id)->whereHas('user.roles', fn ($roles) => $roles->where('name', $record?->position?->code === 'POS-SPV' ? 'owner_manager' : 'supervisor')))],
            ],
            'import-mapping-templates' => [
                'model' => ImportMappingTemplate::class, 'label' => 'Mapping Import', 'plural_label' => 'Mapping Import Kasir',
                'description' => 'Daftar sumber laporan kasir. Atur nama kolom pada versi mapping sebelum digunakan Kasir.',
                'permission' => $operationsPermission, 'search' => ['name', 'source_application'],
                'columns' => [['key' => 'name', 'label' => 'Mapping'], ['key' => 'source_application', 'label' => 'Sumber'], ['key' => 'is_active', 'label' => 'Aktif', 'type' => 'boolean']],
                'fields' => [['name' => 'name', 'label' => 'Nama Mapping', 'type' => 'text', 'required' => true], ['name' => 'source_application', 'label' => 'Aplikasi Kasir', 'type' => 'text', 'required' => true], ['name' => 'description', 'label' => 'Keterangan', 'type' => 'textarea'], ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox', 'default' => true]],
                'rules' => static fn (?Model $record): array => ['name' => ['required', 'string', 'max:100'], 'source_application' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string'], 'is_active' => ['required', 'boolean']],
                'can_delete' => false,
            ],
            'import-mapping-versions' => self::mappingVersions($operationsPermission),
        ];
    }

    private static function mappingVersions(array $permission): array
    {
        $fields = [['name' => 'mapping_template_id', 'label' => 'Mapping', 'type' => 'select', 'required' => true], ['name' => 'version_number', 'label' => 'Nomor Versi', 'type' => 'number', 'required' => true], ['name' => 'is_active', 'label' => 'Aktif', 'type' => 'checkbox', 'default' => true]];
        foreach (self::IMPORT_COLUMNS as $column => $label) {
            $fields[] = ['name' => 'column_'.$column, 'label' => 'Kolom '.$label, 'type' => 'text', 'required' => true, 'default' => $column];
        }

        return [
            'model' => ImportMappingVersion::class, 'label' => 'Versi Mapping', 'plural_label' => 'Versi Mapping Import', 'permission' => $permission,
            'description' => 'Masukkan nama header pada laporan kasir untuk setiap data. Versi yang sudah digunakan tidak dapat diubah.',
            'search' => ['template.name'], 'with' => ['template'], 'fields' => $fields,
            'columns' => [['key' => 'template.name', 'label' => 'Mapping'], ['key' => 'version_number', 'label' => 'Versi'], ['key' => 'is_active', 'label' => 'Aktif', 'type' => 'boolean']],
            'rules' => static function (?Model $record): array {
                $rules = ['mapping_template_id' => ['required', 'integer', 'exists:import_mapping_templates,id'], 'version_number' => ['required', 'integer', 'min:1', Rule::unique('import_mapping_versions')->where('mapping_template_id', request('mapping_template_id'))->ignore($record?->id)], 'is_active' => ['required', 'boolean']];
                foreach (array_keys(self::IMPORT_COLUMNS) as $column) {
                    $rules['column_'.$column] = ['required', 'string', 'max:150'];
                }
                $rules['mappings_json'] = ['required', 'array', static function (string $attribute, mixed $value, \Closure $fail): void {
                    $headers = array_map(static fn ($header) => preg_replace('/[^a-z0-9]/', '', strtolower((string) $header)), $value);
                    if (in_array('', $headers, true) || count($headers) !== count(array_unique($headers))) {
                        $fail('Setiap data harus memakai header kolom yang berbeda dan tidak kosong.');
                    }
                }];

                return $rules;
            },
            'prepare' => static function (array $data): array {
                $mapping = [];
                foreach (array_keys(self::IMPORT_COLUMNS) as $column) {
                    $mapping[$column] = trim((string) ($data['column_'.$column] ?? ''));
                }
                $data['mappings_json'] = $mapping;

                return $data;
            },
            'persist' => ['mappings_json'], 'can_delete' => false,
            'can_edit' => static fn ($user, ?Model $record = null) => ! $record || ! ImportBatch::where('mapping_version_id', $record->id)->exists(),
            'form_values' => static function (ImportMappingVersion $record): array {
                $values = [];
                foreach (array_keys(self::IMPORT_COLUMNS) as $column) {
                    $values['column_'.$column] = $record->mappings_json[$column] ?? $column;
                }

                return $values;
            },
            'options' => ['mapping_template_id' => static fn () => self::options(ImportMappingTemplate::where('is_active', true))],
        ];
    }

    private static function options($query): array
    {
        return $query->orderBy('name')->get(['id', 'name'])->map(fn ($record) => ['value' => (string) $record->id, 'label' => $record->name])->all();
    }

    private static function versionOptions(): array
    {
        return KpiTemplateVersion::with('template')->where('status', 'draft')->get()->map(fn ($version) => ['value' => (string) $version->id, 'label' => $version->template->name.' v'.$version->version_number])->all();
    }

    private static function labels(array $labels): array
    {
        $options = [];
        foreach ($labels as $value => $label) {
            $options[] = ['value' => $value, 'label' => $label];
        }

        return $options;
    }
}
