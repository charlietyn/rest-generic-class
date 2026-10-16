<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;
use Ronu\RestGenericClass\Core\Authorization\AuthorizationScope;
use Ronu\RestGenericClass\Core\Authorization\GateRelationAuthorizer;
use Ronu\RestGenericClass\Core\Authorization\MissingAuthorizationContextException;
use Ronu\RestGenericClass\Core\Authorization\NullAuthorizationContext;
use Ronu\RestGenericClass\Core\Authorization\RelationAbilityNamer;
use Ronu\RestGenericClass\Core\Contracts\Authorization\AuthorizationContext;
use Ronu\RestGenericClass\Core\Contracts\Authorization\FieldWriteAuthorizer;
use Ronu\RestGenericClass\Core\Contracts\Authorization\RelationAuthorizer;
use Ronu\RestGenericClass\Core\Models\BaseModel;
use Ronu\RestGenericClass\Core\Providers\RestGenericClassServiceProvider;
use Ronu\RestGenericClass\Core\Services\BaseService;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\RestoresPaginationResolvers;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The core authorizes eager loads only through its contracts and Laravel's Gate: any integration
 * that binds an AuthorizationContext and answers the Gate works, without the route permissions.
 */
final class AuthorizationContractsTest extends TestCase
{
    use RestoresPaginationResolvers;

    protected function getPackageProviders($app): array
    {
        return [RestGenericClassServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('rest-generic-class.authorization.eager_relations', 'require_read');
    }

    protected function setUp(): void
    {
        $this->capturePaginationResolvers();
        parent::setUp();
        Schema::create('contract_owners', fn ($t) => [$t->increments('id'), $t->string('name')]);
        Schema::create('contract_clients', fn ($t) => [$t->increments('id'), $t->unsignedInteger('owner_id'), $t->string('name')]);
        Schema::create('contract_notes', fn ($t) => [$t->increments('id'), $t->unsignedInteger('client_id'), $t->string('body')]);
        DB::table('contract_owners')->insert(['id' => 1, 'name' => 'Ana']);
        DB::table('contract_clients')->insert(['id' => 1, 'owner_id' => 1, 'name' => 'ACME']);
        DB::table('contract_notes')->insert(['id' => 1, 'client_id' => 1, 'body' => 'secret']);
        $this->actingAs(new ContractUser(['id' => 1]));
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            $this->restorePaginationResolvers();
        }
    }

    public function testAnyIntegrationThatBindsAContextAndAnswersTheGateIsEnforced(): void
    {
        $this->grantScope('crm.clients');
        Gate::define('crm.clients.notes.read', fn () => false);
        config(['rest-generic-class.filtering.strict_relations' => false]);

        $client = (new BaseService(ContractClient::class))->show(['relations' => ['notes', 'owner']], 1);
        $this->assertFalse($client->relationLoaded('notes'), 'denied by the Gate');
        $this->assertTrue($client->relationLoaded('owner'), 'to-one relations are exempt');

        Gate::define('crm.clients.notes.read', fn () => true);
        $this->assertTrue((new BaseService(ContractClient::class))->show(['relations' => ['notes']], 1)->relationLoaded('notes'));
    }

    public function testStrictModeDeniesWithTheAlternativesOfTheConfiguredNamer(): void
    {
        config(['app.debug' => true, 'rest-generic-class.authorization.relation_abilities' => ['view']]);
        $this->grantScope('crm.clients');
        try {
            (new BaseService(ContractClient::class))->show(['relations' => ['notes']], 1);
            $this->fail('Expected 403');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertSame("Forbidden: relation 'notes' requires one of crm.clients.notes.view", $exception->getMessage());
        }
    }

    public function testRequestsWithoutAScopeAreNotChecked(): void
    {
        $this->app->bind(AuthorizationContext::class, fn () => new FixedContext(null));
        $this->assertTrue((new BaseService(ContractClient::class))->show(['relations' => ['notes']], 1)->relationLoaded('notes'));
    }

    public function testRequireReadWithoutAnyIntegrationFailsClosedAtFirstUse(): void
    {
        $this->app->bind(AuthorizationContext::class, NullAuthorizationContext::class);
        // Without relations there is nothing to authorize.
        $this->assertSame('ACME', (new BaseService(ContractClient::class))->show([], 1)->name);

        $this->expectException(MissingAuthorizationContextException::class);
        (new BaseService(ContractClient::class))->show(['relations' => ['notes']], 1);
    }

    public function testRequireReadWithoutAnyIntegrationFailsAtHttpBoot(): void
    {
        $this->app->bind(AuthorizationContext::class, NullAuthorizationContext::class);
        $this->bootProviderOutsideTheConsole();
    }

    public function testOffDoesNotNeedAnIntegration(): void
    {
        config(['rest-generic-class.authorization.eager_relations' => 'off']);
        $this->app->bind(AuthorizationContext::class, NullAuthorizationContext::class);
        $this->bootProviderOutsideTheConsole(expectFailure: false);
        $this->assertTrue((new BaseService(ContractClient::class))->show(['relations' => ['notes']], 1)->relationLoaded('notes'));
    }

