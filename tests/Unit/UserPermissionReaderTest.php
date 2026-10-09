<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Support\Collection;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Support\Permissions\UserPermissionReader;

final class UserPermissionReaderTest extends TestCase
{
    private Container $previousContainer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousContainer = Container::getInstance();
        $container = new Container();
        $container->instance('config', new Repository());
        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance($this->previousContainer);
        parent::tearDown();
    }

    public function testMergeUnionsDirectAndRolePermissionsDedupedById(): void
    {
        $reader = new UserPermissionReader();

        $result = $reader->merge(
            collect([
                $this->permission(1, 'api'),
                $this->permission(2, 'api'),
            ]),
            collect([
                $this->permission(2, 'api'),
                $this->permission(3, 'api'),
            ])
        );

        $this->assertSame([1, 2, 3], $result->pluck('id')->all());
    }

    public function testMergeFiltersByGuard(): void
    {
        $reader = new UserPermissionReader();

        $result = $reader->merge(
            collect([
                $this->permission(1, 'api'),
                $this->permission(2, 'web'),
            ]),
            collect([
                $this->permission(3, 'api'),
                $this->permission(4, 'web'),
            ]),
            'api'
        );

        $this->assertSame([1, 3], $result->pluck('id')->all());
    }

    public function testEffectivePermissionsReadsUserDirectAndViaRoles(): void
    {
        $reader = new UserPermissionReader();

        $user = new UserPermissionReaderFakeUser(
            direct: [$this->permission(1, 'api'), $this->permission(2, 'web')],
            viaRoles: [$this->permission(2, 'web'), $this->permission(3, 'api')]
        );

        $all = $reader->effectivePermissions($user);
        $this->assertSame([1, 2, 3], $all->pluck('id')->all());

        $apiOnly = $reader->effectivePermissions($user, 'api');
        $this->assertSame([1, 3], $apiOnly->pluck('id')->all());
    }

    private function permission(int $id, string $guard): object
    {
        return (object)['id' => $id, 'guard_name' => $guard];
    }
}

final class UserPermissionReaderFakeUser
{
    private Collection $direct;
    private Collection $viaRoles;

    public function __construct(array $direct, array $viaRoles)
    {
        $this->direct = collect($direct);
        $this->viaRoles = collect($viaRoles);
    }

    public function enabled_permissions(): object
    {
        $direct = $this->direct;

        return new class($direct) {
            public function __construct(private Collection $direct)
            {
            }

            public function get(): Collection
            {
                return $this->direct;
            }
        };
    }

    public function getEnabledPermissionsViaRoles(): Collection
    {
        return $this->viaRoles;
    }
}
