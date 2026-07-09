<?php

namespace Ronu\RestGenericClass\Core\Contracts;

interface HasRestRelations
{
    /**
     * @return list<string>
     */
    public function getRestRelations(): array;
}
