<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Ronu\RestGenericClass\Core\Support\Permissions\ActionPattern;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionCompressor;
use Ronu\RestGenericClass\Core\Support\Permissions\TargetPermissionResolver;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelAuthorizationTestCase;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\ChannelPermission;

final class ActionPatternAssignmentTest extends ChannelAuthorizationTestCase
{
    private const CATALOG = [
        'admin.crm.clients.index',
        'admin.crm.clients.show',
        'admin.crm.clients.update',
        'admin.crm.clients.pets.read',
        'admin.crm.clients.pets.manage',
        'admin.crm.clients-notes.read',
        'admin.crm.leads.index',
        'admin.billing.invoices.index',
        'client.crm.clients.index',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        foreach (self::CATALOG as $name) {
            $this->permission($name);
        }
        $this->permission('admin.crm.clients.index', 'web');
    }

    public function testEntityPatternIncludesItsRelationsButNotSimilarNames(): void
    {
        $this->assertEqualsCanonicalizing([
            'admin.crm.clients.index',
            'admin.crm.clients.show',
            'admin.crm.clients.update',
            'admin.crm.clients.pets.read',
            'admin.crm.clients.pets.manage',
        ], $this->resolve(['admin.crm.clients.*']));
        $this->assertEqualsCanonicalizing(['admin.crm.clients.pets.read', 'admin.crm.clients.pets.manage'], $this->resolve(['admin.crm.clients.pets.*']));
        $this->assertCount(8, $this->resolve(['admin.*']));
        $this->assertCount(9, $this->resolve(['*']));
    }

    public function testPatternsMixWithExactNamesAndRespectTheGuard(): void
    {
        $this->assertEqualsCanonicalizing(
            ['admin.billing.invoices.index', 'admin.crm.leads.index'],
            $this->resolve(['admin.billing.*', 'admin.crm.leads.index'])
        );
        [$web] = (new TargetPermissionResolver())->resolve('web', ['admin.*'], null, null, null, null);
        $this->assertSame(['admin.crm.clients.index'], $web->pluck('name')->all());
    }

    public function testRoleFileWithPatternsGrantsTheSameAsExactNames(): void
    {
        $patterns = base_path('rgc-role-patterns.json');
        $exact = base_path('rgc-role-exact.json');
        file_put_contents($patterns, json_encode(['admin.crm.clients.*', 'admin.billing.*']));
        file_put_contents($exact, json_encode([
            'admin.crm.clients.index', 'admin.crm.clients.show', 'admin.crm.clients.update',
            'admin.crm.clients.pets.read', 'admin.crm.clients.pets.manage', 'admin.billing.invoices.index',
        ]));
        try {
            $resolver = new TargetPermissionResolver();
            [$fromPatterns] = $resolver->resolve('api', null, null, 'rgc-role-patterns.json', null, null);
            [$fromExact] = $resolver->resolve('api', null, null, 'rgc-role-exact.json', null, null);
            $this->assertEqualsCanonicalizing($fromExact->pluck('id')->all(), $fromPatterns->pluck('id')->all());
            $this->assertCount(6, $fromPatterns);
        } finally {
            @unlink($patterns);
            @unlink($exact);
        }
    }

    public function testPrefixIsBoundedBySegments(): void
    {
        foreach (['admin.crm.clients', 'admin.crm.clients.', 'admin.crm.clients.*'] as $prefix) {
            [$perms] = (new TargetPermissionResolver())->resolve('api', null, $prefix, null, null, null);
            $this->assertNotContains('admin.crm.clients-notes.read', $perms->pluck('name')->all(), $prefix);
            $this->assertCount(5, $perms, $prefix);
        }
    }

    public function testPatternMatchingNothingGrantsNothingInsteadOfEverything(): void
    {
        [$perms, , $usedDefaultAll] = (new TargetPermissionResolver())->resolve('api', ['admin.nothing.*'], null, null, null, null);
        $this->assertCount(0, $perms);
        $this->assertFalse($usedDefaultAll);
    }

    public function testMisplacedWildcardIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new TargetPermissionResolver())->resolve('api', ['admin.*.index'], null, null, null, null);
    }

    public function testPatternGrammar(): void
    {
        foreach (['*', 'admin.*', 'admin.crm.clients.*'] as $valid) {
            $this->assertTrue(ActionPattern::isValid($valid), $valid);
        }
        foreach (['admin*', 'admin.*.index', '*.index', '.*', 'admin..*', 'admin.**'] as $invalid) {
            $this->assertFalse(ActionPattern::isValid($invalid), $invalid);
        }
        $this->assertTrue(ActionPattern::matches('admin.crm.clients.pets.read', 'admin.crm.clients.*'));
        $this->assertFalse(ActionPattern::matches('admin.crm.clients-notes.read', 'admin.crm.clients.*'));
        $this->assertFalse(ActionPattern::matches('admin.crm.clients', 'admin.crm.clients.*'));
    }

    public function testEntityLookupIsCaseInsensitiveWithoutIlike(): void
    {
        ChannelPermission::where('name', 'admin.crm.leads.index')->update(['model' => 'Leads', 'module' => 'CRM']);
        [$perms] = (new TargetPermissionResolver())->resolve('api', null, null, null, null, ['crm.leads']);
        $this->assertSame(['admin.crm.leads.index'], $perms->pluck('name')->all());
    }

    public function testCompressorWorksAtFourAndFiveSegments(): void
    {
        $compressor = new PermissionCompressor();
        $universe = collect(self::CATALOG);

        $clients = ['admin.crm.clients.index', 'admin.crm.clients.show', 'admin.crm.clients.update',
            'admin.crm.clients.pets.read', 'admin.crm.clients.pets.manage'];
        $this->assertSame(['admin.crm.clients.*'], $compressor->compress(collect($clients), $universe)->all());

        $this->assertSame(
            ['admin.crm.clients.pets.*', 'admin.crm.clients.index'],
            $compressor->compress(collect(['admin.crm.clients.index', 'admin.crm.clients.pets.read', 'admin.crm.clients.pets.manage']), $universe)->all()
        );

        $admin = array_values(array_filter(self::CATALOG, fn ($name) => str_starts_with($name, 'admin.')));
        $this->assertSame(['admin.*'], $compressor->compress(collect($admin), $universe)->all());

        $crm = array_values(array_filter($admin, fn ($name) => str_starts_with($name, 'admin.crm.')));
        $this->assertSame(['admin.crm.*'], $compressor->compress(collect($crm), $universe)->all());

        // Every compressed pattern expands back to exactly the subject.
        $subject = ['admin.crm.leads.index', 'admin.crm.clients.pets.read', 'admin.crm.clients.pets.manage', 'client.crm.clients.index'];
        $compressed = $compressor->compress(collect($subject), $universe)->all();
        $this->assertSame(['client.*', 'admin.crm.leads.*', 'admin.crm.clients.pets.*'], $compressed);
        $expanded = collect($compressed)->flatMap(fn ($p) => ActionPattern::isPattern($p) ? ActionPattern::expand($p, self::CATALOG) : [$p]);
        $this->assertEqualsCanonicalizing($subject, $expanded->all());
    }

    /** @return string[] */
    private function resolve(array $perms): array
    {
        [$resolved] = (new TargetPermissionResolver())->resolve('api', $perms, null, null, null, null);
        return $resolved->pluck('name')->all();
    }
}
