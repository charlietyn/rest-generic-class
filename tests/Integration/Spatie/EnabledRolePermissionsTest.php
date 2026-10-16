<?php

namespace Ronu\RestGenericClass\Tests\Integration\Spatie;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ronu\RestGenericClass\Core\Models\SpatiePermission;
use Ronu\RestGenericClass\Core\Models\SpatieRole;

/**
 * SpatieRole::enabled_permissions(): the role's own permissions active now plus every non-restricted
 * permission of its guard, on a non-PostgreSQL connection (SQLite).
 */
final class EnabledRolePermissionsTest extends SpatieTestCase
{
    private SpatieRole $a;
    private SpatieRole $b;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('permission.pivot_models.role_has_permissions', RolePivotWithRange::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();
        Schema::table('permissions', fn ($t) => $t->boolean('restrict')->nullable()->default(true));
        Schema::table('role_has_permissions', function ($t) {
            $t->boolean('range')->nullable();
            $t->timestamp('start_range')->nullable();
            $t->timestamp('end_range')->nullable();
        });
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = SpatieRole::create(['name' => 'a']);
        $this->b = SpatieRole::create(['name' => 'b']);
        SpatiePermission::create(['name' => 'profile.show', 'restrict' => false]);
        $this->grant($this->a, 'users.delete');
        $this->grant($this->a, 'reports.export', now()->subDays(10), now()->subDay());   // expired
        $this->grant($this->a, 'audit.read', now()->subDay(), now()->addDay());          // current window
        $this->grant($this->a, 'billing.read', now()->addDay(), null);                   // not started
        $this->grant($this->b, 'reports.export');                                        // active for b only
    }

    public function testOwnActivePermissionsAndGlobalsOnSqlite(): void
    {
        $this->assertSame(['audit.read', 'profile.show', 'users.delete'], $this->names($this->a->enabled_permissions()->get()));
    }

    public function testAnotherRolesActiveRowDoesNotGrantAnExpiredOne(): void
    {
        $this->assertNotContains('reports.export', $this->names($this->a->enabled_permissions()->get()));
        $this->assertContains('reports.export', $this->names($this->b->enabled_permissions()->get()));
    }

    public function testLazyLoadingReturnsEachGlobalOnceForThisRole(): void
    {
        $rows = $this->a->enabled_permissions()->get();
        $this->assertCount(1, $rows->where('name', 'profile.show'));
        $this->assertSame([$this->a->id], $rows->pluck('pivot.role_id')->map(fn ($id) => (int) $id)->unique()->values()->all());
    }

    public function testEagerLoadingMatchesEachRoleWithItsOwnSet(): void
    {
        $roles = SpatieRole::with('enabled_permissions')->orderBy('id')->get();
        $this->assertSame(['audit.read', 'profile.show', 'users.delete'], $this->names($roles[0]->enabled_permissions));
        $this->assertSame(['profile.show', 'reports.export'], $this->names($roles[1]->enabled_permissions));
    }

    private function grant(SpatieRole $role, string $name, $start = null, $end = null): void
    {
        $permission = SpatiePermission::query()->firstOrCreate(['name' => $name, 'guard_name' => 'web'], ['restrict' => true]);
        DB::table('role_has_permissions')->insert([
            'role_id' => $role->id,
            'permission_id' => $permission->id,
            'range' => $start !== null || $end !== null ? true : null,
            'start_range' => $start,
            'end_range' => $end,
        ]);
    }

    private function names($permissions): array
    {
        return $permissions->pluck('name')->sort()->values()->all();
    }
}
