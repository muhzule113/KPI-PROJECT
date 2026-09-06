<?php

namespace App\Support;

final class SpreadsheetValue
{
    public static function safe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/u', $value) ? "'".$value : $value;
    }

    public static function row(array $values): array
    {
        return array_map(self::safe(...), $values);
    }
}
