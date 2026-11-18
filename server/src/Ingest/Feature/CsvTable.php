<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use RuntimeException;

final class CsvTable
{
    /** @param list<string> $header
     * @return list<array<string, string>>
     */
    public static function read(string $path, array $header): array
    {
        $file = fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException('Cannot read feature seed file.');
        }
        try {
            if (fgetcsv($file, escape: '') !== $header) {
                throw new RuntimeException('Invalid feature seed header.');
            }
            $rows = [];
            while (($values = fgetcsv($file, escape: '')) !== false) {
                if (count($values) !== count($header)) {
                    throw new RuntimeException('Invalid feature seed row.');
                }
                $row = [];
                foreach ($header as $index => $name) {
                    if (!is_string($values[$index])) {
                        throw new RuntimeException('Invalid feature seed value.');
                    }
                    $row[$name] = trim($values[$index]);
                }
                $rows[] = $row;
            }
            return $rows;
        } finally {
            fclose($file);
        }
    }
}
