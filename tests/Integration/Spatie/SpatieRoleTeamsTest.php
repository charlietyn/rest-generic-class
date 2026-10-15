<?php

namespace Ronu\RestGenericClass\Tests\Integration\Spatie;

use Ronu\RestGenericClass\Core\Models\SpatieRole;
use Spatie\Permission\Exceptions\RoleAlreadyExists;
use Spatie\Permission\Exceptions\RoleDoesNotExist;

final class SpatieRoleTeamsTest extends SpatieTestCase
{
    protected bool $teams = true;

    public function testRolesAreIsolatedPerTeamWithEnumsAndStrings(): void
    {
        $teamKey = config('permission.column_names.team_foreign_key');

        setPermissionsTeamId(1);
        $first = SpatieRole::findOrCreate(StringName::Editor);
        $this->assertSame(1, (int) $first->{$teamKey});
        $this->assertSame($first->getKey(), SpatieRole::findOrCreate('editor')->getKey());

        setPermissionsTeamId(2);
        $second = SpatieRole::findOrCreate('editor');
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(2, (int) $second->{$teamKey});
        $this->assertSame($second->getKey(), SpatieRole::findByName(StringName::Editor)->getKey());

        setPermissionsTeamId(3);
        try {
            SpatieRole::findByName(StringName::Editor);
            $this->fail('a role of another team must not be found');
        } catch (RoleDoesNotExist $e) {
            $this->assertStringContainsString('editor', $e->getMessage());
        }
    }

    public function testGlobalRolesAreSharedAndDuplicatesAreDetectedPerTeam(): void
    {
        $teamKey = config('permission.column_names.team_foreign_key');

        $global = SpatieRole::create(['name' => StringName::Editor, $teamKey => null]);
        setPermissionsTeamId(5);
        $this->assertSame($global->getKey(), SpatieRole::findByName(StringName::Editor)->getKey());

        $this->expectException(RoleAlreadyExists::class);
        SpatieRole::create(['name' => StringName::Editor]);
    }
}
