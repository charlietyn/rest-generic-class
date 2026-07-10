<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class PaginationCoordinator
{
    public function __construct(private Model $model)
    {
    }

    public function paginate($query, mixed $pagination): LengthAwarePaginator
    {
        $pagination = $this->normalize($pagination);
        $currentPage = $pagination['page'] ?? 1;
        $pageSize = $pagination['pageSize'] ?? ($pagination['pagesize'] ?? null);

        Paginator::currentPageResolver(function () use ($currentPage) {
            return $currentPage;
        });

        return $query->paginate($pageSize);
    }

    public function process(mixed $params, $query): mixed
    {
        $pagination = $this->normalize($params['pagination']);
        $paginationLower = array_change_key_case($pagination);
        $pageSize = array_key_exists('pagesize', $paginationLower)
            ? $paginationLower['pagesize']
            : $this->model->getPerPage();

        if (!isset($pagination['infinity']) || $pagination['infinity'] !== true) {
            return $this->paginate($query, $pagination);
        }

        $cursor = $pagination['cursor'] ?? null;

        return $query->cursorPaginate($pageSize, ['*'], 'cursor', $cursor);
    }

    private function normalize(mixed $pagination): array
    {
        if (is_string($pagination)) {
            $decoded = json_decode($pagination, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($pagination) ? $pagination : [];
    }
}
