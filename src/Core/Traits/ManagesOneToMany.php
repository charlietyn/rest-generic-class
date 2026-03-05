<?php

namespace Ronu\RestGenericClass\Core\Traits;

/**
 * Trait ManagesOneToMany
 *
 * Backward-compatible alias for ManagesRelations.
 *
 * All one-to-many (HasMany) and many-to-many (BelongsToMany) relationship
 * logic has been unified into the ManagesRelations trait. This trait exists
 * so that existing controllers using `use ManagesOneToMany;` continue to
 * work without modification.
 *
 * For new controllers, prefer `use ManagesRelations;` directly.
 *
 * @see ManagesRelations
 * @deprecated Use ManagesRelations directly instead.
 */
trait ManagesOneToMany
{
    use ManagesRelations;
}
