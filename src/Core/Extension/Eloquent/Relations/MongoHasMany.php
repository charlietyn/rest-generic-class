<?php


namespace Ronu\RestGenericClass\Core\Extension\Eloquent\Relations;


use MongoDB\Laravel\Relations\HasMany;

class MongoHasMany extends HasMany
{
    public function addEagerConstraints(array $models)
    {
        $whereIn = $this->whereInMethod($this->parent, $this->localKey);
        $this->whereInEager(
            $whereIn,
            $this->foreignKey,
            $this->getKeys($models, $this->localKey),
            $this->getRelationQuery()
        );
    }
}