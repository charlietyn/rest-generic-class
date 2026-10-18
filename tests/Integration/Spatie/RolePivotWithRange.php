<?php

namespace Ronu\RestGenericClass\Tests\Integration\Spatie;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** The role_has_permissions pivot of the generated apps: a validity window and an active($at) scope. */
class RolePivotWithRange extends Pivot
{
    protected $table = 'role_has_permissions';

    public function scopeActive(Builder $query, $at = null): Builder
    {
        $at = $at ?: now();
        $table = $this->getTable();

        return $query->where(function ($q) use ($table, $at) {
            $q->where("{$table}.range", false)
                ->orWhereNull("{$table}.range")
                ->orWhere(function ($inner) use ($table, $at) {
                    $inner->where("{$table}.range", true)
                        ->where(fn ($start) => $start->whereNull("{$table}.start_range")->orWhere("{$table}.start_range", '<=', $at))
                        ->where(fn ($end) => $end->whereNull("{$table}.end_range")->orWhere("{$table}.end_range", '>=', $at));
                });
        });
    }
}
