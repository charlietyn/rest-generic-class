<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Ronu\RestGenericClass\Core\Contracts\Authorization\AuthorizationContext;
use Ronu\RestGenericClass\Core\Contracts\Authorization\RelationAuthorizer;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Authorizes eager loads (?relations=) with authorization.eager_relations = require_read, asking
 * Laravel's Gate — so any permission backend that answers the Gate works.
 *
 * Each to-many hop of a requested path needs one of the RelationAbilityNamer alternatives
 * (default `<resource>.<path up to the hop>.read` or `….manage`), where <resource> comes from the
 * AuthorizationContext (admin.crm.clients.index → admin.crm.clients, so ?relations=pets needs
 * admin.crm.clients.pets.read|manage). To-one hops (belongsTo, hasOne, morphOne, hasOneThrough,
 * morphTo) are exempt while eager_relations_to_one = allow: they are the foreign-key labels any
 * listing shows. A denied relation is a 403 with filtering.strict_relations = true and is silently
 * left out otherwise.
 *
 * Requests without a scope (not authorized by any integration) are not checked. Asking for
 * require_read with no integration bound at all fails closed (MissingAuthorizationContextException).
 */
class GateRelationAuthorizer implements RelationAuthorizer
{
    public function __construct(
        private readonly AuthorizationContext $context,
        private readonly RelationAbilityNamer $namer,
    ) {
    }

    public function enabled(): bool
    {
        if (AuthorizationConfig::eagerRelations() !== AuthorizationConfig::REQUIRE_READ) {
            return false;
        }
        if ($this->context instanceof NullAuthorizationContext) {
            throw MissingAuthorizationContextException::forEagerRelations();
        }
        return $this->scope() !== null;
    }

    public function filter(Model $root, array $relations, ?callable $pathOf = null): array
    {
        if ($relations === [] || !$this->enabled()) {
            return $relations;
        }
        $pathOf ??= static fn ($relation) => is_string($relation) ? trim(explode(':', $relation, 2)[0]) : null;
        $allowed = [];
        foreach ($relations as $key => $relation) {
            // with(['name' => fn]) style entries carry the path in the key.
            $path = is_string($key) && !is_string($relation) ? trim(explode(':', $key, 2)[0]) : $pathOf($relation);
            $missing = $path === null || $path === '' ? null : $this->missing($root, $path);
            if ($missing === null) {
                $allowed[$key] = $relation;
                continue;
            }
            if (AuthorizationConfig::strictRelations()) {
                throw new HttpException(403, config('app.debug')
                    ? "Forbidden: relation '{$path}' requires one of " . implode(', ', $missing)
                    : 'Forbidden');
            }
        }
        return array_is_list($relations) ? array_values($allowed) : $allowed;
    }

    /**
     * Alternatives missing for the first denied hop of $path, or null when the path is allowed.
     *
     * @return string[]|null
     */
    public function missing(Model $root, string $path): ?array
    {
        $scope = $this->scope();
        $resource = $scope?->resource;
        $user = auth($scope?->guard)->user();
        $toOneExempt = AuthorizationConfig::eagerRelationsToOne() === AuthorizationConfig::ALLOW;

        $model = $root;
        $hops = [];
        foreach (explode('.', $path) as $segment) {
            $hops[] = $segment;
            $relation = $this->relation($model, $segment);
            if ($toOneExempt && $relation !== null && $this->isToOne($relation)) {
                $model = $relation instanceof MorphTo ? null : $relation->getRelated();
                continue;
            }
            $alternatives = $resource === null ? [] : $this->namer->abilities($resource, $hops);
            if ($alternatives === [] || !$this->allowsAny($user, $alternatives)) {
                return $alternatives ?: ['<unresolved resource>'];
            }
            $model = $relation?->getRelated();
        }
        return null;
    }

    /** @param string[] $abilities */
    protected function allowsAny(mixed $user, array $abilities): bool
    {
        return $user !== null && Gate::forUser($user)->any($abilities);
    }

    protected function scope(): ?AuthorizationScope
    {
        return $this->context->current();
    }

    /** The relation instance, or null when it cannot be inspected (then it is treated as to-many). */
    private function relation(?Model $model, string $name): ?Relation
    {
        if ($model === null || !method_exists($model, $name)) {
            return null;
        }
        try {
            $relation = $model->{$name}();
            return $relation instanceof Relation ? $relation : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isToOne(Relation $relation): bool
    {
        // MorphTo extends BelongsTo.
        return $relation instanceof BelongsTo
            || $relation instanceof HasOne
            || $relation instanceof MorphOne
            || $relation instanceof HasOneThrough;
    }
}
