<?php


namespace Ronu\RestGenericClass\Core\Extension\Eloquent\Relations;


use MongoDB\Laravel\Relations\HasMany;
/**
 * Class MongoHasMany
 *
 * Custom implementation of the HasMany relationship for MongoDB.
 */
class MongoHasMany extends HasMany
{
    /**
     * Adds constraints for eager loading.
     *
     * @param array $models The models to apply constraints to.
     * @return void
     */
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