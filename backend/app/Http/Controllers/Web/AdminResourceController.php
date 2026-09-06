<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\EmployeeKpi;
use App\Models\User;
use App\Support\AdminResourceRegistry;
use App\Support\CapabilityMatrix;
use App\Support\KpiVisibility;
use App\Support\MenuAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class AdminResourceController extends Controller
{
    public function index(Request $request, string $resource): Response
    {
        $config = $this->config($resource);
        $this->authorize($request, $config);

        $model = $config['model'];
        $search = trim((string) $request->input('search', ''));
        $query = $model::query();

        if (isset($config['scope'])) {
            ($config['scope'])($query, $request->user());
        }

        if (! empty($config['with'])) {
            $query->with($config['with']);
        }

        if (! empty($config['with_count'])) {
            $query->withCount($config['with_count']);
        }

        if ($search !== '') {
            $query->where(function (Builder $query) use ($config, $search): void {
                foreach ($config['search'] as $field) {
                    if ($field === 'rating_label') {
                        $query->orWhere(fn (Builder $scores) => KpiVisibility::visibleScores($scores, request()->user())->where($field, 'like', "%{$search}%"));

                        continue;
                    }
                    if (str_contains($field, '.')) {
                        $separator = strrpos($field, '.');
                        [$relation, $column] = [substr($field, 0, $separator), substr($field, $separator + 1)];
                        $query->orWhereHas($relation, fn (Builder $relationQuery) => $relationQuery->where($column, 'like', "%{$search}%"));
                    } else {
                        $query->orWhere($field, 'like', "%{$search}%");
                    }
                }
            });
        }

        $perPage = min(max((int) $request->input('per_page', 10), 5), 50);
        $paginator = $query
            ->orderByDesc($config['order_by'] ?? 'created_at')
            ->paginate($perPage)
            ->withQueryString();

        return Inertia::render('Admin/ResourceIndex', [
            'resource' => $this->clientConfig($resource, $config, $request),
            'records' => collect($paginator->items())
                ->map(fn (Model $record): array => $this->rowPayload($resource, $record, $config, $request))
                ->values()
                ->all(),
            'search' => $search,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'previous_url' => $paginator->previousPageUrl(),
                'next_url' => $paginator->nextPageUrl(),
                'pages' => range(1, $paginator->lastPage()),
            ],
        ]);
    }

    public function create(Request $request, string $resource): Response
    {
        $config = $this->config($resource);
        $this->authorize($request, $config, 'create');

        return Inertia::render('Admin/ResourceForm', [
            'resource' => $this->clientConfig($resource, $config, $request),
            'form' => $this->formPayload($config, null, $request),
        ]);
    }

    public function store(Request $request, string $resource)
    {
        $config = $this->config($resource);
        $this->authorize($request, $config, 'create');
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

        return redirect()
            ->route('app.resource.index', ['resource' => $resource])
            ->with('success', "{$config['label']} berhasil ditambahkan.");
    }

    public function edit(Request $request, string $resource, string $record): Response
    {
        $config = $this->config($resource);
        $record = $this->findRecord($config, $record, $request->user());
        $this->authorize($request, $config, 'edit', $record);

        return Inertia::render('Admin/ResourceForm', [
            'resource' => $this->clientConfig($resource, $config, $request, $record),
            'form' => $this->formPayload($config, $record, $request),
        ]);
    }

    public function update(Request $request, string $resource, string $record)
    {
        $config = $this->config($resource);
        $record = $this->findRecord($config, $record, $request->user());
        $this->authorize($request, $config, 'edit', $record);
        $data = $this->prepareData($config, $request->all(), $request, $record);

        $request->merge($data);
        $validated = $request->validate(($config['rules'])($record));
        $before = $this->auditSnapshot($record);
        DB::transaction(function () use ($config, $record, $validated, $data, $request, $before): void {
            $this->runHook($config, 'before_update', $record, $request);
            $record->update([...$validated, ...$this->preparedFields($config, $data)]);
            $this->syncRelationships($config, $record, $data);
            $this->runHook($config, 'after_update', $record, $request);
            $this->auditResourceChange('updated', $record->fresh(), $before, $request);
        });

        return redirect()
            ->route('app.resource.index', ['resource' => $resource])
            ->with('success', "{$config['label']} berhasil diperbarui.");
    }

    public function destroy(Request $request, string $resource, string $record)
    {
        $config = $this->config($resource);
        $record = $this->findRecord($config, $record, $request->user());
        $this->authorize($request, $config, 'delete', $record);

        try {
            $before = $this->auditSnapshot($record);
            DB::transaction(function () use ($config, $record, $request, $before): void {
                $this->runHook($config, 'before_delete', $record, $request);
                $record->delete();
                $this->runHook($config, 'after_delete', $record, $request);
                $this->auditResourceChange('deleted', $record, $before, $request);
            });
        } catch (QueryException|\RuntimeException $exception) {
            return back()->with('error', "{$config['label']} tidak dapat dihapus karena masih digunakan.");
        }

        return back()->with('success', "{$config['label']} berhasil dihapus.");
    }

    private function config(string $resource): array
    {
        $config = AdminResourceRegistry::get($resource);

        abort_if($config === null, 404);

        return $config;
    }

    public function action(Request $request, string $resource, string $action)
    {
        return $this->runAction($request, $resource, $action);
    }

    public function recordAction(Request $request, string $resource, string $record, string $action)
    {
        return $this->runAction($request, $resource, $action, $record);
    }

    private function runAction(Request $request, string $resource, string $action, ?string $record = null)
    {
        $config = $this->config($resource);
        $definition = $config['actions'][$action] ?? null;
        abort_if($definition === null || ! isset($definition['handler']), 404);

        $model = $record ? $this->findRecord($config, $record, $request->user()) : null;
        $this->authorize($request, $config, 'view', $model);
        abort_unless(CapabilityMatrix::canAccessResource($request->user(), $resource, 'action'), 403);
        if (isset($definition['visible'])) {
            abort_unless($definition['visible']($model, $request->user()), 403);
        }

        if (isset($definition['permission'])) {
            $permission = $definition['permission'];
            abort_unless(MenuAccess::can($request->user(), $permission['roles'] ?? [], $permission['positions'] ?? []), 403);
        }

        try {
            $result = ($definition['handler'])($request, $model);
        } catch (\Throwable $exception) {
            return back()->with('error', $exception->getMessage());
        }

        if ($result instanceof \Symfony\Component\HttpFoundation\Response) {
            return $result;
        }

        if (is_array($result) && ! empty($result['error'])) {
            return back()->with('error', $result['error']);
        }

        return back()->with('success', is_string($result) ? $result : ($result['message'] ?? 'Aksi berhasil dijalankan.'));
    }

    private function authorize(Request $request, array $config, string $ability = 'view', ?Model $record = null): void
    {
        $allowed = CapabilityMatrix::canAccessResource($request->user(), $config['key'], $ability);
        $rule = $config["can_{$ability}"] ?? true;

        if ($allowed && is_callable($rule)) {
            $allowed = (bool) $rule($request->user(), $record);
        } elseif ($allowed) {
            $allowed = (bool) $rule;
        }

        abort_unless($allowed, 403);
    }

    private function findRecord(array $config, string $key, ?User $user): Model
    {
        $model = $config['model'];
        $query = $model::query();

        if (isset($config['scope'])) {
            ($config['scope'])($query, $user);
        }

        return $query->findOrFail($key);
    }

    private function clientConfig(string $key, array $config, Request $request, ?Model $record = null): array
    {
        return [
            'key' => $key,
            'label' => $config['label'],
            'plural_label' => $config['plural_label'],
            'description' => $config['description'],
            'columns' => $config['columns'],
            'fields' => array_map(function (array $field) use ($request, $record): array {
                if (isset($field['readOnly']) && is_callable($field['readOnly'])) {
                    $field['readOnly'] = (bool) ($field['readOnly'])($record, $request->user());
                }

                return Arr::except($field, ['options']);
            }, $config['fields']),
            'can_create' => $this->ability($request, $config, 'create'),
            'can_edit' => $this->ability($request, $config, 'edit'),
            'can_delete' => $this->ability($request, $config, 'delete'),
            'header_actions' => collect($config['actions'] ?? [])
                ->filter(fn (array $action): bool => ($action['scope'] ?? 'row') === 'header')
                ->filter(fn (array $action): bool => $this->actionAllowed($request, $action, $config))
                ->map(fn (array $action, string $name): array => $this->actionPayload($name, $action, $key))
                ->values()
                ->all(),
        ];
    }

    private function formPayload(array $config, ?Model $record, Request $request): array
    {
        $values = [];
        $options = [];

        foreach ($config['fields'] as $field) {
            $value = $record
                ? (isset($config['relationships'][$field['name']])
                    ? $record->{$config['relationships'][$field['name']]}()->pluck($record->{$config['relationships'][$field['name']]}()->getRelated()->getTable().'.id')->values()->all()
                    : data_get($record, $field['name']))
                : ($field['default'] ?? null);

            if ($record && ($field['sensitive'] ?? false)) {
                $value = null;
            }

            if ($value instanceof \DateTimeInterface) {
                $value = match ($field['type'] ?? null) {
                    'datetime-local' => $value->format('Y-m-d\TH:i'),
                    'time' => $value->format('H:i'),
                    default => $value->format('Y-m-d'),
                };
            }

            $values[$field['name']] = $value;

            if (isset($field['options'])) {
                $options[$field['name']] = is_callable($field['options'])
                    ? ($field['options'])($record, $request->user())
                    : $field['options'];
            }
        }

        foreach ($config['options'] ?? [] as $field => $resolver) {
            $options[$field] = $resolver($record, $request->user());
        }

        if ($record && isset($config['form_values'])) {
            $values = [...$values, ...$config['form_values']($record)];
        }

        return [
            'mode' => $record ? 'edit' : 'create',
            'record_id' => $record?->getKey(),
            'values' => $values,
            'options' => $options,
        ];
    }

    private function rowPayload(string $resource, Model $record, array $config, Request $request): array
    {
        $values = [];

        foreach ($config['columns'] as $column) {
            $value = data_get($record, $column['key']);
            if ($record instanceof EmployeeKpi && in_array($column['key'], ['final_score', 'rating_label'], true)
                && ! KpiVisibility::scoreVisible($request->user(), $record)) {
                $value = null;
            }
            $label = $value;

            if ($value === null || $value === '') {
                $label = $column['placeholder'] ?? '—';
            } elseif (isset($column['labels']) && array_key_exists($value, $column['labels'])) {
                $label = $column['labels'][$value];
            } elseif (($column['type'] ?? null) === 'boolean') {
                $label = $value ? 'Aktif' : 'Tidak aktif';
            } elseif (($column['type'] ?? null) === 'status') {
                $label = match ($value) {
                    'active' => 'Aktif',
                    'inactive' => 'Tidak aktif',
                    'resigned' => 'Resign',
                    'leave' => 'Cuti',
                    default => str_replace('_', ' ', (string) $value),
                };
            } elseif (($column['type'] ?? null) === 'money') {
                $label = 'Rp '.number_format((float) $value, 0, ',', '.');
            } elseif (($column['type'] ?? null) === 'decimal') {
                $label = number_format((float) $value, 2, ',', '.');
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

            $values[$column['key']] = [
                'value' => $value,
                'label' => $label,
            ];
        }

        return [
            'id' => (string) $record->getKey(),
            'values' => $values,
            'can_edit' => $this->ability($request, $config, 'edit', $record),
            'can_delete' => $this->ability($request, $config, 'delete', $record),
            'actions' => collect($config['actions'] ?? [])
                ->filter(fn (array $action): bool => ($action['scope'] ?? 'row') === 'row')
                ->filter(fn (array $action): bool => ! isset($action['visible']) || $action['visible']($record, $request->user()))
                ->filter(fn (array $action): bool => $this->actionAllowed($request, $action, $config))
                ->map(fn (array $action, string $name): array => $this->actionPayload($name, $action, $resource))
                ->values()
                ->all(),
        ];
    }

    private function ability(Request $request, array $config, string $ability, ?Model $record = null): bool
    {
        if (! CapabilityMatrix::canAccessResource($request->user(), $config['key'], $ability)) {
            return false;
        }
        $rule = $config["can_{$ability}"] ?? true;

        return is_callable($rule)
            ? (bool) $rule($request->user(), $record)
            : (bool) $rule;
    }

    private function actionAllowed(Request $request, array $action, array $config): bool
    {
        if (! CapabilityMatrix::canAccessResource($request->user(), $config['key'], ($action['type'] ?? null) === 'link' ? 'view' : 'action')) {
            return false;
        }
        if (! isset($action['permission'])) {
            return true;
        }

        $permission = $action['permission'];

        return MenuAccess::can(
            $request->user(),
            $permission['roles'] ?? [],
            $permission['positions'] ?? [],
        );
    }

    private function auditResourceChange(string $action, Model $record, ?array $before, Request $request): void
    {
        AuditEvent::log(
            action: "web_resource_{$action}",
            subjectType: class_basename($record),
            subjectId: (string) $record->getKey(),
            before: $before,
            after: $action === 'deleted' ? null : $this->auditSnapshot($record),
            actorId: $request->user()?->getKey(),
        );
    }

    private function auditSnapshot(Model $record): array
    {
        $snapshot = Arr::except($record->getAttributes(), [
            'password',
            'remember_token',
            'passcode_or_pattern',
        ]);

        if ($record instanceof User) {
            $snapshot['roles'] = $record->roles()->pluck('name')->all();
            $snapshot['employee_id'] = $record->employee()->value('id');
        }

        return $snapshot;
    }

    private function prepareData(array $config, array $data, Request $request, ?Model $record): array
    {
        if (isset($config['prepare'])) {
            $data = ($config['prepare'])($data, $request, $record);
        }

        return $data;
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

    private function actionPayload(string $name, array $action, string $resource): array
    {
        return [
            'key' => $name,
            'label' => $action['label'],
            'variant' => $action['variant'] ?? 'outline',
            'confirm' => $action['confirm'] ?? null,
            'prompt' => $action['prompt'] ?? null,
            'type' => $action['type'] ?? 'post',
            'url' => $action['url'] ?? "/app/{$resource}/actions/{$name}",
            'record_url' => $action['record_url'] ?? "/app/{$resource}/:record/actions/{$name}",
        ];
    }
}
