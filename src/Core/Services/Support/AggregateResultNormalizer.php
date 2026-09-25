<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class AggregateResultNormalizer
{
    public function value(string $function, mixed $value): mixed
    {
        return match ($function) {
            'count' => (int) $value,
            'sum' => (string) ($value ?? 0),
            'avg' => $value === null ? null : (string) $value,
            default => $value,
        };
    }

    public function records(mixed $result, array $specs): mixed
    {
        if ($result instanceof Collection) {
            foreach ($result as $model) {
                if ($model instanceof Model) {
                    foreach ($specs as $spec) {
                        $attributes = $model->getAttributes();
                        if (array_key_exists($spec['as'], $attributes)) {
                            $model->setAttribute($spec['as'], $this->value($spec['function'], $attributes[$spec['as']]));
                            $model->makeVisible($spec['as']);
                        }
                    }
                }
            }
        }
        return $result;
    }
}
