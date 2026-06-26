<?php

namespace Ronu\RestGenericClass\Core\Traits;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Column-aware soft delete for generated models.
 *
 * This trait is the CANONICAL mechanism by which a soft-deletable model joins
 * the soft-delete lifecycle in the rest-generic-class ecosystem. Apply it ONLY
 * to models that are soft-deletable; models without it keep the historic
 * behaviour of physical deletes (full backward compatibility).
 *
 * It builds on Laravel's native {@see SoftDeletes} trait — so the model gets,
 * for free and fully column-aware:
 *   - a write-path where delete() sets the soft-delete column instead of issuing
 *     a physical DELETE,
 *   - restore() / forceDelete(),
 *   - withTrashed() / onlyTrashed() / trashed(),
 *   - the global SoftDeletingScope that excludes trashed rows from reads,
 *   - restoring/restored/forceDeleting/forceDeleted/trashed events.
 *
 * The only thing this trait changes from the native behaviour is WHICH column
 * stores the timestamp: instead of the hardcoded `deleted_at`, it resolves the
 * column from the model via getSoftDeleteColumn() (declared on BaseModel). That
 * lets the generator drive soft delete entirely by declaring:
 *
 *     protected ?string $softDeleteColumn = 'deleted_at';   // or 'archived_at', ...
 *     use InteractsWithSoftDelete;
 *
 * Column contract: the column is assumed to be a `timestamp NULL` (deleted_at
 * style). A boolean flag column is NOT supported by this mechanism — that would
 * be a different strategy and must not be wired through here.
 *
 * @see \Ronu\RestGenericClass\Core\Models\BaseModel::getSoftDeleteColumn()
 */
trait InteractsWithSoftDelete
{
    use SoftDeletes;

    /**
     * Resolve the soft-delete column for every native SoftDeletes operation.
     *
     * Overrides SoftDeletes::getDeletedAtColumn() so that the scope, the
     * delete/restore write-path, casts, and qualified-column helpers all use
     * the model-declared column. Falls back to 'deleted_at' when the model has
     * not declared one (defensive — a model using this trait is expected to
     * declare $softDeleteColumn).
     */
    public function getDeletedAtColumn(): string
    {
        return $this->getSoftDeleteColumn() ?? 'deleted_at';
    }
}
