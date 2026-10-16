<?php

namespace Ronu\RestGenericClass\Tests\Unit\Fixtures;

/**
 * Testbench installs static paginator callbacks bound to its application. Restore them after each
 * test so the standalone Capsule tests that run later remain independent.
 *
 * Call capturePaginationResolvers() before parent::setUp() and restorePaginationResolvers() after
 * parent::tearDown().
 */
trait RestoresPaginationResolvers
{
    private array $paginationResolvers = [];

    protected function capturePaginationResolvers(): void
    {
        foreach ([\Illuminate\Pagination\AbstractPaginator::class, \Illuminate\Pagination\AbstractCursorPaginator::class] as $class) {
            foreach ((new \ReflectionClass($class))->getProperties(\ReflectionProperty::IS_STATIC) as $property) {
                if (str_ends_with($property->getName(), 'Resolver')) {
                    $this->paginationResolvers[] = [$property, $property->getValue()];
                }
            }
        }
    }

    protected function restorePaginationResolvers(): void
    {
        foreach ($this->paginationResolvers as [$property, $value]) {
            $property->setValue(null, $value);
        }
        $this->paginationResolvers = [];
    }
}
