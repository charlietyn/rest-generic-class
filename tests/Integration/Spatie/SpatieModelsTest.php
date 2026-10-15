<?php

namespace Ronu\RestGenericClass\Tests\Integration\Spatie;

use Ronu\RestGenericClass\Core\Models\SpatiePermission;
use Ronu\RestGenericClass\Core\Models\SpatieRole;
use Spatie\Permission\Contracts\Permission as PermissionContract;
use Spatie\Permission\Contracts\Role as RoleContract;
use Spatie\Permission\Exceptions\PermissionAlreadyExists;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Exceptions\RoleDoesNotExist;
use Spatie\Permission\PermissionRegistrar;

final class SpatieModelsTest extends SpatieTestCase
{
    public function testAUserComposedWithHasRolesAndTheRonuReadersLoadsAndResolvesEnums(): void
    {
        \Illuminate\Support\Facades\Schema::create('users', fn ($t) => [$t->increments('id'), $t->string('name')]);
        $user = IntegrationUser::query()->create(['name' => 'ana']);
        $role = SpatieRole::findOrCreate(StringName::Editor);
        $role->givePermissionTo(SpatiePermission::findOrCreate(StringName::Edit));
        $user->assignRole(StringName::Editor->value);

        $this->assertTrue($user->fresh()->hasPermissionTo(StringName::Edit->value));
        $this->assertSame(['editor'], $user->fresh()->getRoleNames()->all());
    }

    /** @return array<string, array{class-string}> */
    public static function models(): array
    {
        return ['permission' => [SpatiePermission::class], 'role' => [SpatieRole::class]];
    }

    /** Fails on Spatie 7+ with a fatal declaration error, and on Spatie 6 by the type check, with the v2.8.0 code. */
    public function testModelsLoadAgainstTheRealSpatieContracts(): void
    {
        $this->assertTrue(is_subclass_of(SpatiePermission::class, PermissionContract::class));
        $this->assertTrue(is_subclass_of(SpatieRole::class, RoleContract::class));
        foreach ([SpatiePermission::class, SpatieRole::class] as $class) {
            foreach (['findByName', 'findOrCreate'] as $method) {
                $type = (string) (new \ReflectionMethod($class, $method))->getParameters()[0]->getType();
                $this->assertSame('BackedEnum|string', $type, "$class::$method");
            }
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function testStringAndEnumResolveTheSameRecordWithoutDuplicates(string $class): void
    {
        $name = $class === SpatieRole::class ? StringName::Editor : StringName::Edit;

        $created = $class::findOrCreate($name);
        $this->assertSame($name->value, $created->name);
        $this->assertSame('web', $created->guard_name);

        $this->assertSameRecord($created, $class::findOrCreate($name->value));
        $this->assertSameRecord($created, $class::findOrCreate($name));
        $this->assertSameRecord($created, $class::findByName($name));
        $this->assertSameRecord($created, $class::findByName($name->value));
        $this->assertSame(1, $class::query()->where('name', $name->value)->count());
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function testFindByNameOfAnExistingRecordCreatedWithAString(string $class): void
    {
        $existing = $class::create(['name' => 'editor']);
        $this->assertSameRecord($existing, $class::findByName(StringName::Editor));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function testIntBackedEnumIsANameNotAnId(string $class): void
    {
        $other = $class::create(['name' => 'other']);
        $this->assertSame(1, (int) $other->getKey());

        $byEnum = $class::findOrCreate(IntName::One);
        $this->assertNotSame($other->getKey(), $byEnum->getKey(), 'value 1 must not resolve the record with id 1');
        $this->assertSame('1', $byEnum->name);
        $this->assertSameRecord($byEnum, $class::findByName(IntName::One));
        $this->assertSameRecord($byEnum, $class::findByName('1'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('models')]
    public function testGuardsAreExplicitDefaultAndSeparated(string $class): void
    {
        $web = $class::findOrCreate(StringName::Edit);
        $api = $class::findOrCreate(StringName::Edit, 'api');

        $this->assertSame('web', $web->guard_name);
        $this->assertSame('api', $api->guard_name);
        $this->assertNotSame($web->getKey(), $api->getKey());
        $this->assertSameRecord($api, $class::findByName(StringName::Edit, 'api'));
        $this->assertSameRecord($web, $class::findByName(StringName::Edit));
    }

    public function testMissingNamesThrowWithTheEnumValue(): void
    {
        foreach ([[SpatiePermission::class, PermissionDoesNotExist::class], [SpatieRole::class, RoleDoesNotExist::class]] as [$class, $exception]) {
            try {
                $class::findByName(StringName::Edit, 'api');
                $this->fail("$class: expected $exception");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($exception, $e);
                $this->assertStringContainsString('articles.edit', $e->getMessage());
                $this->assertStringContainsString('api', $e->getMessage());
            }
        }
    }

    public function testCreatingADuplicateThrowsWithTheEnumValue(): void
    {
        foreach ([[SpatiePermission::class, PermissionAlreadyExists::class], [SpatieRole::class, RoleAlreadyExists::class]] as [$class, $exception]) {
            $class::create(['name' => StringName::Edit]);
            $this->assertSame('articles.edit', $class::query()->value('name'), 'create() persists the enum value');
            try {
                $class::create(['name' => StringName::Edit->value]);
                $this->fail("$class: expected $exception");
            } catch (\Throwable $e) {
                $this->assertInstanceOf($exception, $e);
                $this->assertStringContainsString('articles.edit', $e->getMessage());
            }
        }
    }

    public function testThePermissionCacheIsInvalidatedAndRead(): void
    {
        try {
            SpatiePermission::findByName(StringName::Edit);
            $this->fail('expected a miss');
        } catch (PermissionDoesNotExist) {
            // The miss loads (and caches) the empty catalog.
        }

        // Creating through the enum refreshes the cached catalog, so the next lookup finds it.
        $created = SpatiePermission::findOrCreate(StringName::Edit);
        $this->assertSameRecord($created, SpatiePermission::findByName(StringName::Edit));

        // Lookups are served from the cache: a row removed behind its back is still seen...
        SpatiePermission::query()->toBase()->delete();
        $this->assertSameRecord($created, SpatiePermission::findByName(StringName::Edit));

        // ...until the cache is forgotten.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->expectException(PermissionDoesNotExist::class);
        SpatiePermission::findByName(StringName::Edit);
    }

    /** Spatie hydrates cached models without a connection name, so compare identity by table and key. */
    private function assertSameRecord($expected, $actual): void
    {
        $this->assertSame($expected->getTable(), $actual->getTable());
        $this->assertSame($expected->getKey(), $actual->getKey());
    }
}
