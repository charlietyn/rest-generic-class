<?php

namespace Ronu\RestGenericClass\Core\Contracts;

interface HasRestAggregates
{
    /** @return array<string, list<string>> Column => permitted aggregate functions. */
    public function getRestAggregateColumns(): array;
}
