<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\Support\PivotMutationCoordinator;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\RelationParentItem;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\RelationTagItem;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class PivotMutationCoordinatorTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!self::$booted) {
            $capsule = new Capsule();
            $capsule->addConnection([
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
            ]);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            Capsule::schema()->create('relation_parent_items', function ($table) {
                $table->increments('id');
                $table->string('name');
            });

            Capsule::schema()->create('relation_tag_items', function ($table) {
                $table->increments('id');
                $table->string('name');
            });

            Capsule::schema()->create('relation_parent_tag', function ($table) {
                $table->unsignedInteger('parent_item_id');
                $table->unsignedInteger('tag_item_id');
                $table->string('label')->nullable();
                $table->boolean('is_primary')->default(false);
                $table->string('ignored')->nullable();
            });

            self::$booted = true;
        }

        $this->seedRows();
    }

    public function testAttachAddsSinglePivotWithWhitelistedColumns(): void
    {
        $request = Request::create('/', 'POST', [
            '_relation' => 'tags',
            'tag_id' => 1,
            'label' => 'main',
            'ignored' => 'drop-me',
        ]);

        $response = $this->coordinator()->attach($request, 1);
        $payload = $response->getData(true);

        $this->assertSame([1], $payload['attached']);
        $this->assertSame('main', $this->pivotValue(1, 1, 'label'));
        $this->assertNull($this->pivotValue(1, 1, 'ignored'));
    }

    public function testBulkAttachAddsMultiplePivotRows(): void
    {
        $request = Request::create('/', 'POST', [
            '_relation' => 'tags',
            '_scenario' => 'bulk_attach',
            'tags' => [
                ['tag_id' => 1, 'label' => 'first'],
                ['tag_id' => 2, 'label' => 'second'],
            ],
        ]);

        $response = $this->coordinator()->attach($request, 1);
        $payload = $response->getData(true);

        $this->assertSame([1, 2], $payload['attached']);
        $this->assertSame('first', $this->pivotValue(1, 1, 'label'));
        $this->assertSame('second', $this->pivotValue(1, 2, 'label'));
    }

    public function testSyncAttachReplacesRelationshipSet(): void
    {
        RelationParentItem::find(1)->tags()->attach(1, ['label' => 'old']);

        $request = Request::create('/', 'POST', [
            '_relation' => 'tags',
            '_scenario' => 'sync',
            'tags' => [
                ['tag_id' => 2, 'label' => 'new'],
                ['tag_id' => 3, 'label' => 'third'],
            ],
        ]);

        $response = $this->coordinator()->attach($request, 1);
        $payload = $response->getData(true);

        $this->assertSame([2, 3], $payload['attached']);
        $this->assertSame([1], $payload['detached']);
        $this->assertNull($this->pivotValue(1, 1, 'label'));
        $this->assertSame('new', $this->pivotValue(1, 2, 'label'));
    }

    public function testDetachRemovesSinglePivotRow(): void
    {
        RelationParentItem::find(1)->tags()->attach([1, 2]);

        $request = Request::create('/', 'DELETE', [
            '_relation' => 'tags',
        ]);

        $response = $this->coordinator()->detach($request, 1, 2);
        $payload = $response->getData(true);

        $this->assertSame(1, $payload['detached']);
        $this->assertNotNull($this->pivotValue(1, 1, 'tag_item_id'));
        $this->assertNull($this->pivotValue(1, 2, 'tag_item_id'));
    }

    public function testUpdatePivotUpdatesExistingPivotColumns(): void
    {
        RelationParentItem::find(1)->tags()->attach(1, ['label' => 'old']);

        $request = Request::create('/', 'PUT', [
            '_relation' => 'tags',
            'label' => 'updated',
            'is_primary' => true,
        ]);

        $response = $this->coordinator()->updatePivot($request, 1, 1);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('updated', $this->pivotValue(1, 1, 'label'));
        $this->assertSame(1, $this->pivotValue(1, 1, 'is_primary'));
    }

    private function coordinator(): PivotMutationCoordinator
    {
        return new PivotMutationCoordinator(
            fn (?string $relationName): array => $this->relationConfig($relationName),
            function (array $config, string $method): void {
                if (($config['_type'] ?? 'o2m') !== 'm2m') {
                    throw new BadRequestHttpException($method . ' requires m2m');
                }
            },
            fn (array $config, mixed $parentId): RelationParentItem => RelationParentItem::query()->findOrFail($parentId),
            fn (array $config, bool $bulk, mixed $parentIdOrRelatedId, mixed $relatedId): array => [
                RelationParentItem::query()->findOrFail($parentIdOrRelatedId),
                $relatedId,
            ],
            fn (Request $request, array $config): array => $this->extractData($request, $config),
            fn (Request $request, callable $operation, int $status = 200): JsonResponse => new JsonResponse($operation(), $status),
            fn (Request $request): bool => str_contains($request->get('_scenario', ''), 'bulk')
        );
    }

    private function relationConfig(?string $relationName): array
    {
        $this->assertSame('tags', $relationName);

        return [
            'relationship' => 'tags',
            'relatedModel' => RelationTagItem::class,
            'parentModel' => RelationParentItem::class,
            'relatedKey' => 'tag_id',
            'mutation' => [
                'dataKey' => 'tags',
                'pivotColumns' => ['label', 'is_primary'],
            ],
            '_type' => 'm2m',
        ];
    }

    private function extractData(Request $request, array $config): array
    {
        $params = $request->all();
        $dataKey = $config['mutation']['dataKey'];

        if (isset($params[$dataKey])) {
            return $params[$dataKey];
        }

        unset($params['_relation'], $params['_scenario']);
        return $params;
    }

    private function seedRows(): void
    {
        Capsule::table('relation_parent_tag')->delete();
        RelationTagItem::query()->delete();
        RelationParentItem::query()->delete();

        RelationParentItem::insert([
            ['id' => 1, 'name' => 'Parent A'],
            ['id' => 2, 'name' => 'Parent B'],
        ]);

        RelationTagItem::insert([
            ['id' => 1, 'name' => 'Tag A'],
            ['id' => 2, 'name' => 'Tag B'],
            ['id' => 3, 'name' => 'Tag C'],
        ]);
    }

    private function pivotValue(int $parentId, int $tagId, string $column): mixed
    {
        $row = Capsule::table('relation_parent_tag')
            ->where('parent_item_id', $parentId)
            ->where('tag_item_id', $tagId)
            ->first();

        return $row ? $row->{$column} : null;
    }
}
