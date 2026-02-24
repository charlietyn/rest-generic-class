<?php

namespace Ronu\RestGenericClass\Core\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Generic Excel export class for any dataset.
 *
 * Used by BaseService::exportExcel(), ManagesManyToMany::exportRelationExcel(),
 * and ManagesOneToMany::exportRelationExcel().
 *
 * Accepts rows as plain arrays, Eloquent model instances, or stdClass objects.
 * Each row is normalised to an array via toArray() / (array) cast before mapping,
 * so the class works regardless of whether the data originates from a paginator
 * (which returns model objects) or from Collection::toArray() (which returns arrays).
 *
 * Example:
 *   $columns = ['id', 'name', 'city'];
 *   $data    = [['id' => 1, 'name' => 'John', 'city' => 'Madrid', 'extra' => 'ignored'], ...];
 *   // → headings row:  id | name | city
 *   // → data rows:      1 | John | Madrid
 */
class ModelExport implements FromArray, WithHeadings
{
    public function __construct(
        private readonly array $data,
        private readonly array $columns
    ) {}

    /**
     * Return the data rows mapped to the declared column order.
     * Keys not present in $columns are silently dropped.
     * Missing keys in a row are filled with null.
     *
     * Rows are normalised to arrays first so that Eloquent model objects
     * (returned by LengthAwarePaginator::items()) are handled transparently.
     */
    public function array(): array
    {
        return array_map(function (mixed $row): array {
            $row = $this->normalizeRow($row);
            return array_map(fn(string $col) => $row[$col] ?? null, $this->columns);
        }, $this->data);
    }

    /**
     * Normalise a single row to a plain associative array.
     *
     * Handles three input types:
     *   - plain array            → returned as-is
     *   - Eloquent model / any object with toArray() → converted via toArray()
     *   - stdClass / other object → cast to array
     */
    private function normalizeRow(mixed $row): array
    {
        if (is_array($row)) {
            return $row;
        }

        if (is_object($row) && method_exists($row, 'toArray')) {
            return $row->toArray();
        }

        return (array) $row;
    }

    /**
     * Return the spreadsheet header row.
     */
    public function headings(): array
    {
        return $this->columns;
    }
}
