<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Support\Permissions\RoleInputResolver;
use Ronu\RestGenericClass\Core\Support\Permissions\TargetPermissionResolver;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\TargetPermissionItem;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\TargetRoleItem;

final class TargetPermissionResolverTest extends TestCase
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

            Capsule::schema()->create('target_permission_items', function ($table) {
                $table->increments('id');
                $table->string('name');
                $table->string('guard_name');
                $table->string('module')->nullable();
            });

            Capsule::schema()->create('target_role_items', function ($table) {
                $table->increments('id');
                $table->string('name');
                $table->string('guard_name');
            });

            TargetPermissionItem::insert([
                ['id' => 1, 'name' => 'security.user.index', 'guard_name' => 'api', 'module' => 'security'],
                ['id' => 2, 'name' => 'security.user.show', 'guard_name' => 'api', 'module' => 'security'],
                ['id' => 3, 'name' => 'billing.invoice.index', 'guard_name' => 'api', 'module' => 'billing'],
                ['id' => 4, 'name' => 'security.user.index', 'guard_name' => 'web', 'module' => 'security'],
            ]);

            TargetRoleItem::insert([
                ['id' => 10, 'name' => 'admin', 'guard_name' => 'api'],
                ['id' => 11, 'name' => 'editor', 'guard_name' => 'api'],
                ['id' => 12, 'name' => 'admin', 'guard_name' => 'web'],
            ]);

            self::$booted = true;
        }

        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'permission' => [
                'models' => [
                    'permission' => TargetPermissionItem::class,
                    'role' => TargetRoleItem::class,
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

    public function testResolveByExplicitNamesKeepsOnlyExistingOfGuard(): void
    {
        [$perms, $created, $usedDefaultAll] = (new TargetPermissionResolver())->resolve(
            'api',
            ['security.user.index', 'security.user.index', 'does.not.exist'],
            null,
            null,
            null,
            null
        );

        $this->assertSame([1], $perms->pluck('id')->all());
        $this->assertSame([], $created);
        $this->assertFalse($usedDefaultAll);
    }

    public function testResolveByPrefixUsesLikeAndGuard(): void
    {
        [$perms] = (new TargetPermissionResolver())->resolve('api', null, 'security.', null, null, null);

        $this->assertSame([1, 2], $perms->pluck('id')->sort()->values()->all());
    }

    public function testResolveByModulesFiltersGuardAndModule(): void
    {
        [$perms] = (new TargetPermissionResolver())->resolve('api', null, null, null, ['security'], null);

        $this->assertSame([1, 2], $perms->pluck('id')->sort()->values()->all());
    }

    public function testResolveWithNoSourcesFallsBackToAllOfGuard(): void
    {
        [$perms, $created, $usedDefaultAll] = (new TargetPermissionResolver())->resolve('api', null, null, null, null, null);

        $this->assertSame([1, 2, 3], $perms->pluck('id')->sort()->values()->all());
        $this->assertTrue($usedDefaultAll);
    }

    public function testRoleInputResolverResolvesByNameGuardAware(): void
    {
        $roles = (new RoleInputResolver())->resolve(['admin', 'editor', 'admin'], 'name', 'api');

        $this->assertSame([10, 11], $roles->pluck('id')->sort()->values()->all());
    }

    public function testRoleInputResolverResolvesById(): void
    {
        $roles = (new RoleInputResolver())->resolve([10, 12], 'id', 'api');

        // 12 is web-guard, so only 10 survives the guard filter.
        $this->assertSame([10], $roles->pluck('id')->all());
    }
}
