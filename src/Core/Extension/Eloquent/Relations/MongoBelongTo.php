<?php


namespace Ronu\RestGenericClass\Core\Extension\Eloquent\Relations;

/**
 * Class MongoBelongTo
 *
 * Custom implementation of the BelongsTo relationship for MongoDB.
 */
class MongoBelongTo extends \Illuminate\Database\Eloquent\Relations\BelongsTo
{
    /**
     * Adds constraints for eager loading.
     *
     * @param array $models The models to apply constraints to.
     * @return void
     */
    public function addEagerConstraints(array $models)
    {
        $key = $this->ownerKey;
        $whereIn = $this->whereInMethod($this->related, $this->ownerKey);
        $this->whereInEager($whereIn, $key, $this->getEagerModelKeys($models));
    }
}