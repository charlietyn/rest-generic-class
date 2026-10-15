<?php
declare(strict_types=1);

namespace Ronu\RestGenericClass\Core\Support\Authorization;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\Relation;
use Ronu\RestGenericClass\Core\Middleware\SpatieAuthorize;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Authorizes eager loads (?relations=) with permissions.authorization.eager_relations = require_read.
 *
 * Each to-many hop of a requested path needs `<resource>.<path up to the hop>.read` or `….manage`, where
 * <resource> is the authorized route name without its last segment (admin.crm.clients.index →
 * admin.crm.clients, so ?relations=pets needs admin.crm.clients.pets.read|manage). To-one hops
 * (belongsTo, hasOne, morphOne, hasOneThrough, morphTo) are exempt while eager_relations_to_one = allow:
 * they are the foreign-key labels any listing shows. A denied relation is a 403 with
 * filtering.strict_relations = true and is silently left out otherwise.
 *
 * Only requests authorized by SpatieAuthorize carry the context; without it nothing is checked.
 */
final class EagerRelationAuthorizer
{
    public function enabled(): bool
    {
        return config('rest-generic-class.permissions.authorization.eager_relations', 'off') === 'require_read'
            && $this->context() !== null;
    }

    /**
     * Keep the authorized entries of a relation list (`pets`, `pets.vaccines`, `owner:id,name`).
     *
     * @param array<int|string, mixed> $relations
     * @return array<int|string, mixed>
     * @throws HttpException 403 in strict mode when one is denied.
     */
    public function filter(Model $root, array $relations, ?callable $pathOf = null): array
    {
        if (!$this->enabled()) {
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
            if (config('rest-generic-class.filtering.strict_relations', true)) {
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
        $context = $this->context();
        $resource = $context['resource'] ?? null;
        $user = auth($context['guard'] ?? null)->user();
        $toOneExempt = config('rest-generic-class.permissions.authorization.eager_relations_to_one', 'allow') === 'allow';

        $model = $root;
        $hops = [];
        foreach (explode('.', $path) as $segment) {
            $hops[] = $segment;
            $relation = $this->relation($model, $segment);
            if ($toOneExempt && $relation !== null && $this->isToOne($relation)) {
                $model = $relation instanceof \Illuminate\Database\Eloquent\Relations\MorphTo ? null : $relation->getRelated();
                continue;
            }
            $prefix = $resource === null ? null : $resource . '.' . implode('.', $hops);
            $alternatives = $prefix === null ? [] : ["{$prefix}.read", "{$prefix}.manage"];
            if ($alternatives === [] || !app(PermissionDecisionEvaluator::class)->canAny($user, $alternatives)) {
                return $alternatives ?: ['<unresolved resource>'];
            }
            $model = $relation?->getRelated();
        }
        return null;
    }

    private function context(): ?array
    {
        $request = app()->bound('request') ? app('request') : null;
        $context = $request?->attributes->get(SpatieAuthorize::CONTEXT_ATTRIBUTE);
        return is_array($context) ? $context : null;
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
