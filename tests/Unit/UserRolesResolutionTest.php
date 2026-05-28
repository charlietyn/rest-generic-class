<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Traits\HasReadableUserPermissions;

final class UserRolesResolutionTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        parent::tearDown();
    }

    public function testNormalizeKeepsCollectionForManyToMany(): void
    {
        $user = new RolesResolutionFakeUser();

        $roleA = (object)['id' => 1, 'name' => 'admin'];
        $roleB = (object)['id' => 2, 'name' => 'editor'];

        $result = $user->publicNormalize(collect([$roleA, $roleB]));

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(2, $result);
        $this->assertSame([$roleA, $roleB], $result->all());
    }

    public function testNormalizeFiltersNullEntriesFromCollection(): void
    {
        $user = new RolesResolutionFakeUser();

        $role = (object)['id' => 1, 'name' => 'admin'];

        $result = $user->publicNormalize(collect([$role, null]));

        $this->assertCount(1, $result);
        $this->assertSame([$role], $result->all());
    }

    public function testNormalizeWrapsSingleModelForOneToMany(): void
    {
        $user = new RolesResolutionFakeUser();

        $role = new class extends Model {};

        $result = $user->publicNormalize($role);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertCount(1, $result);
        $this->assertSame($role, $result->first());
    }

    public function testNormalizeReturnsEmptyCollectionForNull(): void
    {
        $user = new RolesResolutionFakeUser();

        $result = $user->publicNormalize(null);

        $this->assertInstanceOf(Collection::class, $result);
        $this->assertTrue($result->isEmpty());
    }

    public function testRelationNamePrefersModelConstant(): void
    {
        // The constant short-circuits before config() is ever consulted.
        $user = new RolesResolutionFakeUserWithConst();

        $this->assertSame('array_role', $user->publicRelationName());
    }

    public function testRelationNameFallsBackToConfigThenDefault(): void
    {
        $container = new Container();
        Container::setInstance($container);

        $container->instance('config', new Repository([
            'rest-generic-class' => ['permissions' => ['roles_relation' => 'groups']],
        ]));

        $user = new RolesResolutionFakeUser();
        $this->assertSame('groups', $user->publicRelationName());

        // No configured value -> hardcoded default 'roles'.
        $container->instance('config', new Repository([]));
        $this->assertSame('roles', $user->publicRelationName());
    }
}

final class RolesResolutionFakeUser
{
    use HasReadableUserPermissions;

    public function publicNormalize($value): Collection
    {
        return $this->normalizeRolesValue($value);
    }

    public function publicRelationName(): string
    {
        return $this->rolesRelationName();
    }
}

final class RolesResolutionFakeUserWithConst
{
    use HasReadableUserPermissions;

    const ROLES_RELATION = 'array_role';

    public function publicRelationName(): string
    {
        return $this->rolesRelationName();
    }
}
