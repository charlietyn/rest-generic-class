<?php

namespace Ronu\RestGenericClass\Core\Contracts;

interface HasRestSoftDeletes
{
    public function getSoftDeleteColumn(): ?string;
}
