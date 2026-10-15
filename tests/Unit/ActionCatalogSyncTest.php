<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Ronu\RestGenericClass\Core\Support\Permissions\RoutePermissionRefresher;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelAuthorizationTestCase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelPermission;

final class ActionCatalogSyncTest extends ChannelAuthorizationTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('permission.table_names.role_has_permissions', 'channel_role_permissions');
        $app['config']->set('permission.table_names.model_has_permissions', 'channel_user_permissions');
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('channel_role_permissions', function ($table) {
            $table->unsignedInteger('role_id');
            $table->unsignedInteger('permission_id');
        });
    }

    public function testEightRelationRoutesProduceFiveActions(): void
    {
        $this->relationRoutes();
        Route::get('admin/crm/clients/{id}', fn () => 'ok')->name('admin.crm.clients.show');

        $refresher = new RoutePermissionRefresher();
        $rows = $refresher->refresh('api', false);

        $this->assertSame([
            'admin.crm.clients.pets.read',
            'admin.crm.clients.pets.manage',
            'admin.crm.clients.pets.add',
            'admin.crm.clients.pets.update',
            'admin.crm.clients.pets.delete',
            'admin.crm.clients.show',
        ], array_column($rows, 'permission'));
        $this->assertSame(6, ChannelPermission::count());
        $this->assertSame(['created' => 6, 'updated' => 0, 'unchanged' => 0], array_slice($refresher->report(), 0, 3));

        $read = ChannelPermission::where('name', 'admin.crm.clients.pets.read')->firstOrFail();
        $this->assertSame('admin/crm/clients/{id}/pets', $read->route, 'route comes from the first route');
        $this->assertSame('read', $read->type);
        $manage = collect($rows)->firstWhere('permission', 'admin.crm.clients.pets.manage');
        $this->assertSame(
            'admin/crm/clients/{id}/pets, admin/crm/clients/{id}/pets/{pet}, admin/crm/clients/{id}/pets/bulk',
            $manage['route'],
            'the dump lists every route of the action'
        );
    }

    public function testSecondSyncWritesNothing(): void
    {
        $this->relationRoutes();
        $refresher = new RoutePermissionRefresher();
        $refresher->refresh('api', false);

        $writes = 0;
        DB::listen(function ($query) use (&$writes) {
            $writes += preg_match('/^\s*(insert|update|delete)/i', $query->sql);
        });
        $refresher->refresh('api', false);

        $this->assertSame(0, $writes);
        $this->assertSame(['created' => 0, 'updated' => 0, 'unchanged' => 5], array_slice($refresher->report(), 0, 3));
    }

    public function testChangedRowsAreUpdatedKeepingTheirIds(): void
    {
        Route::get('admin/users', fn () => 'ok')->name('admin.users.index');
        $refresher = new RoutePermissionRefresher();
        $refresher->refresh('api', false);
        $id = ChannelPermission::firstOrFail()->id;
        ChannelPermission::query()->update(['route' => 'old', 'restrict' => null]);

        $refresher->refresh('api', false);

        $row = ChannelPermission::firstOrFail();
        $this->assertSame($id, $row->id);
        $this->assertSame('admin/users', $row->route);
        $this->assertSame(['created' => 0, 'updated' => 1, 'unchanged' => 0], array_slice($refresher->report(), 0, 3));
    }

    public function testActionSharedByRoutesIsGlobalOnlyIfEveryRouteIs(): void
    {
        config(['rest-generic-class.permissions.authorization.explicit_globals' => true]);
        $global = Route::get('admin/auth/a', fn () => 'ok')->name('admin.auth.a');
        $global->setAction([...$global->getAction(), 'permission_global' => true, 'permission_ability' => 'profile']);
        $restricted = Route::get('admin/auth/b', fn () => 'ok')->name('admin.auth.b');
        $restricted->setAction([...$restricted->getAction(), 'permission_ability' => 'profile']);

        (new RoutePermissionRefresher())->refresh('api', false);

        $this->assertTrue((bool) ChannelPermission::where('name', 'admin.auth.profile')->value('restrict'));
    }

    public function testPruneDeletesOnlyActionsNobodyHolds(): void
    {
        Route::get('admin/users', fn () => 'ok')->name('admin.users.index');
        $roleHeld = $this->permission('admin.old.role-held');
        $userHeld = $this->permission('admin.old.user-held');
        $free = $this->permission('admin.old.free');
        $web = $this->permission('admin.old.free', 'web');
        DB::table('channel_role_permissions')->insert(['role_id' => 1, 'permission_id' => $roleHeld->id]);
        DB::table('channel_user_permissions')->insert(['user_id' => 1, 'permission_id' => $userHeld->id]);

        $refresher = new RoutePermissionRefresher();
        $refresher->refresh('api', true, true);
        $this->assertSame(['admin.old.free'], $refresher->report()['pruned']);
        $this->assertSame(4, ChannelPermission::count(), 'dry run deletes nothing');

        $refresher->refresh('api', false);
        $this->assertSame([], $refresher->report()['pruned'], 'prune is opt-in');
        $this->assertNotNull($free->fresh());

        $refresher->refresh('api', false, true);
        $this->assertSame(['admin.old.free'], $refresher->report()['pruned']);
        $this->assertEqualsCanonicalizing(['admin.old.role-held', 'admin.old.user-held'], $refresher->report()['orphans']);
        $this->assertNull($free->fresh());
        $this->assertNotNull($roleHeld->fresh());
        $this->assertNotNull($userHeld->fresh());
        $this->assertNotNull($web->fresh(), 'other guards are untouched');
    }

    public function testTenThousandRoutesSyncInLessThanTenSeconds(): void
    {
        for ($i = 0; $i < 10000; $i++) {
            Route::get("admin/entity$i", fn () => 'ok')->name("admin.mod.entity$i.index");
        }
        $refresher = new RoutePermissionRefresher();

        $start = microtime(true);
        $refresher->refresh('api', false);
        $elapsed = microtime(true) - $start;

        $this->assertSame(10000, ChannelPermission::count());
        $this->assertLessThan(10, $elapsed, sprintf('first sync took %.2fs', $elapsed));

        $start = microtime(true);
        $refresher->refresh('api', false);
        $this->assertLessThan(10, microtime(true) - $start);
        $this->assertSame(10000, $refresher->report()['unchanged']);
    }

    /** O2M routes of clients → pets, as the generator declares them. */
    private function relationRoutes(): void
    {
        $base = 'admin/crm/clients/{id}/pets';
        foreach ([
            ['list', 'GET', $base, ['read', 'manage']],
            ['show', 'GET', "$base/{pet}", ['read', 'manage']],
            ['create', 'POST', $base, ['add', 'manage']],
            ['bulk-create', 'POST', "$base/bulk", ['add', 'manage']],
            ['update', 'PUT', "$base/{pet}", ['update', 'manage']],
            ['bulk-update', 'PUT', "$base/bulk", ['update', 'manage']],
            ['delete', 'DELETE', "$base/{pet}", ['delete', 'manage']],
            ['bulk-delete', 'DELETE', "$base/bulk", ['delete', 'manage']],
        ] as [$suffix, $verb, $uri, $abilities]) {
            $route = Route::match([$verb], $uri, fn () => 'ok')->name("admin.crm.clients.pets.$suffix");
            $route->setAction([...$route->getAction(), 'permission_ability' => $abilities, 'permission_requires' => ['^^show']]);
        }
    }
}
