<?php

namespace Ronu\RestGenericClass\Core\Support\Permissions;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;

final class PermissionPayloadBuilder
{
    public function build(
        Request $request,
        mixed $context,
        callable $permissionsFiltered,
        callable $permissionsCompressed
    ): array {
        $guard = $this->guard($request);
        $modules = $this->list($request->query('modules'));
        $entities = $this->list($request->query('entities'));
        $base = $this->context($context);

        if ($guard !== null) {
            $base['guard'] = $guard;
        }

        if (!$request->boolean('compress', false)) {
            /** @var Collection $permissions */
            $permissions = $permissionsFiltered($guard, $modules, $entities);

            return array_merge($base, [
                'count' => $permissions->count(),
                'permissions' => $this->rows($permissions),
            ]);
        }

        return array_merge($base, $permissionsCompressed(
            $guard,
            $modules,
            $entities,
            [
                'module_wildcard' => true,
                'table_wildcard' => true,
                'global_wildcard' => $request->boolean('compress_global', false),
                'include_expanded' => $request->boolean('expand', false),
            ]
        ));
    }

    public function list(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $values = is_array($value) ? $value : explode(',', (string)$value);
        $values = collect($values)
            ->flatMap(fn($item) => is_array($item) ? $item : explode(',', (string)$item))
            ->map(fn($item) => trim((string)$item))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $values === [] ? null : $values;
    }

    public function context(mixed $context): array
    {
        if ($context === null) {
            return [];
        }

        return is_array($context) ? $context : ['context' => $context];
    }

    public function rows(Collection $permissions): array
    {
        return $permissions
            ->map(fn($permission) => [
                'id' => $permission->id ?? null,
                'name' => $permission->name,
                'module' => $permission->module ?? null,
                'guard' => $permission->guard_name ?? null,
            ])
            ->values()
            ->all();
    }

    private function guard(Request $request): ?string
    {
        $guard = $request->query('guard');

        return is_string($guard) && $guard !== '' ? $guard : null;
    }
}
