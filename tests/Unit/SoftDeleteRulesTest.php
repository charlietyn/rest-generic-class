<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Rules\IdsExistInTable;
use Ronu\RestGenericClass\Core\Rules\UniqueCompositeInArray;
use Ronu\RestGenericClass\Core\Traits\ValidatesExistenceInDatabase;

/**
 * Proves the validation rules are column-aware / soft-delete-aware:
 *  - existence checks ignore soft-deleted rows (custom column supported)
 *  - a unique value freed by a soft delete can be reused
 *  - backward compatibility: without the soft-delete column nothing changes
 */
final class SoftDeleteRulesTest extends TestCase
{
    public const CONN = 'rules';
    private static ?Capsule $capsule = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$capsule === null) {
            $capsule = new Capsule(new Container());
            $capsule->addConnection([
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ], self::CONN);
            $capsule->setEventDispatcher(new Dispatcher(new Container()));
            $capsule->bootEloquent();

            $capsule->getConnection(self::CONN)->getSchemaBuilder()->create('cats', function ($t) {
                $t->increments('id');
                $t->string('name');
                $t->timestamp('deleted_at')->nullable();
            });

            self::$capsule = $capsule;
        }

        // Wire DB + Cache facades onto the shared global container so the rules
        // resolve our in-memory connection and a throwaway array cache.
        $container = Container::getInstance();
        $container->instance('db', self::$capsule->getDatabaseManager());
        $container->instance('cache', new CacheRepository(new ArrayStore()));
        Facade::setFacadeApplication($container);

        $conn = self::$capsule->getConnection(self::CONN);
        $conn->table('cats')->delete();
        $conn->table('cats')->insert([
            ['id' => 1, 'name' => 'tom',   'deleted_at' => null],
            ['id' => 2, 'name' => 'jerry', 'deleted_at' => '2020-01-01 00:00:00'], // soft-deleted
        ]);
    }

    /** Trait host with validation cache disabled (deterministic, no cache). */
    private function existenceChecker(): object
    {
        return new class {
            use ValidatesExistenceInDatabase;

            public function __construct()
            {
                $this->connection = SoftDeleteRulesTest::CONN;
                $this->enableValidationCache = false;
            }
        };
    }

    public function testExistNotDeletedExcludesSoftDeletedRow(): void
    {
        $checker = $this->existenceChecker();

        // id 1 (tom) is active → exists.
        $this->assertTrue($checker->validateIdsExistNotDeleted([1], 'cats', 'id', [], 'deleted_at'));
        // id 2 (jerry) is soft-deleted → treated as missing.
        $this->assertFalse($checker->validateIdsExistNotDeleted([2], 'cats', 'id', [], 'deleted_at'));
        // Passing null disables soft-delete filtering → jerry is found again.
        $this->assertTrue($checker->validateIdsExistNotDeleted([2], 'cats', 'id', [], null));
    }

    public function testIdsExistInTableSoftAwareIgnoresDeleted(): void
    {
        // soft-aware: the soft-deleted id 2 must be reported as non-existent.
        $softAware = new IdsExistInTable(self::CONN, 'cats', 'id', [], null, 'deleted_at');
        $softAware->setValidator($validator = $this->makeValidator());
        $softAware->validate('cat_ids', [2], fn () => null);
        $this->assertTrue($validator->errors()->isNotEmpty(), 'Soft-deleted id should be rejected.');

        // legacy (no soft column): the row still exists → no error.
        $legacy = new IdsExistInTable(self::CONN, 'cats', 'id');
        $legacy->setValidator($validator2 = $this->makeValidator());
        $legacy->validate('cat_ids', [2], fn () => null);
        $this->assertTrue($validator2->errors()->isEmpty());
    }

    public function testUniqueCompositeIgnoresSoftDeletedRowForReuse(): void
    {
        // Reusing 'jerry' (a name freed by a soft delete) must PASS when soft-aware.
        $this->assertFalse($this->uniqueFails('jerry', 'deleted_at'));
        // ...and FAIL when not soft-aware (legacy: the row still counts).
        $this->assertTrue($this->uniqueFails('jerry', null));
        // An ACTIVE name must always collide.
        $this->assertTrue($this->uniqueFails('tom', 'deleted_at'));
    }

    private function uniqueFails(string $value, ?string $softDeleteColumn): bool
    {
        $rule = new UniqueCompositeInArray(
            connection: self::CONN,
            table: 'cats',
            column: 'name',
            arrayKey: 'cats',
            conditions: [],
            ignoreField: null,
            softDeleteColumn: $softDeleteColumn,
        );
        $rule->setData(['cats' => [['name' => $value]]]);

        $failed = false;
        $rule->validate('cats.0.name', $value, function () use (&$failed) { $failed = true; });
        return $failed;
    }

    private function makeValidator(): Validator
    {
        return new Validator(new Translator(new ArrayLoader(), 'en'), [], []);
    }
}
