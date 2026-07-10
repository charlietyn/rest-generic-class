<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionFilter;
use Ronu\RestGenericClass\Core\Traits\HasReadableRolePermissions;
use Ronu\RestGenericClass\Core\Traits\HasReadableUserPermissions;

final class PermissionFilterTest extends TestCase
{
    public function testFilterMatchesGuardModuleAndEntity(): void
    {
        $result = (new PermissionFilter())->filter(
            collect([
                $this->permission(1, 'api', 'security', 'user'),
                $this->permission(2, 'web', 'security', 'user'),
                $this->permission(3, 'api', 'billing', 'invoice'),
                $this->permission(4, 'api', 'security', 'role'),
            ]),
            'api',
            ['security'],
            ['security.USER']
        );

        $this->assertSame([1], $result->pluck('id')->all());
    }

    public function testUnrestrictedPermissionsBypassModuleAndEntityButNotGuard(): void
    {
        $result = (new PermissionFilter())->filter(
            collect([
                $this->permission(1, 'api', 'security', 'user', restrict: true),
                $this->permission(2, 'api', 'reports', 'dashboard', restrict: false),
                $this->permission(3, 'web', 'reports', 'dashboard', restrict: false),
            ]),
            'api',
            ['security'],
            ['security.user'],
            includeUnrestricted: true
        );

        $this->assertSame([1, 2], $result->pluck('id')->all());
    }

    public function testUserTraitFiltersAllEffectivePermissions(): void
    {
        $user = new PermissionFilterFakeUser([
            $this->permission(1, 'api', 'security', 'user', restrict: true),
            $this->permission(2, 'api', 'reports', 'dashboard', restrict: false),
            $this->permission(3, 'web', 'security', 'user', restrict: true),
        ]);

        $result = $user->permissionsFiltered('api', ['security'], ['security.user']);

        $this->assertSame([1], $result->pluck('id')->all());
    }

    public function testRoleTraitKeepsUnrestrictedPermissionsAfterGuardFilter(): void
    {
        $role = new PermissionFilterFakeRole([
            $this->permission(1, 'api', 'security', 'user', restrict: true),
            $this->permission(2, 'api', 'reports', 'dashboard', restrict: false),
            $this->permission(3, 'web', 'reports', 'dashboard', restrict: false),
        ]);

        $result = $role->permissionsFiltered('api', ['security'], ['security.user']);

        $this->assertSame([1, 2], $result->pluck('id')->all());
    }

    private function permission(
        int $id,
        string $guard,
        string $module,
        string $model,
        bool $restrict = true
    ): object {
        return (object)[
            'id' => $id,
            'guard_name' => $guard,
            'module' => $module,
            'model' => $model,
            'restrict' => $restrict,
        ];
    }
}

final class PermissionFilterFakeUser
{
    use HasReadableUserPermissions;

    private Collection $permissions;

    public function __construct(array $permissions)
    {
        $this->permissions = collect($permissions);
    }

    public function effectivePermissions(?string $guard = null): Collection
    {
        $permissions = $this->permissions;

        return $guard ? $permissions->where('guard_name', $guard)->values() : $permissions;
    }
}

final class PermissionFilterFakeRole
{
    use HasReadableRolePermissions;

    public Collection $permissions;

    public function __construct(array $permissions)
    {
        $this->permissions = collect($permissions);
    }
}
