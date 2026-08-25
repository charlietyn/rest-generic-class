<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Database\MySqlConnection;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Ronu\RestGenericClass\Core\Helpers\Helper;

/**
 * Covers Helper::insertRow, the seam that lets a seeded row keep its explicit
 * primary key on a PostgreSQL column declared GENERATED ALWAYS AS IDENTITY.
 */
final class HelperInsertRowTest extends TestCase
{
    /** Records every statement the connection is asked to run. */
    private function pgsqlConnection(string $tablePrefix = ''): PostgresConnection
    {
        return new class(fn () => null, 'testing', $tablePrefix, ['driver' => 'pgsql']) extends PostgresConnection {
            /** @var array<int,array{query:string,bindings:array}> */
            public array $statements = [];

            public function insert($query, $bindings = [], $sequence = null)
            {
                $this->statements[] = ['query' => $query, 'bindings' => $bindings];

                return true;
            }
        };
    }

    private function mysqlConnection(): MySqlConnection
    {
        return new class(fn () => null, 'testing', '', ['driver' => 'mysql']) extends MySqlConnection {
            /** @var array<int,array{query:string,bindings:array}> */
            public array $statements = [];

            public function insert($query, $bindings = [], $sequence = null)
            {
                $this->statements[] = ['query' => $query, 'bindings' => $bindings];

                return true;
            }
        };
    }

    private function insertRow($connection, string $table, array $row): void
    {
        $method = new ReflectionMethod(Helper::class, 'insertRow');
        $method->invoke(null, $connection, $table, $row);
    }

    public function test_postgres_insert_carries_the_overriding_system_value_clause(): void
    {
        $connection = $this->pgsqlConnection();
        $row = ['id' => 7, 'name' => 'Servicios', 'active' => true];

        $this->insertRow($connection, 'service_categories', $row);

        $this->assertCount(1, $connection->statements);
        $this->assertSame(
            'insert into "service_categories" ("id", "name", "active") overriding system value values (?, ?, ?)',
            $connection->statements[0]['query']
        );
    }

    public function test_postgres_statement_only_differs_from_the_query_builder_by_the_clause(): void
    {
        $connection = $this->pgsqlConnection();
        $row = ['id' => 7, 'name' => 'Servicios', 'active' => true];

        // Same row, both paths: the raw statement and the builder Laravel would emit.
        $this->insertRow($connection, 'catalog.service_categories', $row);
        $connection->table('catalog.service_categories')->insert($row);

        [$patched, $builder] = $connection->statements;

        $this->assertSame(
            preg_replace('/\) values \(/', ') overriding system value values (', $builder['query'], 1),
            $patched['query'],
            'Only the clause may differ: table and column quoting must stay the grammar\'s own.'
        );
        // Schema-qualified names keep being quoted segment by segment.
        $this->assertStringContainsString('"catalog"."service_categories"', $patched['query']);
        $this->assertSame($builder['bindings'], $patched['bindings']);
    }

    public function test_postgres_values_travel_as_bindings_not_interpolated(): void
    {
        $connection = $this->pgsqlConnection();
        $row = ['id' => 7, 'name' => 'Servicios', 'active' => true];

        $this->insertRow($connection, 'service_categories', $row);

        $statement = $connection->statements[0];

        $this->assertSame([7, 'Servicios', true], $statement['bindings']);
        $this->assertSame(3, substr_count($statement['query'], '?'));
        $this->assertStringNotContainsString('Servicios', $statement['query']);
    }

    public function test_postgres_statement_honours_the_connection_table_prefix(): void
    {
        $connection = $this->pgsqlConnection('acme_');

        $this->insertRow($connection, 'service_categories', ['id' => 1]);

        $this->assertStringContainsString('"acme_service_categories"', $connection->statements[0]['query']);
    }

    public function test_other_drivers_keep_the_query_builder_insert(): void
    {
        $connection = $this->mysqlConnection();
        $row = ['id' => 7, 'name' => 'Servicios'];

        $this->insertRow($connection, 'service_categories', $row);

        $this->assertCount(1, $connection->statements);
        $statement = $connection->statements[0];

        $this->assertSame('insert into `service_categories` (`id`, `name`) values (?, ?)', $statement['query']);
        $this->assertStringNotContainsStringIgnoringCase('overriding system value', $statement['query']);
        $this->assertSame([7, 'Servicios'], $statement['bindings']);
    }

    public function test_explicit_key_survives_against_a_real_non_postgres_database(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('pdo_sqlite is not available.');
        }

        $connection = new SQLiteConnection(new PDO('sqlite::memory:'), 'main', '', ['driver' => 'sqlite']);
        $connection->statement('create table service_categories (id integer primary key, name text not null)');

        $this->insertRow($connection, 'service_categories', ['id' => 42, 'name' => 'Servicios']);

        $stored = $connection->table('service_categories')->where('id', 42)->first();

        $this->assertNotNull($stored);
        $this->assertSame('Servicios', $stored->name);
    }
}
