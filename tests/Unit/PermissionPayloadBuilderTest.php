<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Support\Permissions\PermissionPayloadBuilder;

final class PermissionPayloadBuilderTest extends TestCase
{
    public function testListNormalizesCommaStringsArraysAndDuplicates(): void
    {
        $builder = new PermissionPayloadBuilder();

        $this->assertSame(['security', 'billing'], $builder->list('security, billing,security'));
        $this->assertSame(['security', 'billing'], $builder->list(['security,billing', 'security']));
        $this->assertNull($builder->list(''));
        $this->assertNull($builder->list(null));
    }

    public function testBuildsFlatPayloadFromFilteredCallback(): void
    {
        $builder = new PermissionPayloadBuilder();
        $request = Request::create('/permissions', 'GET', [
            'guard' => 'api',
        ]);

        $payload = $builder->build(
            $request,
            ['user' => ['id' => 10]],
            fn() => collect([
                (object)[
                    'id' => 1,
                    'name' => 'security.user.index',
                    'module' => 'security',
                    'guard_name' => 'api',
                ],
            ]),
            fn() => ['permissions' => ['security.*']]
        );

        $this->assertSame(['id' => 10], $payload['user']);
        $this->assertSame('api', $payload['guard']);
        $this->assertSame(1, $payload['count']);
        $this->assertSame([
            ['id' => 1, 'name' => 'security.user.index', 'module' => 'security', 'guard' => 'api'],
        ], $payload['permissions']);
    }

    public function testBuildsCompressedPayloadWithNormalizedFiltersAndOptions(): void
    {
        $builder = new PermissionPayloadBuilder();
        $request = Request::create('/permissions', 'GET', [
            'compress' => 'true',
            'expand' => 'true',
            'compress_global' => 'false',
            'guard' => 'api',
            'modules' => 'security,billing',
            'entities' => ['security.user', 'billing.invoice'],
        ]);

        $call = [];
        $payload = $builder->build(
            $request,
            'session-1',
            fn() => collect(),
            function (?string $guard, ?array $modules, ?array $entities, array $options) use (&$call): array {
                $call = compact('guard', 'modules', 'entities', 'options');

                return [
                    'permissions' => ['security.*'],
                    'stats' => ['original_count' => 3, 'compressed_count' => 1],
                ];
            }
        );

        $this->assertSame('session-1', $payload['context']);
        $this->assertSame(['security.*'], $payload['permissions']);
        $this->assertSame([
            'guard' => 'api',
            'modules' => ['security', 'billing'],
            'entities' => ['security.user', 'billing.invoice'],
            'options' => [
                'module_wildcard' => true,
                'table_wildcard' => true,
                'global_wildcard' => false,
                'include_expanded' => true,
            ],
        ], $call);
    }
}
