<?php

namespace Ronu\RestGenericClass\Core\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;

/**
 * Generic Excel export class for any array-based dataset.
 *
 * Used by BaseService::exportExcel(), ManagesManyToMany::exportRelationExcel(),
 * and ManagesOneToMany::exportRelationExcel().
 *
 * Receives a flat array of row arrays and a column list that defines both
 * the spreadsheet headings and the key order used when mapping each row.
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
     */
    public function array(): array
    {
        return array_map(function (array $row): array {
            return array_map(fn(string $col) => $row[$col] ?? null, $this->columns);
        }, $this->data);
    }

    /**
     * Return the spreadsheet header row.
     */
    public function headings(): array
    {
        return $this->columns;
    }
}
