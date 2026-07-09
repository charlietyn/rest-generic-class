<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Contracts\HasRestRelations;
use Ronu\RestGenericClass\Core\Services\Support\RelationResolver;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\OrderTestClient;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\OrderTestUser;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class RelationResolverTest extends TestCase
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
            $capsule->setEventDispatcher(new Dispatcher(new Container()));
            $capsule->setAsGlobal();
            $capsule->bootEloquent();

            self::$booted = true;
        }

        $container = Container::getInstance();
        $container->instance('config', new ConfigRepository([
            'rest-generic-class' => [
                'filtering' => [
                    'strict_relations' => true,
                ],
            ],
        ]));
        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('config');
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('config');

        parent::tearDown();
    }

    public function testNormalizeParsesJsonFieldsAndAllShortcut(): void
    {
        $resolver = new RelationResolver();

        $normalized = $resolver->normalize('["user:id,name","user.posts:title"]', OrderTestClient::class);
        $all = $resolver->normalize('["all"]', OrderTestClient::class);

        $this->assertSame([
            [
                'relation' => 'user',
                'fields' => ['id', 'name'],
                'segments' => ['user'],
                'base' => 'user',
            ],
            [
                'relation' => 'user.posts',
                'fields' => ['title'],
                'segments' => ['user', 'posts'],
                'base' => 'user',
            ],
        ], $normalized);
        $this->assertSame(['user'], array_column($all, 'relation'));
    }

    public function testAllowedRelationsCanComeFromContract(): void
    {
        $model = new class extends Model implements HasRestRelations {
            public function getRestRelations(): array
            {
                return ['owner', 'items'];
            }
        };

        $this->assertSame(['owner', 'items'], (new RelationResolver())->allowedFor($model));
    }

    public function testExtractFiltersRejectsRelationsOutsideContract(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage("Relation 'role' is not allowed");

        (new RelationResolver())->extractFilters(['role' => ['and' => []]], OrderTestClient::class);
    }

    public function testRelatedModelAndRequiredFieldsAreResolved(): void
    {
        $resolver = new RelationResolver();

        $this->assertSame(OrderTestUser::class, $resolver->relatedModel(OrderTestClient::class, 'user'));
        $this->assertSame(['id', 'name'], $resolver->addRequiredFields(new OrderTestClient(), 'user', ['name']));
        $this->assertSame(['user_id', 'id', 'title'], $resolver->addRequiredFields(new OrderTestUser(), 'posts', ['title']));
    }
}
