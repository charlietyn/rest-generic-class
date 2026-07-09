<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OperFilterPipeline
{
    private Closure $applyFilters;
    private Closure $getDepth;
    private Closure $setDepth;
    private Closure $addConditionCount;
    private Closure $getConditionCount;

    public function __construct(
        private RelationResolver $relationResolver,
        callable $applyFilters,
        callable $getDepth,
        callable $setDepth,
        callable $addConditionCount,
        callable $getConditionCount
    ) {
        $this->applyFilters = Closure::fromCallable($applyFilters);
        $this->getDepth = Closure::fromCallable($getDepth);
        $this->setDepth = Closure::fromCallable($setDepth);
        $this->addConditionCount = Closure::fromCallable($addConditionCount);
        $this->getConditionCount = Closure::fromCallable($getConditionCount);
    }

    public function normalize(mixed $oper): array
    {
        if (empty($oper)) {
            return [];
        }

        if (is_string($oper)) {
            $decoded = json_decode($oper, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $oper = $decoded;
            }
        }

        if (!is_array($oper)) {
            return [];
        }

        if (array_is_list($oper)) {
            return ['and' => $oper];
        }

        $normalized = [];
        foreach ($oper as $key => $value) {
            if (in_array($key, ['and', 'or'], true) && !is_array($value)) {
                throw new HttpException(400, "Logical operator '{$key}' must have array value.");
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    public function apply(
        Builder $query,
        mixed $oper,
        string $boolean = 'and',
        object|string|null $modelClass = null,
        object|string|null $defaultModel = null
    ): Builder {
        ($this->setDepth)(($this->getDepth)() + 1);
        $maxDepth = config('rest-generic-class.filtering.max_depth', 5);

        if (($this->getDepth)() > $maxDepth) {
            throw new HttpException(400, "Maximum nesting depth ({$maxDepth}) exceeded.");
        }

        try {
            $normalized = $this->normalize($oper);

            if (empty($normalized)) {
                return $query;
            }

            $modelClass = $modelClass ?? $defaultModel;
            $baseOper = $this->relationResolver->stripFilters($normalized);
            $relationFilters = $this->relationResolver->extractFilters($normalized, $modelClass);

            if (!empty($baseOper)) {
                ($this->addConditionCount)($this->countConditions($baseOper));

                $maxConditions = config('rest-generic-class.filtering.max_conditions', 100);
                if (($this->getConditionCount)() > $maxConditions) {
                    throw new HttpException(400, "Maximum conditions ({$maxConditions}) exceeded.");
                }

                $query = ($this->applyFilters)($query, $baseOper, $boolean, $modelClass);
            }

            foreach ($relationFilters as $relationPath => $subOper) {
                $query = $this->applyNestedWhereHas($query, $relationPath, $subOper, $boolean, $modelClass);
            }

            return $query;
        } finally {
            ($this->setDepth)(($this->getDepth)() - 1);
        }
    }

    public function applyNestedWhereHas(
        Builder $query,
        string $relationPath,
        mixed $subOper,
        string $boolean,
        object|string $currentModel
    ): Builder {
        $method = $boolean === 'or' ? 'orWhereHas' : 'whereHas';

        if (str_contains($relationPath, '.')) {
            $segments = explode('.', $relationPath);
            $firstRelation = array_shift($segments);
            $remainingPath = implode('.', $segments);
            $relatedModel = $this->relationResolver->relatedModel($currentModel, $firstRelation);

            return $query->{$method}($firstRelation, function ($relationQuery) use ($remainingPath, $subOper, $boolean, $relatedModel) {
                if ($remainingPath) {
                    $this->applyNestedWhereHas($relationQuery, $remainingPath, $subOper, $boolean, $relatedModel);
                } else {
                    $this->apply($relationQuery, $subOper, $boolean, $relatedModel, $relatedModel);
                }
            });
        }

        $relatedModel = $this->relationResolver->relatedModel($currentModel, $relationPath);

        return $query->{$method}($relationPath, function ($relationQuery) use ($subOper, $boolean, $relatedModel) {
            $this->apply($relationQuery, $subOper, $boolean, $relatedModel, $relatedModel);
        });
    }

    private function countConditions(array $baseOper): int
    {
        $count = 0;

        foreach ($baseOper as $conditions) {
            if (is_array($conditions)) {
                $count += count($conditions);
            }
        }

        return $count;
    }
}
