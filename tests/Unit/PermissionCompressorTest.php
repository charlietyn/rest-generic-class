<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionCompressor;

final class PermissionCompressorTest extends TestCase
{
    private PermissionCompressor $compressor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compressor = new PermissionCompressor();
    }

    public function testItCompressesCompleteTablesToTableWildcards(): void
    {
        $result = $this->compressor->compress(
            collect([
                $this->permission('security.user.index'),
                $this->permission('security.user.show'),
                $this->permission('security.user.create'),
                $this->permission('security.role.index'),
            ]),
            $this->universe()
        );

        $this->assertSame([
            'security.user.*',
            'security.role.index',
        ], $result->all());
        $this->assertSame(4, $result->stats['original_count']);
        $this->assertSame(2, $result->stats['compressed_count']);
        $this->assertSame(2.0, $result->stats['compression_ratio']);
    }

    public function testItCompressesCompleteModulesToModuleWildcards(): void
    {
        $result = $this->compressor->compress(
            collect([
                'security.role.index',
                'security.user.create',
                'security.user.index',
                'security.user.show',
                'security.role.show',
            ]),
            $this->universe()
        );

        $this->assertSame(['security.*'], $result->all());
        $this->assertSame(5, $result->stats['original_count']);
        $this->assertSame(1, $result->stats['compressed_count']);
        $this->assertSame(5.0, $result->stats['compression_ratio']);
    }

    public function testGlobalWildcardIsDisabledByDefault(): void
    {
        $result = $this->compressor->compress($this->universe(), $this->universe());

        $this->assertSame([
            'billing.*',
            'reports.*',
            'security.*',
            'root',
        ], $result->all());
    }

    public function testGlobalWildcardCanBeEnabled(): void
    {
        $result = $this->compressor->compress(
            $this->universe(),
            $this->universe(),
            ['global_wildcard' => true]
        );

        $this->assertSame(['*'], $result->all());
        $this->assertSame(1, $result->stats['compressed_count']);
    }

    public function testPartialPermissionsRemainIndividual(): void
    {
        $result = $this->compressor->compress(
            collect([
                ['name' => 'security.user.index'],
                ['name' => 'security.user.show'],
                ['name' => 'security.role.index'],
                ['name' => 'billing.invoice.index'],
            ]),
            $this->universe()
        );

        $this->assertSame([
            'billing.invoice.index',
            'security.role.index',
            'security.user.index',
            'security.user.show',
        ], $result->all());
    }

    public function testTwoPartPermissionsCanParticipateInModuleWildcardsAndRootStaysIndividual(): void
    {
        $result = $this->compressor->compress(
            collect([
                'reports.export',
                'reports.view',
                'root',
            ]),
            $this->universe()
        );

        $this->assertSame(['reports.*', 'root'], $result->all());
    }

    public function testItNormalizesDuplicatesAndKeepsStableOrder(): void
    {
        $result = $this->compressor->compress(
            collect([
                'security.user.show',
                'security.user.index',
                'security.user.show',
                'billing.invoice.show',
                'billing.invoice.index',
            ]),
            $this->universe()
        );

        $this->assertSame([
            'billing.*',
            'security.user.index',
            'security.user.show',
        ], $result->all());
        $this->assertSame(4, $result->stats['original_count']);
    }

    public function testItCanIncludeExpandedPermissionNames(): void
    {
        $result = $this->compressor->compress(
            collect([
                'security.user.show',
                'security.user.index',
                'security.user.create',
            ]),
            $this->universe(),
            ['include_expanded' => true]
        );

        $this->assertSame(['security.user.*'], $result->all());
        $this->assertSame([
            'security.user.create',
            'security.user.index',
            'security.user.show',
        ], $result->expanded);
        $this->assertArrayHasKey('expanded', $result->toArray());
    }

    private function universe()
    {
        return collect([
            $this->permission('security.user.index'),
            $this->permission('security.user.show'),
            $this->permission('security.user.create'),
            $this->permission('security.role.index'),
            $this->permission('security.role.show'),
            $this->permission('billing.invoice.index'),
            $this->permission('billing.invoice.show'),
            $this->permission('reports.view'),
            $this->permission('reports.export'),
            $this->permission('root'),
        ]);
    }

    private function permission(string $name): object
    {
        return (object)['name' => $name];
    }
}
