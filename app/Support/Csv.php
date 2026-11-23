<?php

namespace App\Support;

/** CSV helpers shared by report downloads and the CSV booking export. */
class Csv
{
    public const BOM = "\xEF\xBB\xBF"; // so Excel shows names / ₹ correctly

    /** Stop spreadsheet formula injection (also keeps "+91…" phones as text in Excel). */
    public static function safe(mixed $value): mixed
    {
        return is_string($value) && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }

    /** Write one formula-safe row (RFC 4180 quoting, no escape char). */
    public static function put($handle, array $row): void
    {
        fputcsv($handle, array_map(self::safe(...), $row), ',', '"', '');
    }
}
