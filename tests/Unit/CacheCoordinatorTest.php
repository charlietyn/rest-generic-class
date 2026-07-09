<?php

namespace Ronu\RestGenericClass\Tests\Unit;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Ronu\RestGenericClass\Core\Services\Support\CacheCoordinator;
use Ronu\RestGenericClass\Tests\Unit\Fixtures\HardItem;

final class CacheCoordinatorTest extends TestCase
{
    private ConfigRepository $config;
    private CacheRepository $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new ConfigRepository([
            'rest-generic-class' => [
                'cache' => [
                    'enabled' => false,
                    'store' => null,
                    'ttl' => 60,
                    'ttl_by_method' => [
                        'list_all' => 60,
                        'get_one' => 30,
                    ],
                    'cacheable_methods' => ['list_all', 'get_one'],
                    'vary' => [
                        'headers' => ['Accept-Language', 'X-Tenant-Id'],
                    ],
                ],
            ],
        ]);
        $this->cache = new CacheRepository(new ArrayStore());

        $container = new Container();
        $container->instance('config', $this->config);
        $container->instance('cache', new class($this->cache) {
            public function __construct(private CacheRepository $repository)
            {
            }

            public function store($name = null): CacheRepository
            {
                return $this->repository;
            }
        });

        Container::setInstance($container);
        Facade::setFacadeApplication($container);
        Facade::clearResolvedInstance('cache');
        Facade::clearResolvedInstance('config');
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstance('cache');
        Facade::clearResolvedInstance('config');

        parent::tearDown();
    }

    public function testShouldUseCacheHonorsGlobalConfigAndRequestOverride(): void
    {
        $coordinator = $this->coordinator();

        $this->assertFalse($coordinator->shouldUse('list_all', []));

        $this->config->set('rest-generic-class.cache.enabled', true);

        $this->assertTrue($coordinator->shouldUse('list_all', []));
        $this->assertFalse($coordinator->shouldUse('destroy', []));
        $this->assertFalse($coordinator->shouldUse('list_all', ['cache' => false]));
    }

    public function testServiceOverridesGlobalCacheToggle(): void
    {
        $this->config->set('rest-generic-class.cache.enabled', false);

        $forcedOn = $this->coordinator(cacheable: true, operations: ['list_all']);
        $forcedOff = $this->coordinator(cacheable: false, operations: ['list_all']);

        $this->assertTrue($forcedOn->shouldUse('list_all', []));
        $this->assertFalse($forcedOff->shouldUse('list_all', []));
    }

    public function testBumpVersionSkipsWhenDisabledAndIncrementsWhenEnabled(): void
    {
        $disabled = $this->coordinator();
        $disabled->bumpVersion();

        $this->assertSame(1, $disabled->modelVersion());

        $forcedOn = $this->coordinator(cacheable: true, operations: ['list_all']);
        $forcedOn->bumpVersion();

        $this->assertSame(2, $forcedOn->modelVersion());
    }

    public function testRememberUsesCachedValueUntilModelVersionChanges(): void
    {
        $this->config->set('rest-generic-class.cache.enabled', true);
        $coordinator = $this->coordinator();
        $calls = 0;

        $first = $coordinator->remember('list_all', ['eq' => ['name' => 'alpha']], function () use (&$calls) {
            $calls++;
            return ['calls' => $calls];
        });
        $second = $coordinator->remember('list_all', ['eq' => ['name' => 'alpha']], function () use (&$calls) {
            $calls++;
            return ['calls' => $calls];
        });

        $coordinator->bumpVersion();

        $third = $coordinator->remember('list_all', ['eq' => ['name' => 'alpha']], function () use (&$calls) {
            $calls++;
            return ['calls' => $calls];
        });

        $this->assertSame(['calls' => 1], $first);
        $this->assertSame(['calls' => 1], $second);
        $this->assertSame(['calls' => 2], $third);
    }

    private function coordinator(
        ?bool $cacheable = null,
        ?int $ttl = null,
        array $operations = []
    ): CacheCoordinator {
        return new CacheCoordinator(
            new HardItem(),
            'rgc:v1',
            $cacheable,
            $ttl,
            $operations,
            fn (array $params): array => []
        );
    }
}
