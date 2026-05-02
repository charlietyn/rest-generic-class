<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Traits\HasReadableUserPermissions;

final class AuthenticatedPermissionsPayloadTest extends TestCase
{
    public function testItReturnsTheFlatPayloadByDefault(): void
    {
        $user = new PermissionPayloadFakeUser([
            $this->permission(1, 'security.user.index', 'security', 'api'),
            $this->permission(2, 'security.user.show', 'security', 'api'),
        ]);
        $request = Request::create('/permissions', 'GET');

        $payload = $user->permissionsPayload($request, ['user' => ['id' => 10]]);

        $this->assertSame(['id' => 10], $payload['user']);
        $this->assertSame(2, $payload['count']);
        $this->assertSame([
            ['id' => 1, 'name' => 'security.user.index', 'module' => 'security', 'guard' => 'api'],
            ['id' => 2, 'name' => 'security.user.show', 'module' => 'security', 'guard' => 'api'],
        ], $payload['permissions']);
        $this->assertArrayNotHasKey('stats', $payload);
    }

    public function testItReturnsCompressedPayloadOnlyWhenRequested(): void
    {
        $user = new PermissionPayloadFakeUser([]);
        $request = Request::create('/permissions', 'GET', [
            'compress' => 'true',
            'expand' => 'true',
            'compress_global' => 'true',
            'guard' => 'api',
            'modules' => ['security'],
            'entities' => 'security.user,security.role',
        ]);

        $payload = $user->permissionsPayload($request, ['user' => ['id' => 10]]);

        $this->assertSame(['id' => 10], $payload['user']);
        $this->assertSame('api', $payload['guard']);
        $this->assertSame(['security.*'], $payload['permissions']);
        $this->assertSame([
            'original_count' => 3,
            'compressed_count' => 1,
            'compression_ratio' => 3.0,
        ], $payload['stats']);
        $this->assertSame([
            'guard' => 'api',
            'modules' => ['security'],
            'entities' => ['security.user', 'security.role'],
            'options' => [
                'module_wildcard' => true,
                'table_wildcard' => true,
                'global_wildcard' => true,
                'include_expanded' => true,
            ],
        ], $user->lastCompressedCall);
    }

    private function permission(int $id, string $name, string $module, string $guard): object
    {
        return (object)[
            'id' => $id,
            'name' => $name,
            'module' => $module,
            'guard_name' => $guard,
        ];
    }
}

final class PermissionPayloadFakeUser
{
    use HasReadableUserPermissions;

    public array $lastCompressedCall = [];

    private Collection $permissions;

    public function __construct(array $permissions)
    {
        $this->permissions = collect($permissions);
    }

    public function permissionsFiltered(?string $guard = null, ?array $modules = null, ?array $entities = null): Collection
    {
        return $this->permissions;
    }

    public function effectivePermissionsCompressed(
        ?string $guard = null,
        ?array $modules = null,
        ?array $entities = null,
        array $compressOptions = []
    ): array {
        $this->lastCompressedCall = [
            'guard' => $guard,
            'modules' => $modules,
            'entities' => $entities,
            'options' => $compressOptions,
        ];

        return [
            'permissions' => ['security.*'],
            'stats' => [
                'original_count' => 3,
                'compressed_count' => 1,
                'compression_ratio' => 3.0,
            ],
            'expanded' => [
                'security.user.index',
                'security.user.show',
                'security.role.index',
            ],
        ];
    }
}
