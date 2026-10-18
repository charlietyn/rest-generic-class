<?php

namespace Ronu\RestGenericClass\Tests\Integration\Spatie;

use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Ronu\RestGenericClass\Core\Models\SpatiePermission;
use Ronu\RestGenericClass\Core\Models\SpatieRole;
use Ronu\RestGenericClass\Core\Providers\RestGenericClassServiceProvider;
use Spatie\Permission\PermissionServiceProvider;

/**
 * Boots the real spatie/laravel-permission provider with the Ronu models configured
 * as in production, and builds the tables from the migration stub of the installed
 * Spatie version (so each CI row tests the schema of its own Spatie line).
 *
 * SQLite in memory by default; RGC_AGGREGATE_DRIVER=mysql|pgsql (and the other RGC_AGGREGATE_*
 * variables of the database CI job) run it against a real server. The database name must end in _test.
 */
abstract class SpatieTestCase extends TestCase
{
    private const TABLES = ['role_has_permissions', 'model_has_roles', 'model_has_permissions', 'roles', 'permissions', 'users'];

    protected bool $teams = false;

    protected function getPackageProviders($app): array
    {
        return [PermissionServiceProvider::class, RestGenericClassServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $driver = getenv('RGC_AGGREGATE_DRIVER') ?: 'sqlite';
        if ($driver !== 'sqlite') {
            $database = getenv('RGC_AGGREGATE_DATABASE') ?: 'rgc_aggregates_test';
            if (!str_ends_with($database, '_test')) {
                throw new \RuntimeException('Refusing to run the Spatie integration tests on a database not ending in _test.');
            }
            $app['config']->set('database.connections.integration', [
                'driver' => $driver,
                'host' => getenv('RGC_AGGREGATE_HOST') ?: '127.0.0.1',
                'port' => getenv('RGC_AGGREGATE_PORT') ?: ($driver === 'pgsql' ? 5432 : 3306),
                'database' => $database,
                'username' => getenv('RGC_AGGREGATE_USER') ?: 'rgc',
                'password' => getenv('RGC_AGGREGATE_PASSWORD') ?: '',
                'prefix' => '',
            ] + ($driver === 'mysql' ? ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'engine' => 'InnoDB'] : []));
        }
        $app['config']->set('database.default', $driver === 'sqlite' ? 'testing' : 'integration');
        $app['config']->set('auth.guards.api', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('permission.models.permission', SpatiePermission::class);
        $app['config']->set('permission.models.role', SpatieRole::class);
        $app['config']->set('permission.teams', $this->teams);
        $app['config']->set('permission.cache.store', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->dropTables();
        $stub = dirname((new \ReflectionClass(PermissionServiceProvider::class))->getFileName(), 2)
            . '/database/migrations/create_permission_tables.php.stub';
        (include $stub)->up();
        $this->beforeApplicationDestroyed(fn () => $this->dropTables());

        // Eloquent caches each model's columns statically; the teams schema differs between test cases.
        (new \ReflectionProperty(\Illuminate\Database\Eloquent\Model::class, 'guardableColumns'))->setValue(null, []);
    }

    private function dropTables(): void
    {
        Schema::disableForeignKeyConstraints();
        foreach (self::TABLES as $table) {
            Schema::dropIfExists($table);
        }
        Schema::enableForeignKeyConstraints();
    }
}
