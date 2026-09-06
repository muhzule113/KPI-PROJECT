<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\StockOpname;
use App\Models\User;
use App\Modules\Assessment\StockOpnameService;
use App\Support\AdminResourceRegistry;
use App\Support\CapabilityMatrix;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Adapter API untuk resource operasional yang juga dipakai oleh admin web.
 * Aturan field, scope, hook, dan action tetap berasal dari registry yang sama.
 */
final class AdminResourceApiController extends Controller
{
    private const MOBILE_RESOURCES = [
        'attendances', 'stock-opnames', 'admin-work-logs', 'complaints',
        'coaching-logs', 'customer-feedback', 'spareparts', 'import-batches',
    ];

    public function index(Request $request, string $resource): JsonResponse
    {
        $config = $this->config($resource);
        $this->authorizeResource($request, $resource, $config, 'view');

        $search = trim((string) $request->input('search', ''));
        $query = $config['model']::query();
        $this->applyScope($resource, $config, $query, $request->user());

        if (! empty($config['with'])) {
            $query->with($config['with']);
        }
        if (! empty($config['with_count'])) {
            $query->withCount($config['with_count']);
        }
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($config, $search): void {
                foreach ($config['search'] ?? [] as $field) {
                    if (str_contains($field, '.')) {
                        $separator = strrpos($field, '.');
                        [$relation, $column] = [substr($field, 0, $separator), substr($field, $separator + 1)];
                        $builder->orWhereHas($relation, fn (Builder $relationQuery) => $relationQuery->where($column, 'like', "%{$search}%"));
                    } else {
                        $builder->orWhere($field, 'like', "%{$search}%");
                    }
                }
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 5), 50);
        $paginator = $query
            ->orderByDesc($config['order_by'] ?? 'created_at')
            ->paginate($perPage)
            ->withQueryString();

