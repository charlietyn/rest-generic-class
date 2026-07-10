<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Closure;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Ronu\RestGenericClass\Core\Exports\ModelExport;

class RelationReadCoordinator
{
    private Closure $resolveConfig;
    private Closure $resolveParent;
    private RelationQueryFilter $queryFilter;
    private Closure $buildNotFoundError;
    private Closure $defaultPerPage;

    public function __construct(
        callable $resolveConfig,
        callable $resolveParent,
        RelationQueryFilter $queryFilter,
        callable $buildNotFoundError,
        callable $defaultPerPage
    ) {
        $this->resolveConfig = Closure::fromCallable($resolveConfig);
        $this->resolveParent = Closure::fromCallable($resolveParent);
        $this->queryFilter = $queryFilter;
        $this->buildNotFoundError = Closure::fromCallable($buildNotFoundError);
        $this->defaultPerPage = Closure::fromCallable($defaultPerPage);
    }

    public function list(Request $request, mixed $parentId = null): LengthAwarePaginator|array
    {
        $config = ($this->resolveConfig)($request->get('_relation'));
        $parent = ($this->resolveParent)($config, $parentId);
        $query = $parent->{$config['relationship']}();
        $params = $this->parseParams($request);

        $this->applyQueryOptions($query, $params);

        if (!empty($params['pagination'])) {
            return $this->processPagination($params, $query);
        }

        return ['data' => $query->get()->jsonSerialize()];
    }

    public function show(Request $request, mixed $parentIdOrRelatedId, mixed $relatedId = null): mixed
    {
        $relationName = $request->get('_relation');
        $config = ($this->resolveConfig)($relationName);

        if ($relatedId === null) {
            $parent = ($this->resolveParent)($config, null);
            $relatedId = $parentIdOrRelatedId;
        } else {
            $parent = ($this->resolveParent)($config, $parentIdOrRelatedId);
        }

        $query = $parent->{$config['relationship']}();
        $params = $this->parseParams($request);

        if (!empty($params['relations'])) {
            $query->with($params['relations']);
        }

        $related = $query->find($relatedId, $params['select']);

        if (!$related) {
            return response()->json(
                ($this->buildNotFoundError)(
                    class_basename($config['relatedModel']),
                    $relatedId,
                    $relationName
                ),
                404
            );
        }

        return $related;
    }

    public function exportExcel(Request $request, mixed $parentId = null): mixed
    {
        $payload = $this->exportPayload($request, $parentId);
        $filename = $request->get('filename', 'export.xlsx');

        return \Maatwebsite\Excel\Facades\Excel::download(
            new ModelExport($payload['data'], $payload['columns']),
            $filename
        );
    }

    public function exportPdf(Request $request, mixed $parentId = null): mixed
    {
        $payload = $this->exportPayload($request, $parentId);
        $template = $request->get('template', 'pdf');
        $filename = $request->get('filename', 'export.pdf');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView($template, [
            'data' => $payload['data'],
            'columns' => $payload['columns'],
            'model' => $payload['model'],
            'params' => $payload['params'],
        ])->download($filename);
    }

    public function exportPayload(Request $request, mixed $parentId = null): array
    {
        $config = ($this->resolveConfig)($request->get('_relation'));
        $params = $this->parseParams($request);

        return [
            'data' => $this->buildExportData($config, $params, $parentId),
            'columns' => $this->resolveExportColumns($request, $config, $params['select']),
            'model' => new $config['relatedModel'],
            'params' => $params,
        ];
    }

    public function parseParams(Request $request): array
    {
        return [
            'eq' => $this->parseJsonParam($request, 'eq', 'attr'),
            'oper' => $this->parseJsonParam($request, 'oper'),
            'orderby' => $this->parseJsonParam($request, 'orderby'),
            'pagination' => $this->parseJsonParam($request, 'pagination'),
            'select' => $this->parseSelect($request),
            'relations' => $this->parseRelations($request),
        ];
    }

    public function processPagination(array $params, $query): mixed
    {
        $pagination = $this->normalizeArray($params['pagination'] ?? []);
        $paginationLower = array_change_key_case($pagination);
        $pageSize = array_key_exists('pagesize', $paginationLower)
            ? $paginationLower['pagesize']
            : ($this->defaultPerPage)();
        $select = $params['select'] ?? ['*'];

        if (!isset($pagination['infinity']) || $pagination['infinity'] !== true) {
            return $this->paginate($query, $pagination, $select);
        }

        $cursor = $pagination['cursor'] ?? null;
        $items = $query->cursorPaginate($pageSize, $select, 'cursor', $cursor);

        return [
            'data' => $items->items(),
            'next_cursor' => $items->nextCursor()?->encode(),
            'has_more' => $items->hasMorePages(),
        ];
    }

    public function buildExportData(array $config, array $params, mixed $parentId): array
    {
        $parent = ($this->resolveParent)($config, $parentId);
        $query = $parent->{$config['relationship']}();

        $this->applyQueryOptions($query, $params);

        return $query->get()->toArray();
    }

    public function resolveExportColumns(Request $request, array $config, array $select): array
    {
        $rawColumns = $request->get('columns');

        if ($rawColumns) {
            $normalized = $this->normalizeExportColumns($rawColumns);
            if (!empty($normalized)) {
                return $normalized;
            }
        }

        if ($select !== ['*']) {
            $normalized = $this->normalizeExportColumns($select);
            if (!empty($normalized)) {
                return $normalized;
            }
        }

        return (new $config['relatedModel'])->getFillable();
    }

    public function normalizeExportColumns(mixed $columns): array
    {
        if (is_string($columns)) {
            $columns = array_map('trim', explode(',', $columns));
        }

        if (!is_array($columns)) {
            return [];
        }

        return array_values(array_filter($columns, static fn($value) => $value !== ''));
    }

    private function paginate($query, array $pagination, array $select): LengthAwarePaginator
    {
        $currentPage = $pagination['page'] ?? 1;
        $pageSize = $pagination['pageSize'] ?? ($pagination['pagesize'] ?? null);

        Paginator::currentPageResolver(function () use ($currentPage) {
            return $currentPage;
        });

        return $query->paginate($pageSize, $select);
    }

    private function applyQueryOptions(Relation $query, array $params): void
    {
        $this->queryFilter->applyEq($query, $params['eq']);
        $this->queryFilter->applyOper($query, $params['oper']);
        $this->queryFilter->applyOrdering($query, $params['orderby']);

        if (!empty($params['relations'])) {
            $query->with($params['relations']);
        }
    }

    private function parseJsonParam(Request $request, string $key, ?string $alias = null): array
    {
        $decoded = $this->normalizeArray($request->get($key));

        if ($alias) {
            $decoded = array_merge($decoded, $this->normalizeArray($request->get($alias)));
        }

        return $decoded;
    }

    private function parseSelect(Request $request): array
    {
        $select = $request->get('select');

        if (!$select) {
            return ['*'];
        }

        if (is_string($select)) {
            $decoded = json_decode($select, true);
            return $decoded ?: explode(',', $select);
        }

        return (array)$select;
    }

    private function parseRelations(Request $request): array
    {
        $relations = $request->get('relations');

        if (!$relations) {
            return [];
        }

        if (is_string($relations)) {
            return json_decode($relations, true) ?? [];
        }

        return (array)$relations;
    }

    private function normalizeArray(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return is_array($value) ? $value : [];
    }
}
