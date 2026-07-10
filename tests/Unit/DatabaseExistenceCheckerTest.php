<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Validation\DatabaseExistenceChecker;

final class DatabaseExistenceCheckerTest extends TestCase
{
    private const CONN = 'validation_checker';
    private static ?Capsule $capsule = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$capsule === null) {
            $capsule = new Capsule(new Container());
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ], self::CONN);
            $capsule->setEventDispatcher(new Dispatcher(new Container()));
            $capsule->bootEloquent();

            $schema = $capsule->getConnection(self::CONN)->getSchemaBuilder();
            $schema->create('validation_checker_items', function ($table) {
                $table->increments('id');
                $table->string('status');
                $table->unsignedInteger('tenant_id');
                $table->date('happened_at');
                $table->timestamp('deleted_at')->nullable();
            });

            self::$capsule = $capsule;
        }

        $container = Container::getInstance();
        $container->instance('db', self::$capsule->getDatabaseManager());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('db');

        $this->connection()->table('validation_checker_items')->delete();
        $this->connection()->table('validation_checker_items')->insert([
            ['id' => 1, 'status' => 'active', 'tenant_id' => 1, 'happened_at' => '2026-01-05', 'deleted_at' => null],
            ['id' => 2, 'status' => 'pending', 'tenant_id' => 1, 'happened_at' => '2026-01-10', 'deleted_at' => null],
            ['id' => 3, 'status' => 'active', 'tenant_id' => 2, 'happened_at' => '2026-02-01', 'deleted_at' => null],
            ['id' => 4, 'status' => 'active', 'tenant_id' => 1, 'happened_at' => '2026-01-20', 'deleted_at' => '2026-01-21 00:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('db');

        parent::tearDown();
    }

    public function testNormalizeIdsRemovesNullsEmptyValuesAndDuplicates(): void
    {
        $this->assertSame([1, '2'], $this->checker()->normalizeIds([1, null, '', 1, '2']));
    }

    public function testGetValidIdsFromTableAppliesScalarAndArrayConditions(): void
    {
        $ids = $this->checker()->getValidIdsFromTable('validation_checker_items', 'id', [
            'tenant_id' => 1,
            'status' => ['active', 'pending'],
        ]);

        sort($ids);

        $this->assertSame([1, 2, 4], $ids);
    }

    public function testIdsExistNotDeletedHonorsSoftDeleteColumn(): void
    {
        $this->assertTrue($this->checker()->idsExistNotDeleted([1, 2], 'validation_checker_items', 'id', [
            'tenant_id' => 1,
        ], 'deleted_at'));

        $this->assertFalse($this->checker()->idsExistNotDeleted([4], 'validation_checker_items', 'id', [
            'tenant_id' => 1,
        ], 'deleted_at'));

        $this->assertTrue($this->checker()->idsExistNotDeleted([4], 'validation_checker_items', 'id', [
            'tenant_id' => 1,
        ], null));
    }

    public function testIdsExistWithAnyStatusAndDateRangeReuseCommonConditions(): void
    {
        $this->assertTrue($this->checker()->idsExistWithAnyStatus(
            [1, 2],
            'validation_checker_items',
            ['active', 'pending'],
            'status',
            ['tenant_id' => 1]
        ));

        $this->assertFalse($this->checker()->idsExistWithAnyStatus(
            [3],
            'validation_checker_items',
            ['active'],
            'status',
            ['tenant_id' => 1]
        ));

        $this->assertTrue($this->checker()->idsExistWithDateRange(
            [1, 2],
            'validation_checker_items',
            'happened_at',
            '2026-01-01',
            '2026-01-31',
            ['tenant_id' => 1]
        ));

        $this->assertFalse($this->checker()->idsExistWithDateRange(
            [3],
            'validation_checker_items',
            'happened_at',
            '2026-01-01',
            '2026-01-31',
            ['tenant_id' => 1]
        ));
    }

    public function testIdsExistWithCustomQueryRequiresAllIdsToMatch(): void
    {
        $callback = fn ($query) => $query
            ->from('validation_checker_items')
            ->where('tenant_id', 1);

        $this->assertTrue($this->checker()->idsExistWithCustomQuery([1], $callback, 'id', [
            'status' => 'active',
        ]));

        $this->assertFalse($this->checker()->idsExistWithCustomQuery([2], $callback, 'id', [
            'status' => 'active',
        ]));
    }

    private function checker(): DatabaseExistenceChecker
    {
        return new DatabaseExistenceChecker(self::CONN, cacheEnabled: false);
    }

    private function connection()
    {
        return self::$capsule->getConnection(self::CONN);
    }
}
