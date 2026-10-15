<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize;
use Ronu\RestGenericClass\Core\Models\BaseModel;
use Ronu\RestGenericClass\Core\Services\BaseService;
use Ronu\RestGenericClass\Core\Support\Authorization\EagerRelationAuthorizer;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelAuthorizationTestCase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelPermission;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class EagerRelationAuthorizationTest extends ChannelAuthorizationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('eager_owners', fn ($t) => [$t->increments('id'), $t->string('name')]);
        Schema::create('eager_clients', fn ($t) => [$t->increments('id'), $t->unsignedInteger('owner_id'), $t->string('name')]);
        Schema::create('eager_pets', fn ($t) => [$t->increments('id'), $t->unsignedInteger('client_id'), $t->string('name')]);
        Schema::create('eager_notes', fn ($t) => [$t->increments('id'), $t->unsignedInteger('client_id'), $t->string('body')]);
        Schema::create('eager_vaccines', fn ($t) => [$t->increments('id'), $t->unsignedInteger('pet_id'), $t->string('name')]);
        DB::table('eager_owners')->insert(['id' => 1, 'name' => 'Ana']);
        DB::table('eager_clients')->insert(['id' => 1, 'owner_id' => 1, 'name' => 'ACME']);
        DB::table('eager_pets')->insert(['id' => 1, 'client_id' => 1, 'name' => 'Rex']);
        DB::table('eager_notes')->insert(['id' => 1, 'client_id' => 1, 'body' => 'secret']);
        DB::table('eager_vaccines')->insert(['id' => 1, 'pet_id' => 1, 'name' => 'rabies']);
        config(['rest-generic-class.permissions.authorization.eager_relations' => 'require_read']);
    }

    public function testOffKeepsTheV271BehaviourWithoutChecks(): void
    {
        config(['rest-generic-class.permissions.authorization.eager_relations' => 'off']);
        $client = $this->show(['admin.crm.clients.show'], ['pets', 'notes', 'owner']);
        $this->assertTrue($client->relationLoaded('notes'));
    }

    public function testStrictDeniesAToManyRelationWithoutReadOrManage(): void
    {
        config(['app.debug' => true]);
        try {
            $this->show(['admin.crm.clients.show', 'admin.crm.clients.pets.read'], ['pets', 'notes']);
            $this->fail('Expected 403');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame(
                "Forbidden: relation 'notes' requires one of admin.crm.clients.notes.read, admin.crm.clients.notes.manage",
                $exception->getMessage()
            );
        }
    }

    public function testAllowedAndDeniedRelationsInNonStrictMode(): void
    {
        config(['rest-generic-class.filtering.strict_relations' => false]);
        $client = $this->show(['admin.crm.clients.show', 'admin.crm.clients.pets.manage'], ['pets', 'notes', 'owner']);
        $this->assertTrue($client->relationLoaded('pets'), 'manage is an alternative of read');
        $this->assertFalse($client->relationLoaded('notes'), 'denied relation is left out');
        $this->assertTrue($client->relationLoaded('owner'), 'to-one relations are exempt');
        $this->assertSame('Rex', $client->pets->first()->name);
    }

    public function testToOneRelationsCanBeChecked(): void
    {
        config([
            'rest-generic-class.filtering.strict_relations' => false,
            'rest-generic-class.permissions.authorization.eager_relations_to_one' => 'require_read',
        ]);
        $this->assertFalse($this->show(['admin.crm.clients.show'], ['owner'])->relationLoaded('owner'));
        $this->assertTrue($this->show(['admin.crm.clients.show', 'admin.crm.clients.owner.read'], ['owner'])->relationLoaded('owner'));
    }

    public function testEveryToManyHopOfANestedPathIsChecked(): void
    {
        config(['rest-generic-class.filtering.strict_relations' => false]);
        $granted = ['admin.crm.clients.show', 'admin.crm.clients.pets.read'];
        $this->assertFalse($this->show($granted, ['pets.vaccines'])->relationLoaded('pets'));
        $client = $this->show([...$granted, 'admin.crm.clients.pets.vaccines.read'], ['pets.vaccines']);
        $this->assertTrue($client->pets->first()->relationLoaded('vaccines'));
    }

    public function testRelationListsWithFieldsAndClosuresAreFiltered(): void
    {
        config(['rest-generic-class.filtering.strict_relations' => false]);
        $closure = fn () => null;
        $kept = $this->authorize(['admin.crm.clients.show', 'admin.crm.clients.pets.read'], function () use ($closure) {
            return app(EagerRelationAuthorizer::class)->filter(new EagerClient(), [
                'pets:id,name', 'notes', 'owner:id', 'pets' => $closure, 'notes' => $closure,
            ]);
        });
        $this->assertSame([0 => 'pets:id,name', 2 => 'owner:id', 'pets' => $closure], $kept);
    }

    public function testCachedResponsesNeverBypassTheCheck(): void
    {
        config([
            'rest-generic-class.cache.enabled' => true,
            'rest-generic-class.cache.store' => 'array',
            'rest-generic-class.filtering.strict_relations' => false,
        ]);
        $list = fn (array $permissions) => $this->authorize($permissions, fn () => (new BaseService(EagerClient::class))
            ->list_all(['relations' => ['notes']]), userId: 7);

        $granted = $list(['admin.crm.clients.show', 'admin.crm.clients.notes.read']);
        $this->assertArrayHasKey('notes', $granted['data'][0]);

        // Same user, permission revoked: the cached response with notes must not be served.
        $revoked = $list(['admin.crm.clients.show']);
        $this->assertArrayNotHasKey('notes', $revoked['data'][0]);

        config(['rest-generic-class.filtering.strict_relations' => true]);
        $this->expectException(HttpException::class);
        $list(['admin.crm.clients.show']);
    }

    public function testWithoutAnAuthorizedRequestNothingIsChecked(): void
    {
        $client = (new BaseService(EagerClient::class))->show(['relations' => ['notes']], 1);
        $this->assertTrue($client->relationLoaded('notes'));
    }

    /** GET admin/crm/clients/1 through SpatieAuthorize, then BaseService::show() with ?relations=. */
    private function show(array $permissions, array $relations): EagerClient
    {
        return $this->authorize($permissions, fn () => (new BaseService(EagerClient::class))->show(['relations' => $relations], 1));
    }

    private function authorize(array $permissions, \Closure $action, ?int $userId = null): mixed
    {
        $user = new ChannelUser();
        $user->id = $userId ?? random_int(1, PHP_INT_MAX);
        $user->enabled_permissions()->detach();
        foreach ($permissions as $name) {
            $permission = ChannelPermission::firstOrCreate(['name' => $name, 'guard_name' => 'api'], ['restrict' => true]);
            $user->enabled_permissions()->attach($permission->id);
        }
        $this->actingAs($user, 'web');

        $route = (new Route('GET', 'admin/crm/clients/{id}', fn () => 'ok'))->name('admin.crm.clients.show');
        $request = Request::create('/admin/crm/clients/1');
        $request->setRouteResolver(fn () => $route);
        $this->app->instance('request', $request);
        $result = null;
        $middleware = (new \ReflectionClass(SpatieAuthorize::class))->newInstanceWithoutConstructor();
        $middleware->handle($request, function () use ($action, &$result) {
            $result = $action();
            return new Response('passed');
        }, 'web');
        return $result;
    }
}

class EagerClient extends BaseModel
{
    const RELATIONS = ['pets', 'notes', 'owner'];
    protected $table = 'eager_clients';
    public $timestamps = false;

    public function pets(): HasMany { return $this->hasMany(EagerPet::class, 'client_id'); }
    public function notes(): HasMany { return $this->hasMany(EagerNote::class, 'client_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(EagerOwner::class, 'owner_id'); }
}

class EagerPet extends BaseModel
{
    const RELATIONS = ['vaccines'];
    protected $table = 'eager_pets';
    public $timestamps = false;

    public function vaccines(): HasMany { return $this->hasMany(EagerVaccine::class, 'pet_id'); }
}

class EagerNote extends BaseModel
{
    protected $table = 'eager_notes';
    public $timestamps = false;
}

class EagerOwner extends BaseModel
{
    protected $table = 'eager_owners';
    public $timestamps = false;
}

class EagerVaccine extends BaseModel
{
    protected $table = 'eager_vaccines';
    public $timestamps = false;
}
