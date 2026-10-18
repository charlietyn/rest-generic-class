<?php

namespace Ronu\RestGenericClass\Tests\Integration\Spatie;

use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ronu\RestGenericClass\Core\Models\SpatiePermission;
use Ronu\RestGenericClass\Core\Models\SpatieRole;
use Ronu\RestGenericClass\Core\Support\Permissions\Contracts\ProvidesRoles;
use Ronu\RestGenericClass\Core\Support\Permissions\RolePermissionAssignmentService;
use Ronu\RestGenericClass\Core\Traits\HasReadableUserPermissions;

/**
 * A user composed as the generated applications compose it: Ronu's readers and its own roles
 * relation, and deliberately NOT Spatie's HasRoles/HasPermissions — that trait registers
 * Spatie's Gate::before, which would bypass the channel authorization. Direct assignment used
 * to call givePermissionTo() on it and fail with BadMethodCallException.
 */
class PlainUser extends User implements ProvidesRoles
{
    use HasReadableUserPermissions;

    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;

    public function roles(): MorphToMany
    {
        return $this->morphToMany(SpatieRole::class, 'model', 'model_has_roles', 'model_id', 'role_id');
    }
}

/** The pivot helper the generated applications configure; time windows are out of scope here. */
class AlwaysActivePivot
{
    public static function applyActiveToRelation($relation, $now): void
    {
    }
}

final class DirectUserPermissionAssignmentTest extends SpatieTestCase
{
    private PlainUser $user;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('permission.pivot_models.model_has_permissions', AlwaysActivePivot::class);
        $app['config']->set('permission.pivot_models.role_has_permissions', AlwaysActivePivot::class);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', fn ($t) => [$t->increments('id'), $t->string('name')]);
        $this->user = PlainUser::query()->create(['name' => 'ana']);
        foreach (['admin.users.index', 'admin.users.show', 'admin.users.store'] as $name) {
            SpatiePermission::findOrCreate($name, 'api');
        }
    }

    private function perms(string ...$names)
    {
        return SpatiePermission::query()->whereIn('name', $names)->get();
    }

    private function effective(): array
    {
        return $this->user->fresh()->effectivePermissions('api')->pluck('name')->sort()->values()->all();
    }

    public function testTheUserHasNoSpatieApiSoTheDirectPathIsTheOneUnderTest(): void
    {
        $this->assertFalse(method_exists($this->user, 'givePermissionTo'));
    }

    public function testAddWritesWhatTheReaderResolves(): void
    {
        $service = new RolePermissionAssignmentService();
        $service->applyToUser($this->user, 'ADD', $this->perms('admin.users.index'));
        $service->applyToUser($this->user, 'ADD', $this->perms('admin.users.index', 'admin.users.show'));

        $this->assertSame(['admin.users.index', 'admin.users.show'], $this->effective());
        $this->assertSame(2, DB::table('model_has_permissions')->count(), 'ADD twice does not duplicate');
        $this->assertSame(PlainUser::class, DB::table('model_has_permissions')->value('model_type'));
    }

    public function testSyncReplacesAndRevokeRemovesOnlyTheGivenOnes(): void
    {
        $service = new RolePermissionAssignmentService();
        $service->applyToUser($this->user, 'ADD', $this->perms('admin.users.index', 'admin.users.show'));
        $service->applyToUser($this->user, 'SYNC', $this->perms('admin.users.show', 'admin.users.store'));
        $this->assertSame(['admin.users.show', 'admin.users.store'], $this->effective());

        $service->applyToUser($this->user, 'REVOKE', $this->perms('admin.users.store'));
        $this->assertSame(['admin.users.show'], $this->effective());
    }

    public function testPivotAttributesAreKept(): void
    {
        Schema::table('model_has_permissions', fn ($t) => $t->string('range')->nullable());
        (new RolePermissionAssignmentService())->applyToUser(
            $this->user,
            'ADD',
            $this->perms('admin.users.index'),
            ['range' => 'global'],
        );

        $this->assertSame('global', DB::table('model_has_permissions')->value('range'));
    }

    public function testTheReadersAnswerWithoutSpatiesApi(): void
    {
        (new RolePermissionAssignmentService())->applyToUser($this->user, 'ADD', $this->perms('admin.users.index'));

        $this->assertSame(['admin.users.index'], $this->user->fresh()->directPermissions()->pluck('name')->all());
        $this->assertSame([], $this->user->fresh()->rolePermissions()->all());
    }
}
