<?php

namespace Ronu\RestGenericClass\Core\Contracts;

interface HasRestCache
{
    /**
     * @return array<int, class-string>
     */
    public function getCacheInvalidates(): array;
}
