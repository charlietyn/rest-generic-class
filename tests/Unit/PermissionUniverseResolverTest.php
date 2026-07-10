<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionUniverseResolver;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\PermissionUniverseItem;

final class PermissionUniverseResolverTest extends TestCase
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

            Capsule::schema()->create('permission_universe_items', function ($table) {
                $table->increments('id');
                $table->string('name');
                $table->string('guard_name');
                $table->string('module')->nullable();
                $table->string('model')->nullable();
            });

            PermissionUniverseItem::insert([
                ['id' => 1, 'name' => 'security.user.index', 'guard_name' => 'api', 'module' => 'security', 'model' => 'User'],
                ['id' => 2, 'name' => 'security.role.index', 'guard_name' => 'api', 'module' => 'security', 'model' => 'Role'],
                ['id' => 3, 'name' => 'billing.invoice.index', 'guard_name' => 'api', 'module' => 'billing', 'model' => 'Invoice'],
                ['id' => 4, 'name' => 'security.user.index', 'guard_name' => 'web', 'module' => 'security', 'model' => 'User'],
                ['id' => 5, 'name' => 'reports.user.index', 'guard_name' => 'api', 'module' => 'reports', 'model' => 'User'],
            ]);

            self::$booted = true;
        }

        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'permission' => [
                'models' => [
                    'permission' => PermissionUniverseItem::class,
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

    public function testUniverseReturnsEverythingWithoutFilters(): void
    {
        $ids = $this->resolver()->universe()->pluck('id')->sort()->values()->all();

        $this->assertSame([1, 2, 3, 4, 5], $ids);
    }

    public function testUniverseFiltersByGuard(): void
    {
        $ids = $this->resolver()->universe('api')->pluck('id')->sort()->values()->all();

        $this->assertSame([1, 2, 3, 5], $ids);
    }

    public function testUniverseFiltersByModules(): void
    {
        $ids = $this->resolver()->universe('api', ['security'])->pluck('id')->sort()->values()->all();

        $this->assertSame([1, 2], $ids);
    }

    public function testUniverseFiltersByBareEntityAcrossModules(): void
    {
        // "user" alone must match every module whose model is User.
        $ids = $this->resolver()->universe('api', null, ['user'])->pluck('id')->sort()->values()->all();

        $this->assertSame([1, 5], $ids);
    }

    public function testUniverseFiltersByQualifiedModuleEntity(): void
    {
        // "security.user" must pin both module and model, excluding reports.user.
        $ids = $this->resolver()->universe('api', null, ['security.user'])->pluck('id')->sort()->values()->all();

        $this->assertSame([1], $ids);
    }

    public function testUniverseCombinesMultipleEntities(): void
    {
        $ids = $this->resolver()
            ->universe('api', null, ['security.user', 'billing.invoice'])
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1, 3], $ids);
    }

    public function testApplyFiltersIsCaseInsensitiveOnEntities(): void
    {
        $ids = $this->resolver()
            ->applyFilters(PermissionUniverseItem::query(), 'api', null, ['SECURITY.USER'])
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        $this->assertSame([1], $ids);
    }

    private function resolver(): PermissionUniverseResolver
    {
        return new PermissionUniverseResolver();
    }
}
