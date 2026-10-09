<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Support\Facades\Route;
use Ronu\RestGenericClass\Core\Support\Permissions\RoutePermissionRefresher;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelAuthorizationTestCase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelPermission;

final class RoutePermissionRefresherTest extends ChannelAuthorizationTestCase
{
    public function testSameNameWithDifferentGuardsIsNotOverwrittenAndSyncIsIdempotent(): void
    {
        $web = $this->permission('admin.users.index', 'web');
        Route::get('admin/users', fn () => 'ok')->name('admin.users.index');
        Route::get('client/users', fn () => 'ok')->name('client.users.index');
        $refresher = new RoutePermissionRefresher();
        $rows = $refresher->refresh('api', false);
        $this->assertCount(2, $rows);
        $this->assertSame('web', $web->fresh()->guard_name);
        $this->assertNull($web->fresh()->route);
        $this->assertSame(3, ChannelPermission::count());
        $refresher->refresh('api', false);
        $this->assertSame(3, ChannelPermission::count());
        $api = ChannelPermission::where('guard_name', 'api')->where('name', 'admin.users.index')->firstOrFail();
        $this->assertSame('admin/users', $api->route);
        $this->assertFalse((bool) $api->restrict);
    }

    public function testDryRunDoesNotPersistOrForgetCache(): void
    {
        Route::get('admin/auth/permissions', fn () => 'ok')->name('admin.auth.permissions');
        cache()->put('spatie.permission.cache', 'unchanged');
        $rows = (new RoutePermissionRefresher())->refresh('api', true);
        $this->assertSame('admin.auth.permissions', $rows[0]['permission']);
        $this->assertSame(0, ChannelPermission::count());
        $this->assertSame('unchanged', cache()->get('spatie.permission.cache'));
    }
}
