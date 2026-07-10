<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Validation\UniqueValidationSupport;

final class UniqueValidationSupportTest extends TestCase
{
    private const CONN = 'unique_support';
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
            $schema->create('unique_support_items', function ($table) {
                $table->increments('id');
                $table->string('name');
                $table->unsignedInteger('tenant_id');
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('unique_support_addresses', function ($table) {
                $table->increments('id');
                $table->string('phone');
                $table->timestamp('deleted_at')->nullable();
            });
            $schema->create('unique_support_user_addresses', function ($table) {
                $table->unsignedInteger('user_id');
                $table->unsignedInteger('address_id');
                $table->timestamp('deleted_at')->nullable();
            });

            self::$capsule = $capsule;
        }

        $container = Container::getInstance();
        $container->instance('db', self::$capsule->getDatabaseManager());
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('db');

        $this->connection()->table('unique_support_user_addresses')->delete();
        $this->connection()->table('unique_support_addresses')->delete();
        $this->connection()->table('unique_support_items')->delete();

        $this->connection()->table('unique_support_items')->insert([
            ['id' => 1, 'name' => 'alpha', 'tenant_id' => 1, 'deleted_at' => null],
            ['id' => 2, 'name' => 'alpha', 'tenant_id' => 2, 'deleted_at' => null],
            ['id' => 3, 'name' => 'beta', 'tenant_id' => 1, 'deleted_at' => '2026-01-01 00:00:00'],
        ]);

        $this->connection()->table('unique_support_addresses')->insert([
            ['id' => 1, 'phone' => '111', 'deleted_at' => null],
            ['id' => 2, 'phone' => '222', 'deleted_at' => null],
            ['id' => 3, 'phone' => '333', 'deleted_at' => '2026-01-02 00:00:00'],
            ['id' => 4, 'phone' => '444', 'deleted_at' => null],
        ]);
        $this->connection()->table('unique_support_user_addresses')->insert([
            ['user_id' => 7, 'address_id' => 1, 'deleted_at' => null],
            ['user_id' => 7, 'address_id' => 2, 'deleted_at' => '2026-01-03 00:00:00'],
            ['user_id' => 7, 'address_id' => 3, 'deleted_at' => null],
            ['user_id' => 8, 'address_id' => 4, 'deleted_at' => null],
        ]);
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('db');

        parent::tearDown();
    }

    public function testBuildArrayMessageKeepsLegacyText(): void
    {
        $this->assertSame(
            "The phone '+1' is duplicated in the request at addresses[2].",
            UniqueValidationSupport::buildArrayMessage('addresses.2.phone', '+1', 'phone', 'addresses', true)
        );

        $this->assertSame(
            "The phone '+1' at addresses[2] has already been taken.",
            UniqueValidationSupport::buildArrayMessage('addresses.2.phone', '+1', 'phone', 'addresses', false)
        );
    }

    public function testDuplicateDetectionAndIgnoreValueResolution(): void
    {
        $items = [
            ['id' => 1, 'phone' => '+1'],
            ['id' => 2, 'phone' => '+1'],
            ['id' => 3, 'phone' => ''],
            ['id' => 4, 'phone' => ''],
            ['id' => 5, 'phone' => null],
        ];

        $this->assertTrue(UniqueValidationSupport::hasDuplicateValue($items, 'phone', '+1'));
        $this->assertTrue(UniqueValidationSupport::hasDuplicateValue($items, 'phone', ''));
        $this->assertFalse(UniqueValidationSupport::hasDuplicateValue($items, 'phone', '', ignoreEmptyValues: true));
        $this->assertSame(2, UniqueValidationSupport::resolveIgnoreValue('addresses.1.phone', $items, 'addresses', 'id'));
        $this->assertNull(UniqueValidationSupport::resolveIgnoreValue('addresses.1.phone', $items, 'addresses', null));
    }

    public function testCompositeExistsHonorsConditionsSoftDeleteAndIgnore(): void
    {
        $this->assertTrue(UniqueValidationSupport::compositeExists(
            self::CONN,
            'unique_support_items',
            'name',
            'alpha',
            ['tenant_id' => 1],
            softDeleteColumn: 'deleted_at'
        ));

        $this->assertFalse(UniqueValidationSupport::compositeExists(
            self::CONN,
            'unique_support_items',
            'name',
            'beta',
            ['tenant_id' => 1],
            softDeleteColumn: 'deleted_at'
        ));

        $this->assertTrue(UniqueValidationSupport::compositeExists(
            self::CONN,
            'unique_support_items',
            'name',
            'beta',
            ['tenant_id' => 1],
            softDeleteColumn: null
        ));

        $this->assertFalse(UniqueValidationSupport::compositeExists(
            self::CONN,
            'unique_support_items',
            'name',
            'alpha',
            ['tenant_id' => 1],
            ignoreField: 'id',
            ignoreValue: 1,
            softDeleteColumn: 'deleted_at'
        ));
    }

    public function testPivotExistsHonorsOwnerSoftDeleteAndIgnore(): void
    {
        $this->assertTrue($this->pivotExists('111'));
        $this->assertFalse($this->pivotExists('111', ignoreValue: 1));

        $this->assertFalse($this->pivotExists('222', pivotSoftDeleteColumn: 'deleted_at'));
        $this->assertTrue($this->pivotExists('222'));

        $this->assertFalse($this->pivotExists('333', softDeleteColumn: 'deleted_at'));
        $this->assertTrue($this->pivotExists('333'));

        $this->assertFalse($this->pivotExists('444'));
    }

    private function pivotExists(
        string $phone,
        mixed $ignoreValue = null,
        ?string $softDeleteColumn = null,
        ?string $pivotSoftDeleteColumn = null
    ): bool {
        return UniqueValidationSupport::pivotExists(
            self::CONN,
            'unique_support_addresses',
            'unique_support_user_addresses',
            'address_id',
            'user_id',
            7,
            'phone',
            $phone,
            ignoreValue: $ignoreValue,
            softDeleteColumn: $softDeleteColumn,
            pivotSoftDeleteColumn: $pivotSoftDeleteColumn
        );
    }

    private function connection()
    {
        return self::$capsule->getConnection(self::CONN);
    }
}
