<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Ronu\RestGenericClass\Core\Providers\RestGenericClassServiceProvider;
use Ronu\RestGenericClass\Core\Traits\HasReadableUserPermissions;

abstract class ChannelAuthorizationTestCase extends TestCase
{
    private array $paginationResolvers = [];

    protected function getPackageProviders($app): array
    {
        return [RestGenericClassServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('permission.models.permission', ChannelPermission::class);
        $app['config']->set('rest-generic-class.permissions.authorization', [
            'route_prefixes' => ['admin', 'client'],
            'naming' => 'route_name',
            'on_unresolved' => 'deny',
            'register_gate' => true,
            'gate_guard' => 'api',
        ]);
    }

    protected function setUp(): void
    {
        // Testbench installs static callbacks bound to its application. Restore
        // them afterwards so the standalone Capsule tests remain independent.
        foreach ([\Illuminate\Pagination\AbstractPaginator::class, \Illuminate\Pagination\AbstractCursorPaginator::class] as $class) {
            foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                if (str_ends_with($property->getName(), 'Resolver')) {
                    $this->paginationResolvers[] = [$property, $property->getValue()];
                }
            }
        }
        parent::setUp();
        Schema::create('channel_permissions', function ($table) {
            $table->increments('id');
            foreach (['name', 'guard_name', 'module', 'route', 'type', 'model', 'action'] as $column) {
                $table->string($column)->nullable();
            }
            $table->boolean('restrict')->nullable()->default(true);
            $table->unique(['name', 'guard_name']);
        });
        Schema::create('channel_user_permissions', function ($table) {
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('permission_id');
        });
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            foreach ($this->paginationResolvers as [$property, $value]) {
                $property->setValue(null, $value);
            }
        }
    }

    protected function permission(string $name, string $guard = 'api', bool $restrict = true): ChannelPermission
    {
        return ChannelPermission::create(['name' => $name, 'guard_name' => $guard, 'restrict' => $restrict]);
    }
}

class ChannelPermission extends Model
{
    protected $table = 'channel_permissions';
    protected $guarded = [];
    public $timestamps = false;

    public function scopeNotRestricted($query)
    {
        return $query->where(fn ($query) => $query->where('restrict', false)->orWhereNull('restrict'));
    }
}

class ChannelUser extends User
{
    use HasReadableUserPermissions;

    public function enabled_permissions(): BelongsToMany
    {
        return $this->belongsToMany(ChannelPermission::class, 'channel_user_permissions', 'user_id', 'permission_id');
    }

    public function getEnabledPermissionsViaRoles(): Collection
    {
        return collect();
    }
}
