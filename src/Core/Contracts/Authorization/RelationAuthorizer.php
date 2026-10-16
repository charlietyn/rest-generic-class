<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Contracts\Authorization;

use Illuminate\Database\Eloquent\Model;

/**
 * Decides which eager loads (?relations=) the current user may perform.
 *
 * Called by BaseService (before the cache lookup) and by the relation endpoints.
 */
interface RelationAuthorizer
{
    /** Whether requested relations must be checked for the current request. */
    public function enabled(): bool;

    /**
     * Keep the authorized entries of a relation list, in the same shape as given
     * (`pets`, `pets.vaccines`, `owner:id,name`, `['pets' => Closure]`).
     *
     * @param array<int|string, mixed> $relations
     * @param (callable(mixed): ?string)|null $pathOf Extracts the relation path of an entry.
     * @return array<int|string, mixed>
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException 403 when a relation is denied in strict mode.
     */
    public function filter(Model $root, array $relations, ?callable $pathOf = null): array;
}
