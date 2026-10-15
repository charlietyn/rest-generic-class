<?php

namespace Ronu\RestGenericClass\Tests\Integration\Spatie;

use Orchestra\Testbench\TestCase;
use Ronu\RestGenericClass\Core\Models\SpatiePermission;
use Ronu\RestGenericClass\Core\Models\SpatieRole;
use Ronu\RestGenericClass\Core\Providers\RestGenericClassServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Boots the real spatie/laravel-permission provider with the Ronu models configured
 * as in production, and builds the tables from the migration stub of the installed
 * Spatie version (so each CI row tests the schema of its own Spatie line).
 */
abstract class SpatieTestCase extends TestCase
{
    protected bool $teams = false;

    protected function getPackageProviders($app): array
    {
        return [PermissionServiceProvider::class, RestGenericClassServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.guards.api', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('permission.models.permission', SpatiePermission::class);
        $app['config']->set('permission.models.role', SpatieRole::class);
        $app['config']->set('permission.teams', $this->teams);
        $app['config']->set('permission.cache.store', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $stub = dirname((new \ReflectionClass(PermissionServiceProvider::class))->getFileName(), 2)
            . '/database/migrations/create_permission_tables.php.stub';
        (include $stub)->up();

        // Eloquent caches each model's columns statically; the teams schema differs between test cases.
        (new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, 'guardableColumns'))->setValue(null, []);
    }
}
