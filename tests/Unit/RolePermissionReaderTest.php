<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Support\Permissions\RolePermissionReader;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\RolePermissionItem;

final class RolePermissionReaderTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$booted) {
            $capsule = new Capsule();
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            $capsule->setEventDispatcher(new Dispatcher(new Container()));
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            Capsule::schema()->create('role_permission_items', function ($table) {
                $table->increments('id');
                $table->string('name');
                $table->string('guard_name');
                $table->boolean('restrict')->default(true);
            });

            RolePermissionItem::insert([
                ['id' => 10, 'name' => 'global.audit.view', 'guard_name' => 'api', 'restrict' => false],
                ['id' => 11, 'name' => 'global.health.view', 'guard_name' => 'api', 'restrict' => false],
                ['id' => 12, 'name' => 'security.user.index', 'guard_name' => 'api', 'restrict' => true],
                ['id' => 13, 'name' => 'global.audit.view', 'guard_name' => 'web', 'restrict' => false],
            ]);

            self::$booted = true;
        }

        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'permission' => [
                'models' => [
                    'permission' => RolePermissionItem::class,
                ],
            ],
        ]));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('config');
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('config');

        parent::tearDown();
    }

    public function testMergeDedupesOwnAndGlobalById(): void
    {
        $reader = new RolePermissionReader();

        $result = $reader->merge(
            collect([$this->perm(1), $this->perm(10)]),
            collect([$this->perm(10), $this->perm(11)])
        );

        $this->assertSame([1, 10, 11], $result->pluck('id')->all());
    }

    public function testAllPermissionsMergesOwnWithGlobalUnrestrictedOfSameGuard(): void
    {
        $reader = new RolePermissionReader();

        $role = new RolePermissionReaderFakeRole('api', [
            $this->perm(1, 'security.role.index'),
        ]);

        $result = $reader->allPermissions($role);

        // Own permission (1) plus api global unrestricted (10, 11); excludes
        // restricted (12) and web-guard global (13).
        $this->assertSame([1, 10, 11], $result->pluck('id')->sort()->values()->all());
        // Side effect: the role's relation was hydrated with the merged set.
        $this->assertSame([1, 10, 11], $role->permissions->pluck('id')->sort()->values()->all());
    }

    private function perm(int $id, string $name = 'perm'): object
    {
        return (object)['id' => $id, 'name' => $name];
    }
}

final class RolePermissionReaderFakeRole
{
    public Collection $permissions;

    public function __construct(public string $guard_name, array $permissions)
    {
        $this->permissions = collect($permissions);
    }

    public function setRelation(string $relation, $value): void
    {
        if ($relation === 'permissions') {
            $this->permissions = $value;
        }
    }
}