        return response()->json([
            'success' => true,
            'data' => [
                'resource' => $this->clientConfig($resource, $config, $request),
                'records' => collect($paginator->items())
                    ->map(fn (Model $record): array => $this->rowPayload($resource, $record, $config, $request))
                    ->values(),
                'search' => $search,
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    public function show(Request $request, string $resource, string $record): JsonResponse
    {
        $config = $this->config($resource);
        $model = $this->findRecord($resource, $config, $record, $request->user());
        $this->authorizeResource($request, $resource, $config, 'view', $model);

        if ($model instanceof StockOpname) {
            $model->load(['period', 'items.sparepart']);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'resource' => $this->clientConfig($resource, $config, $request, $model),
                'record' => $this->rowPayload($resource, $model, $config, $request),
                'form' => $this->formPayload($config, $model, $request),
                'detail' => $this->detailPayload($model),
            ],
        ]);
    }

    public function store(Request $request, string $resource): JsonResponse
    {
        $config = $this->config($resource);
        $this->authorizeResource($request, $resource, $config, 'create');
        abort_unless(($config['can_create'] ?? true) !== false, 403);

        $model = $config['model'];
        $data = $this->prepareData($config, $request->all(), $request, null);
        $request->merge($data);
        $validated = $request->validate(($config['rules'])(null));
        $record = null;

        DB::transaction(function () use ($config, $model, $validated, $data, $request, &$record): void {
            $record = $model::query()->create([...$validated, ...$this->preparedFields($config, $data)]);
            $this->syncRelationships($config, $record, $data);
            $this->runHook($config, 'after_create', $record, $request);
            $this->auditResourceChange('created', $record, null, $request);
        });

        return response()->json([
            'success' => true,
            'message' => "{$config['label']} berhasil ditambahkan.",
            'data' => $this->recordResponse($resource, $record, $config, $request),
        ], 201);
    }

    public function update(Request $request, string $resource, string $record): JsonResponse
    {
        $config = $this->config($resource);
        $model = $this->findRecord($resource, $config, $record, $request->user());
        $this->authorizeResource($request, $resource, $config, 'edit', $model);
        abort_unless(($config['can_edit'] ?? true) !== false, 403);

        if ($model instanceof StockOpname && $request->has('items')) {
            return $this->updateStockOpname($request, $model);
        }

        $data = $this->prepareData($config, $request->all(), $request, $model);
        $request->merge($data);
        $validated = $request->validate(($config['rules'])($model));
        $before = $this->auditSnapshot($model);

        DB::transaction(function () use ($config, $model, $validated, $data, $request, $before): void {
            $this->runHook($config, 'before_update', $model, $request);
            $model->update([...$validated, ...$this->preparedFields($config, $data)]);
            $this->syncRelationships($config, $model, $data);
            $this->runHook($config, 'after_update', $model, $request);
            $this->auditResourceChange('updated', $model->fresh(), $before, $request);
        });

        return response()->json([
            'success' => true,
            'message' => "{$config['label']} berhasil diperbarui.",
            'data' => $this->recordResponse($resource, $model->fresh(), $config, $request),
        ]);
    }

    public function action(Request $request, string $resource, string $action): JsonResponse
    {
        return $this->runAction($request, $resource, $action);
    }

    public function recordAction(Request $request, string $resource, string $record, string $action): JsonResponse
    {
        return $this->runAction($request, $resource, $action, $record);
    }

    private function runAction(Request $request, string $resource, string $action, ?string $record = null): JsonResponse
    {
        $config = $this->config($resource);
        $definition = $config['actions'][$action] ?? null;
        abort_unless(is_array($definition) && isset($definition['handler']), 404);
        $model = $record ? $this->findRecord($resource, $config, $record, $request->user()) : null;
        $this->authorizeResource($request, $resource, $config, 'view', $model);
        abort_unless(CapabilityMatrix::canAccessResource($request->user(), $resource, 'action'), 403);
        if (isset($definition['visible'])) {
            abort_unless($definition['visible']($model, $request->user()), 403);
        }
        if (isset($definition['permission'])) {
            abort_unless($this->permissionAllowed($definition['permission'], $request->user()), 403);
        } else {
            abort_unless($this->permissionAllowed($config['permission'] ?? [], $request->user()), 403);
        }

        $result = ($definition['handler'])($request, $model);
        $message = is_string($result)
            ? $result
            : (is_array($result) ? ($result['message'] ?? 'Aksi berhasil dijalankan.') : 'Aksi berhasil dijalankan.');

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $record && $model
                ? $this->recordResponse($resource, $model->fresh(), $config, $request)
                : null,
        ]);
    }

    private function updateStockOpname(Request $request, StockOpname $opname): JsonResponse
    {
        $data = $request->validate([
            'code' => ['nullable', 'string', 'max:50'],
            'period_id' => ['required', 'integer', 'exists:kpi_periods,id'],
            'deadline' => ['nullable', 'date'],
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'string', 'exists:stock_opname_items,id'],
            'items.*.physical_stock' => ['required', 'integer', 'min:0'],
        ]);

        app(StockOpnameService::class)->saveCounts($opname, $data, $request->user()->id);

        $opname->load(['period', 'items.sparepart']);

        return response()->json([
            'success' => true,
            'message' => 'Data stock opname berhasil disimpan.',
            'data' => ['detail' => $this->detailPayload($opname)],
        ]);
    }

    private function config(string $resource): array
    {
        $config = AdminResourceRegistry::get($resource);
        abort_if($config === null || ! in_array($resource, self::MOBILE_RESOURCES, true), 404);

        return $config;
    }

    private function authorizeResource(
        Request $request,
        string $resource,
        array $config,
        string $ability,
        ?Model $record = null,
    ): void {
        /** @var User $user */
        $user = $request->user()->loadMissing(['employee.position', 'roles']);
        $allowed = CapabilityMatrix::canAccessResource($user, $resource, $ability);

        if ($allowed && isset($config["can_{$ability}"])) {
            $rule = $config["can_{$ability}"];
            $allowed = is_callable($rule) ? (bool) $rule($user, $record) : (bool) $rule;
        }

        abort_unless($allowed, 403);
    }

    private function findRecord(string $resource, array $config, string $key, User $user): Model
    {
        $query = $config['model']::query();
        $this->applyScope($resource, $config, $query, $user);

        return $query->findOrFail($key);
    }

    private function applyScope(string $resource, array $config, Builder $query, ?User $user): void
    {
        if (isset($config['scope'])) {
            ($config['scope'])($query, $user);
        }

        if ($resource === 'complaints'
            && $user?->employee?->position?->code === 'POS-CS'
            && $user->employee?->id) {
            $query->where(function (Builder $scope) use ($user): void {
                $scope
                    ->where('recorded_by', $user->getKey())
                    ->orWhere('employee_id', $user->employee->id);
            });
        }
    }

    private function clientConfig(string $key, array $config, Request $request, ?Model $record = null): array
    {
        $fields = array_map(function (array $field) use ($request, $record): array {
            if (isset($field['readOnly']) && is_callable($field['readOnly'])) {
                $field['readOnly'] = (bool) $field['readOnly']($record, $request->user());
            }

            return Arr::except($field, ['options']);
        }, $config['fields'] ?? []);

        $options = [];
        foreach ($config['fields'] ?? [] as $field) {
            if (isset($field['options'])) {
                $options[$field['name']] = is_callable($field['options'])
                    ? ($field['options'])($record, $request->user())
                    : $field['options'];
            }
        }
        foreach ($config['options'] ?? [] as $field => $resolver) {
            $options[$field] = $resolver($record, $request->user());
        }

        return [
            'key' => $key,
            'label' => $config['label'],
            'plural_label' => $config['plural_label'],
            'description' => $config['description'],
            'columns' => $config['columns'] ?? [],
            'fields' => $fields,
            'options' => $options,
            'can_create' => $this->resourceCapabilityAllowed($key, 'create', $request->user())
                && $this->ability($config, 'create', $request->user()),
            'can_edit' => $this->resourceCapabilityAllowed($key, 'edit', $request->user())
                && $this->ability($config, 'edit', $request->user(), $record),
            'header_actions' => collect($config['actions'] ?? [])
                ->filter(fn (array $action): bool => ($action['scope'] ?? 'row') === 'header')
                ->filter(fn (array $action): bool => $this->actionAllowed($config, $action, $request->user()))
                ->map(fn (array $action, string $name): array => [
                    'key' => $name,
                    'label' => $action['label'],
                    'variant' => $action['variant'] ?? 'outline',
                    'type' => $action['type'] ?? 'post',
                    'confirm' => $action['confirm'] ?? null,
                    'prompt' => $action['prompt'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function formPayload(array $config, Model $record, Request $request): array
    {
        $values = [];
        foreach ($config['fields'] ?? [] as $field) {
            $value = data_get($record, $field['name']);
            if ($value instanceof \DateTimeInterface) {
                $value = match ($field['type'] ?? null) {
                    'datetime-local' => $value->format('Y-m-d\TH:i'),
                    'time' => $value->format('H:i'),
                    default => $value->format('Y-m-d'),
                };
            }
            $values[$field['name']] = $value;
        }

        return [
            'mode' => 'edit',
            'record_id' => $record->getKey(),
            'values' => $values,
            'options' => $this->clientConfig('', $config, $request, $record)['options'],
        ];
    }

    private function rowPayload(string $resource, Model $record, array $config, Request $request): array
    {
        $values = [];
        foreach ($config['columns'] ?? [] as $column) {
            $value = data_get($record, $column['key']);
            $label = $value;
            if ($value === null || $value === '') {
                $label = $column['placeholder'] ?? '—';
            } elseif (isset($column['labels']) && array_key_exists($value, $column['labels'])) {
                $label = $column['labels'][$value];
            } elseif (($column['type'] ?? null) === 'boolean') {
                $label = $value ? 'Aktif' : 'Tidak aktif';
            } elseif ($value instanceof \DateTimeInterface) {
                $label = match ($column['type'] ?? null) {
                    'datetime' => $value->format('d M Y H:i'),
                    'time' => $value->format('H:i'),
                    default => $value->format('d M Y'),
                };
            }
            if ($value !== null && $value !== '' && ($column['prefix'] ?? null)) {
                $label = $column['prefix'].$label;
            }
            if ($value !== null && $value !== '' && ($column['suffix'] ?? null)) {
                $label .= $column['suffix'];
            }
            $values[$column['key']] = ['value' => $value, 'label' => $label];
        }

        return [
            'id' => (string) $record->getKey(),
            'values' => $values,
            'can_edit' => $this->resourceCapabilityAllowed($resource, 'edit', $request->user())
                && $this->ability($config, 'edit', $request->user(), $record),
            'actions' => collect($config['actions'] ?? [])
                ->filter(fn (array $action): bool => ($action['scope'] ?? 'row') === 'row')
                ->filter(fn (array $action): bool => ! isset($action['visible']) || $action['visible']($record, $request->user()))
                ->filter(fn (array $action): bool => $this->actionAllowed($config, $action, $request->user()))
                ->map(fn (array $action, string $name): array => [
                    'key' => $name,
                    'label' => $action['label'],
                    'variant' => $action['variant'] ?? 'outline',
                    'type' => $action['type'] ?? 'post',
                    'confirm' => $action['confirm'] ?? null,
                    'prompt' => $action['prompt'] ?? null,
                ])
                ->values()
                ->all(),
        ];
    }

    private function detailPayload(Model $record): ?array
    {
        if (! $record instanceof StockOpname) {
            return null;
        }

        return [
            'id' => (string) $record->getKey(),
            'code' => $record->code,
            'period_id' => (string) $record->period_id,
            'period' => $record->period?->name,
            'deadline' => $record->deadline?->toDateString(),
            'status' => $record->status,
            'items' => $record->items->map(fn ($item): array => [
                'id' => (string) $item->getKey(),
                'sparepart' => $item->sparepart?->name,
                'code' => $item->sparepart?->code,
                'system_stock' => (int) $item->system_stock,
                'physical_stock' => $item->physical_stock,
            ])->values()->all(),
        ];
    }

    private function recordResponse(string $resource, Model $record, array $config, Request $request): array
    {
        if ($record instanceof StockOpname) {
            $record->load(['period', 'items.sparepart']);
        }

        return [
            'record' => $this->rowPayload($resource, $record, $config, $request),
            'detail' => $this->detailPayload($record),
        ];
    }

    private function ability(array $config, string $ability, User $user, ?Model $record = null): bool
    {
        $rule = $config["can_{$ability}"] ?? true;

        return is_callable($rule) ? (bool) $rule($user, $record) : (bool) $rule;
    }

    private function actionAllowed(array $config, array $action, User $user): bool
    {
        return CapabilityMatrix::canAccessResource($user, $config['key'], 'action')
            && $this->permissionAllowed($action['permission'] ?? ($config['permission'] ?? []), $user);
    }

    private function resourceCapabilityAllowed(string $resource, string $ability, User $user): bool
    {
        return CapabilityMatrix::canAccessResource($user, $resource, $ability);
    }

    private function permissionAllowed(array $permission, User $user): bool
    {
        return CapabilityMatrix::matches($user, $permission['roles'] ?? [], $permission['positions'] ?? []);
    }

    private function prepareData(array $config, array $data, Request $request, ?Model $record): array
    {
        return isset($config['prepare'])
            ? ($config['prepare'])($data, $request, $record)
            : $data;
    }

    private function preparedFields(array $config, array $data): array
    {
        return Arr::only($data, $config['persist'] ?? []);
    }

    private function runHook(array $config, string $hook, Model $record, Request $request): void
    {
        if (isset($config[$hook])) {
            ($config[$hook])($record, $request);
        }
    }

    private function syncRelationships(array $config, Model $record, array $data): void
    {
        foreach ($config['relationships'] ?? [] as $field => $relation) {
            if (array_key_exists($field, $data)) {
                $record->{$relation}()->sync($data[$field] ?? []);
            }
        }
    }

    private function auditResourceChange(string $action, Model $record, ?array $before, Request $request): void
    {
        AuditEvent::log(
            action: "api_resource_{$action}",
            subjectType: class_basename($record),
            subjectId: (string) $record->getKey(),
            before: $before,
            after: $action === 'deleted' ? null : $this->auditSnapshot($record),
            actorId: $request->user()?->getKey(),
        );
    }

    private function auditSnapshot(Model $record): array
    {
        return Arr::except($record->getAttributes(), ['password', 'remember_token', 'passcode_or_pattern']);
    }
}
