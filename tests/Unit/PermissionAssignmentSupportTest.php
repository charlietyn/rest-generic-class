<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionListNormalizer;
use Ronu\RestGenericClass\Core\Support\Permissions\RolePermissionAssignmentService;

final class PermissionAssignmentSupportTest extends TestCase
{
    public function testNormalizerSplitsTrimsAndDedupes(): void
    {
        $normalizer = new PermissionListNormalizer();

        $this->assertSame(
            ['security', 'billing', 'reports'],
            $normalizer->normalize(['security, billing', 'billing', ' reports '])
        );
        $this->assertSame([], $normalizer->normalize(['', ' ', null]));
    }

    public function testResolveModePrefersExplicitModeOverFlags(): void
    {
        $service = new RolePermissionAssignmentService();

        $this->assertSame('SYNC', $service->resolveMode(['mode' => 'sync', 'revoke' => true]));
        $this->assertSame('REVOKE', $service->resolveMode(['mode' => 'REVOKE']));
        $this->assertSame('ADD', $service->resolveMode([]));
    }

    public function testResolveModeFallsBackToLegacyFlags(): void
    {
        $service = new RolePermissionAssignmentService();

        $this->assertSame('SYNC', $service->resolveMode(['sync' => true]));
        $this->assertSame('REVOKE', $service->resolveMode(['revoke' => true]));
    }

    public function testResolveModeRejectsSyncAndRevokeTogether(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new RolePermissionAssignmentService())->resolveMode(['sync' => true, 'revoke' => true]);
    }

    public function testRowsAreNameSortedAndNormalized(): void
    {
        $service = new RolePermissionAssignmentService();

        $rows = $service->rows(collect([
            (object)['name' => 'security.user.show', 'module' => 'security', 'guard_name' => 'api'],
            (object)['name' => 'security.user.index', 'module' => null, 'guard_name' => 'api'],
        ]), 'ADD');

        $this->assertSame([
            ['permission' => 'security.user.index', 'module' => '-', 'guard' => 'api', 'action' => 'ADD'],
            ['permission' => 'security.user.show', 'module' => 'security', 'guard' => 'api', 'action' => 'ADD'],
        ], $rows);
    }

    public function testApplyToRoleDispatchesToSpatieVerb(): void
    {
        $service = new RolePermissionAssignmentService();
        $perms = collect([(object)['name' => 'p']]);

        foreach (['ADD' => 'give', 'SYNC' => 'sync', 'REVOKE' => 'revoke'] as $mode => $expected) {
            $role = new AssignmentFakeRole();
            $service->applyToRole($role, $mode, $perms);
            $this->assertSame($expected, $role->lastCall);
        }
    }

    public function testApplyToUserWithoutPivotUsesSpatieVerbs(): void
    {
        $service = new RolePermissionAssignmentService();
        $perms = collect([(object)['name' => 'p']]);

        $user = new AssignmentFakeUser();
        $service->applyToUser($user, 'ADD', $perms);
        $this->assertSame('give', $user->lastCall);

        $service->applyToUser($user, 'SYNC', $perms);
        $this->assertSame('sync', $user->lastCall);

        $service->applyToUser($user, 'REVOKE', $perms);
        $this->assertSame('revoke', $user->lastCall);
    }

    public function testApplyToUserWithPivotWritesPivotMap(): void
    {
        $service = new RolePermissionAssignmentService();
        $perms = collect([
            new AssignmentFakePermission(5),
            new AssignmentFakePermission(9),
        ]);
        $pivot = ['expires_at' => '2026-12-31'];

        $user = new AssignmentFakeUser();
        $service->applyToUser($user, 'ADD', $perms, $pivot);

        $this->assertSame('syncWithoutDetaching', $user->permissionsRelation->lastCall);
        $this->assertSame([5 => $pivot, 9 => $pivot], $user->permissionsRelation->lastMap);

        $service->applyToUser($user, 'SYNC', $perms, $pivot);
        $this->assertSame('sync', $user->permissionsRelation->lastCall);
        $this->assertSame([5 => $pivot, 9 => $pivot], $user->permissionsRelation->lastMap);
    }
}

final class AssignmentFakeRole
{
    public ?string $lastCall = null;

    public function givePermissionTo($perms): void
    {
        $this->lastCall = 'give';
    }

    public function syncPermissions($perms): void
    {
        $this->lastCall = 'sync';
    }

    public function revokePermissionTo($perms): void
    {
        $this->lastCall = 'revoke';
    }
}

final class AssignmentFakeUser
{
    public ?string $lastCall = null;
    public AssignmentFakePermissionsRelation $permissionsRelation;

    public function __construct()
    {
        $this->permissionsRelation = new AssignmentFakePermissionsRelation();
    }

    public function givePermissionTo($perms): void
    {
        $this->lastCall = 'give';
    }

    public function syncPermissions($perms): void
    {
        $this->lastCall = 'sync';
    }

    public function revokePermissionTo($perms): void
    {
        $this->lastCall = 'revoke';
    }

    public function permissions(): AssignmentFakePermissionsRelation
    {
        return $this->permissionsRelation;
    }
}

final class AssignmentFakePermissionsRelation
{
    public ?string $lastCall = null;
    public array $lastMap = [];

    public function sync(array $map): void
    {
        $this->lastCall = 'sync';
        $this->lastMap = $map;
    }

    public function syncWithoutDetaching(array $map): void
    {
        $this->lastCall = 'syncWithoutDetaching';
        $this->lastMap = $map;
    }
}

final class AssignmentFakePermission
{
    public function __construct(private int $key)
    {
    }

    public function getKey(): int
    {
        return $this->key;
    }
}
