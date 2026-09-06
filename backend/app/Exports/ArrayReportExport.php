<?php

namespace App\Exports;

use App\Support\SpreadsheetValue;
use Generator;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\WithHeadings;

final class ArrayReportExport implements FromGenerator, WithHeadings
{
    public function __construct(private array $headings, private iterable $rows) {}

    public function headings(): array
    {
        return SpreadsheetValue::row($this->headings);
    }

    public function generator(): Generator
    {
        foreach ($this->rows as $row) {
            yield SpreadsheetValue::row(array_values($row));
        }
    }
}
