<?php


namespace Ronu\RestGenericClass\Core\Extension\Eloquent\Relations;


class MongoBelongTo extends \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    public function addEagerConstraints(array $models)
    {
        $key = $this->ownerKey;
        $whereIn = $this->whereInMethod($this->related, $this->ownerKey);
        $this->whereInEager($whereIn, $key, $this->getEagerModelKeys($models));
    }
}