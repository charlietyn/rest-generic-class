<?php

namespace Ronu\RestGenericClass\Core\Services\Support;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Ronu\RestGenericClass\Core\Exports\ModelExport;

class ExportCoordinator
{
    private Closure $listAll;

    public function __construct(private Model $model, callable $listAll)
    {
        $this->listAll = Closure::fromCallable($listAll);
    }

    public function exportExcel($params)
    {
        $payload = $this->payload($params);
        $filename = $params['filename'] ?? 'excel.xlsx';

        return \Maatwebsite\Excel\Facades\Excel::download(
            new ModelExport($payload['data'], $payload['columns']),
            $filename
        );
    }

    public function exportPdf($params)
    {
        $payload = $this->payload($params);
        $template = $params['template'] ?? 'pdf';
        $filename = $params['filename'] ?? 'pdf_file.pdf';

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView($template, [
            'data' => $payload['data'],
            'columns' => $payload['columns'],
            'model' => $this->model,
            'params' => $params,
        ]);

        return $pdf->download($filename);
    }

    public function payload($params): array
    {
        $result = ($this->listAll)($params);

        return [
            'data' => $this->extractData($result),
            'columns' => $this->resolveColumns($params),
        ];
    }

    public function extractData(mixed $result): array
    {
        if ($result instanceof LengthAwarePaginator) {
            return $result->items();
        }

        if (is_array($result) && array_key_exists('data', $result)) {
            return $result['data'];
        }

        if (is_array($result)) {
            return $result;
        }

        return [];
    }

    public function resolveColumns(array $params): array
    {
        if (array_key_exists('columns', $params)) {
            return $this->normalizeColumns($params['columns']);
        }

        $select = $params['select'] ?? '*';

        if ($select === '*') {
            return $this->model->getFillable();
        }

        if (is_array($select) && count($select) === 1 && $select[0] === '*') {
            return $this->model->getFillable();
        }

        $normalized = $this->normalizeColumns($select);

        return empty($normalized) ? $this->model->getFillable() : $normalized;
    }

    public function normalizeColumns(mixed $columns): array
    {
        if (is_string($columns)) {
            $columns = array_filter(array_map('trim', explode(',', $columns)));
        }

        if (!is_array($columns)) {
            return [];
        }

        return array_values(array_filter($columns, static fn($value) => $value !== ''));
    }
}
