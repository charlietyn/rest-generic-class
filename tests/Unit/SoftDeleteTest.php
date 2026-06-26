<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\BaseService;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\HardItem;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\SoftArchive;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\SoftPost;

/**
 * End-to-end coverage of the column-aware soft delete:
 *  - delete() sets the (conventional or custom) soft-delete column
 *  - reads exclude trashed rows; withTrashed()/onlyTrashed() include them
 *  - restore() and forceDelete() through both model and service
 *  - non-soft models keep physical-delete behaviour
 *  - the BaseModel soft-delete contract
 */
final class SoftDeleteTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$booted) {
            $capsule = new Capsule();
            $capsule->addConnection([
                'driver'   => 'sqlite',
                'database' => ':memory:',
                'prefix'   => '',
            ]);
            $capsule->setEventDispatcher(new Dispatcher(new Container()));
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            // Minimal container so the config() helper resolves inside the
            // service (cache disabled → bumpCacheVersion() early-returns).
            $container = Container::getInstance();
            $container->instance('config', new ConfigRepository([
                'rest-generic-class' => ['cache' => ['enabled' => false]],
            ]));
            Container::setInstance($container);

            $this->createSchema();
            self::$booted = true;
        }

        $this->seedData();
    }

    protected function tearDown(): void
    {
        // Keep each test isolated: wipe rows between tests.
        Capsule::table('soft_posts')->delete();
        Capsule::table('soft_archives')->delete();
        Capsule::table('hard_items')->delete();
        parent::tearDown();
    }

    private function createSchema(): void
    {
        $schema = Capsule::schema();
        $schema->create('soft_posts', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->timestamp('deleted_at')->nullable();
        });
        $schema->create('soft_archives', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->timestamp('archived_at')->nullable();
        });
        $schema->create('hard_items', function ($t) {
            $t->increments('id');
            $t->string('name');
        });
    }

    private function seedData(): void
    {
        SoftPost::insert([
            ['id' => 1, 'name' => 'Alpha', 'deleted_at' => null],
            ['id' => 2, 'name' => 'Beta',  'deleted_at' => null],
        ]);
        SoftArchive::insert([
            ['id' => 1, 'name' => 'Doc-1', 'archived_at' => null],
        ]);
        HardItem::insert([
            ['id' => 1, 'name' => 'Widget'],
        ]);
    }

    // -------------------------------------------------------------------------
    // Contract
    // -------------------------------------------------------------------------

    public function testSoftDeleteContract(): void
    {
        $this->assertTrue((new SoftPost())->isSoftDeletable());
        $this->assertSame('deleted_at', (new SoftPost())->getSoftDeleteColumn());

        $this->assertTrue((new SoftArchive())->isSoftDeletable());
        $this->assertSame('archived_at', (new SoftArchive())->getSoftDeleteColumn());
        // Native SoftDeletes operations must resolve the custom column.
        $this->assertSame('archived_at', (new SoftArchive())->getDeletedAtColumn());

        $this->assertFalse((new HardItem())->isSoftDeletable());
        $this->assertNull((new HardItem())->getSoftDeleteColumn());
    }

    // -------------------------------------------------------------------------
    // Model write-path
    // -------------------------------------------------------------------------

    public function testDeleteSetsColumnAndExcludesFromReads(): void
    {
        SoftPost::find(1)->delete();

        // Default reads exclude the trashed row.
        $this->assertNull(SoftPost::find(1));
        $this->assertSame([2], SoftPost::query()->pluck('id')->all());

        // Row still physically present with the column set.
        $raw = Capsule::table('soft_posts')->where('id', 1)->first();
        $this->assertNotNull($raw);
        $this->assertNotNull($raw->deleted_at);
    }

    public function testCustomColumnSoftDelete(): void
    {
        SoftArchive::find(1)->delete();

        $this->assertNull(SoftArchive::find(1));
        $raw = Capsule::table('soft_archives')->where('id', 1)->first();
        $this->assertNotNull($raw->archived_at);
    }

    public function testWithTrashedAndOnlyTrashed(): void
    {
        SoftPost::find(1)->delete();

        $this->assertSame([1, 2], SoftPost::withTrashed()->orderBy('id')->pluck('id')->all());
        $this->assertSame([1], SoftPost::onlyTrashed()->pluck('id')->all());
        $this->assertTrue(SoftPost::withTrashed()->find(1)->trashed());
    }

    public function testRestoreModel(): void
    {
        SoftPost::find(1)->delete();
        $this->assertNull(SoftPost::find(1));

        SoftPost::withTrashed()->find(1)->restore();

        $this->assertNotNull(SoftPost::find(1));
        $this->assertNull(Capsule::table('soft_posts')->where('id', 1)->first()->deleted_at);
    }

    public function testForceDeleteRemovesPhysically(): void
    {
        SoftPost::find(2)->forceDelete();

        $this->assertNull(Capsule::table('soft_posts')->where('id', 2)->first());
    }

    public function testNonSoftModelHardDeletes(): void
    {
        HardItem::find(1)->delete();

        $this->assertNull(Capsule::table('hard_items')->where('id', 1)->first());
    }

    // -------------------------------------------------------------------------
    // Service write-path
    // -------------------------------------------------------------------------

    public function testServiceDestroyIsSoft(): void
    {
        $service = new BaseService(SoftPost::class);
        $result = $service->destroy(1);

        $this->assertTrue($result['success']);
        $this->assertNull(SoftPost::find(1));
        $this->assertNotNull(Capsule::table('soft_posts')->where('id', 1)->first()->deleted_at);
    }

    public function testServiceRestore(): void
    {
        SoftPost::find(1)->delete();

        $service = new BaseService(SoftPost::class);
        $result = $service->restore(1);

        $this->assertTrue($result['success']);
        $this->assertNotNull(SoftPost::find(1));
    }

    public function testServiceForceDelete(): void
    {
        SoftPost::find(1)->delete();

        $service = new BaseService(SoftPost::class);
        $result = $service->forceDelete(1);

        $this->assertTrue($result['success']);
        $this->assertNull(Capsule::table('soft_posts')->where('id', 1)->first());
    }

    public function testServiceRestoreOnNonSoftModelReturnsError(): void
    {
        $service = new BaseService(HardItem::class);
        $result = $service->restore(1);

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('message', $result);
    }

    public function testServiceBulkRestore(): void
    {
        SoftPost::find(1)->delete();
        SoftPost::find(2)->delete();

        $service = new BaseService(SoftPost::class);
        $result = $service->restoreById([1, 2]);

        $this->assertTrue($result['success']);
        $this->assertSame([1, 2], SoftPost::query()->orderBy('id')->pluck('id')->all());
    }
}
