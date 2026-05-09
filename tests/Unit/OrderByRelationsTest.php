<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Traits\HasDynamicOrderBy;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\OrderByApplier;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\OrderTestClient;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\OrderTestPost;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\OrderTestRole;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\OrderTestUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class OrderByRelationsTest extends TestCase
{
    private static bool $booted = false;

    protected function setUp(): void
    {
        parent::setUp();
        if (self::$booted) {
            return;
        }

        $capsule = new Capsule();
        $capsule->addConnection([
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);
        $capsule->setEventDispatcher(new Dispatcher(new Container()));
        $capsule->setAsGlobal();
        $capsule->bootEloquent();

        $this->createSchema();
        $this->seedData();

        self::$booted = true;
    }

    private function createSchema(): void
    {
        $schema = Capsule::schema();
        $schema->create('roles', function ($t) {
            $t->increments('id');
            $t->string('name');
        });
        $schema->create('users', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->string('email')->nullable();
            $t->unsignedInteger('role_id')->nullable();
            $t->unsignedInteger('parent_id')->nullable();
        });
        $schema->create('clients', function ($t) {
            $t->increments('id');
            $t->string('name');
            $t->unsignedInteger('user_id');
        });
        $schema->create('posts', function ($t) {
            $t->increments('id');
            $t->string('title');
            $t->unsignedInteger('user_id');
        });
    }

    private function seedData(): void
    {
        OrderTestRole::insert([
            ['id' => 1, 'name' => 'admin'],
            ['id' => 2, 'name' => 'user'],
        ]);
        OrderTestUser::insert([
            ['id' => 1, 'name' => 'Zara',  'email' => 'z@x.com', 'role_id' => 2, 'parent_id' => null],
            ['id' => 2, 'name' => 'Bob',   'email' => 'b@x.com', 'role_id' => 1, 'parent_id' => null],
            ['id' => 3, 'name' => 'Alice', 'email' => 'a@x.com', 'role_id' => 2, 'parent_id' => 2],
        ]);
        OrderTestClient::insert([
            ['id' => 1, 'name' => 'Acme',  'user_id' => 1],
            ['id' => 2, 'name' => 'Beta',  'user_id' => 2],
            ['id' => 3, 'name' => 'Gamma', 'user_id' => 3],
        ]);
        OrderTestPost::insert([
            ['id' => 1, 'title' => 'Bravo',   'user_id' => 1],
            ['id' => 2, 'title' => 'Charlie', 'user_id' => 2],
            ['id' => 3, 'title' => 'Alpha',   'user_id' => 1],
        ]);
    }

    private function applier(): OrderByApplier
    {
        return new OrderByApplier();
    }

    /* ---------------------------------------------------------------------
     *  1. Local column → prefijo de tabla
     * --------------------------------------------------------------------- */
    public function testLocalColumnIsPrefixedWithTable(): void
    {
        $query = OrderTestClient::query();
        $this->applier()->apply($query, [['name' => 'asc']], OrderTestClient::class);

        $this->assertStringContainsString('order by "clients"."name" asc', $query->toSql());
    }

    /* ---------------------------------------------------------------------
     *  2. Pasaje literal cuando el primer segmento NO es método de relación
     * --------------------------------------------------------------------- */
    public function testLiteralTableColumnIsPassedThroughWhenNotARelationMethod(): void
    {
        // 'foo_bar' no es método en OrderTestClient → se pasa tal cual al builder.
        $query = OrderTestClient::query();
        $this->applier()->apply($query, [['foo_bar.name' => 'desc']], OrderTestClient::class);

        $this->assertStringContainsString('order by "foo_bar"."name" desc', $query->toSql());
    }

    /* ---------------------------------------------------------------------
     *  3. belongsTo: subquery escalar correlacionado
     * --------------------------------------------------------------------- */
    public function testBelongsToOrderByGeneratesScalarSubquery(): void
    {
        $query = OrderTestClient::query();
        $this->applier()->apply($query, [['user.name' => 'asc']], OrderTestClient::class);

        $sql = $query->toSql();
        $this->assertStringContainsString('order by (select', $sql);
        $this->assertStringContainsString('"users"."name"', $sql);
        $this->assertStringContainsString('limit 1) asc', $sql);

        $ids = $query->pluck('id')->all();
        // Users ordered by name asc: Alice(3), Bob(2), Zara(1) → clients [3,2,1]
        $this->assertSame([3, 2, 1], $ids);
    }

    /* ---------------------------------------------------------------------
     *  4. Anidamiento: user.role.name
     * --------------------------------------------------------------------- */
    public function testNestedRelationOrderByGeneratesNestedSubquery(): void
    {
        $query = OrderTestClient::query();
        $this->applier()->apply($query, [['user.role.name' => 'asc'], ['name' => 'asc']], OrderTestClient::class);

        $sql = $query->toSql();
        // Two nested "select … limit 1" inside the order by
        $this->assertSame(2, substr_count($sql, 'limit 1'), "SQL: $sql");
        $this->assertStringContainsString('"roles"."name"', $sql);

        // role names: client 2 → admin (Bob, role 1), clients 1+3 → user (role 2)
        // After tie-break on client.name asc, expect [2, 1, 3] (Beta has admin role; then Acme, Gamma)
        $ids = $query->pluck('id')->all();
        $this->assertSame([2, 1, 3], $ids);
    }

    /* ---------------------------------------------------------------------
     *  5. Mixto: local + relación + relación anidada
     * --------------------------------------------------------------------- */
    public function testMixedLocalAndRelationOrderBy(): void
    {
        $query = OrderTestClient::query();
        $this->applier()->apply(
            $query,
            [['name' => 'desc'], ['user.role.name' => 'asc']],
            OrderTestClient::class
        );

        $sql = $query->toSql();
        $this->assertStringContainsString('"clients"."name" desc', $sql);
        $this->assertStringContainsString('order by "clients"."name" desc, (select', $sql);
    }

    /* ---------------------------------------------------------------------
     *  6. hasMany: ningún duplicado de filas
     * --------------------------------------------------------------------- */
    public function testHasManyOrderByDoesNotDuplicateRows(): void
    {
        $query = OrderTestUser::query();
        $this->applier()->apply($query, [['posts.title' => 'asc']], OrderTestUser::class);

        $sql = $query->toSql();
        $this->assertStringContainsString('"posts"."title"', $sql);

        $rows = $query->get();
        // 3 users, 3 posts (user 1 has 2 posts) → ordering by posts must NOT duplicate user rows
        $this->assertCount(3, $rows);
    }

    /* ---------------------------------------------------------------------
     *  7. Relación NO permitida (existe método pero no en RELATIONS) → 400
     * --------------------------------------------------------------------- */
    public function testOrderByOnUnwhitelistedRelationThrowsHttp400(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage("Relation 'role' is not allowed for ordering");

        $query = OrderTestClient::query();
        // OrderTestClient::role() exists as a method but 'role' ∉ Client::RELATIONS
        $this->applier()->apply($query, [['role.name' => 'asc']], OrderTestClient::class);
    }

    /* ---------------------------------------------------------------------
     *  8. Profundidad excesiva → 400
     * --------------------------------------------------------------------- */
    public function testOrderByExceedingMaxDepthThrowsHttp400(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Maximum orderby relation depth');

        // 6 segmentos de profundidad > default max=5
        $query = OrderTestUser::query();
        $this->applier()->apply(
            $query,
            [['parent.parent.parent.parent.parent.parent.name' => 'asc']],
            OrderTestUser::class
        );
    }

    /* ---------------------------------------------------------------------
     *  9. Dirección inválida → asc
     * --------------------------------------------------------------------- */
    public function testInvalidDirectionFallsBackToAsc(): void
    {
        $query = OrderTestClient::query();
        $this->applier()->apply($query, [['user.name' => 'BANANA']], OrderTestClient::class);

        $sql = $query->toSql();
        $this->assertStringContainsString('limit 1) asc', $sql);
        $this->assertStringNotContainsString('desc', $sql);
    }

    /* ---------------------------------------------------------------------
     *  10. Compatibilidad: whereHas + orderBy sobre la misma relación
     * --------------------------------------------------------------------- */
    public function testCompatibleWithWhereHasOnSameRelation(): void
    {
        $query = OrderTestClient::query()->whereHas('user', function ($q) {
            $q->where('name', 'like', '%a%');
        });
        $this->applier()->apply($query, [['user.name' => 'desc']], OrderTestClient::class);

        $rows = $query->get();
        // Users with 'a' in name: Zara(1), Alice(3) → clients 1, 3 ordered by user name desc → Zara, Alice → [1, 3]
        $this->assertSame([1, 3], $rows->pluck('id')->all());
    }

    /* ---------------------------------------------------------------------
     *  11. Listado anidado (Relation): post.user.name vía ManagesRelations style
     * --------------------------------------------------------------------- */
    public function testOrderingARelationOfPostsByItsUserName(): void
    {
        $user = OrderTestUser::find(1);

        // Simula ManagesRelations::applyOrdering pasando la Relation y el modelo relacionado
        $relation = $user->posts();
        $relatedModel = $relation->getRelated();
        $this->applier()->apply($relation, [['user.name' => 'asc']], $relatedModel);

        $sql = $relation->toSql();
        $this->assertStringContainsString('order by (select', $sql);
        $this->assertStringContainsString('"users"."name"', $sql);

        // Post.user is the same user (id=1, Zara) for both → just verifies the SQL is valid and runs
        $rows = $relation->get();
        $this->assertCount(2, $rows);
    }

    /* ---------------------------------------------------------------------
     *  12. orderby como string JSON top-level (retrocompat)
     * --------------------------------------------------------------------- */
    public function testJsonStringOrderbyIsParsed(): void
    {
        $query = OrderTestClient::query();
        $this->applier()->apply($query, '[{"user.name":"desc"}]', OrderTestClient::class);

        $sql = $query->toSql();
        $this->assertStringContainsString('limit 1) desc', $sql);
    }
}