    public function testTheLegacyKeyIsHonouredAndTheNewBlockWins(): void
    {
        config([
            'rest-generic-class.authorization.eager_relations' => null,
            'rest-generic-class.permissions.authorization.eager_relations' => 'require_read',
        ]);
        $this->grantScope('crm.clients');
        $this->assertTrue(app(RelationAuthorizer::class)->enabled(), 'legacy key');

        config(['rest-generic-class.authorization.eager_relations' => 'off']);
        $this->assertFalse(app(RelationAuthorizer::class)->enabled(), 'new key wins');
    }

    /** The cache must never serve relations the authorizer would now deny (v2.8.0 security fix). */
    public function testAuthorizationRunsBeforeTheCacheLookupOnEveryCall(): void
    {
        config([
            'rest-generic-class.cache.enabled' => true,
            'rest-generic-class.cache.store' => 'array',
        ]);
        $events = [];
        $spy = new class($events) implements RelationAuthorizer {
            public function __construct(private array &$events) {}
            public function enabled(): bool { return true; }
            public function filter(Model $root, array $relations, ?callable $pathOf = null): array
            {
                $this->events[] = 'authorize';
                return $relations;
            }
        };
        $this->app->instance(RelationAuthorizer::class, $spy);
        DB::listen(function () use (&$events) {
            $events[] = 'query';
        });

        $service = new BaseService(ContractClient::class);
        $service->list_all(['relations' => ['notes']]);
        $this->assertSame('authorize', $events[0], 'authorized before the first query');
        $this->assertContains('query', $events);

        $events = [];
        $service->list_all(['relations' => ['notes']]);
        $this->assertSame(['authorize'], $events, 'a cache hit is still authorized (and runs no query)');
    }

    public function testFieldWriteAuthorizerCanBeReplaced(): void
    {
        $this->app->instance(FieldWriteAuthorizer::class, new class implements FieldWriteAuthorizer {
            public function deniedFields(Model $model, mixed $user): array { return ['name']; }
        });
        $this->assertSame(['name'], (new ContractClient())->getDeniedFieldsForUser(null));
        $this->assertSame([], (new ContractNote())->getDeniedFieldsForUser(null), 'no map declared: no restrictions');
    }

    public function testRelationAbilityNamerCanBeReplaced(): void
    {
        $this->app->instance(RelationAbilityNamer::class, new class extends RelationAbilityNamer {
            public function abilities(string $resource, array $hops): array { return ['relations:' . implode('.', $hops)]; }
        });
        $this->grantScope('crm.clients');
        Gate::define('relations:notes', fn () => true);
        config(['rest-generic-class.filtering.strict_relations' => true]);
        $this->assertTrue((new BaseService(ContractClient::class))->show(['relations' => ['notes']], 1)->relationLoaded('notes'));
        $this->assertInstanceOf(GateRelationAuthorizer::class, app(RelationAuthorizer::class));
    }

    private function grantScope(?string $resource): void
    {
        $this->app->bind(AuthorizationContext::class, fn () => new FixedContext(new AuthorizationScope(null, "{$resource}.show", $resource)));
    }

    private function bootProviderOutsideTheConsole(bool $expectFailure = true): void
    {
        $property = new \ReflectionProperty($this->app, 'isRunningInConsole');
        $original = $property->getValue($this->app);
        $property->setValue($this->app, false);
        try {
            (new RestGenericClassServiceProvider($this->app))->boot();
            if ($expectFailure) {
                $this->fail('Expected MissingAuthorizationContextException at boot');
            }
        } catch (MissingAuthorizationContextException $exception) {
            if (!$expectFailure) {
                throw $exception;
            }
            $this->assertStringContainsString('eager_relations=require_read', $exception->getMessage());
        } finally {
            $property->setValue($this->app, $original);
        }
    }
}

final class FixedContext implements AuthorizationContext
{
    public function __construct(private ?AuthorizationScope $scope) {}

    public function current(?Request $request = null): ?AuthorizationScope
    {
        return $this->scope;
    }
}

class ContractUser extends User
{
    protected $guarded = [];
}

class ContractClient extends BaseModel
{
    const RELATIONS = ['notes', 'owner'];
    protected $table = 'contract_clients';
    public $timestamps = false;
    protected array $fieldsByRole = ['admin' => ['name']];

    public function notes(): HasMany { return $this->hasMany(ContractNote::class, 'client_id'); }
    public function owner(): BelongsTo { return $this->belongsTo(ContractOwner::class, 'owner_id'); }
}

class ContractNote extends BaseModel
{
    protected $table = 'contract_notes';
    public $timestamps = false;
}

class ContractOwner extends BaseModel
{
    protected $table = 'contract_owners';
    public $timestamps = false;
}
