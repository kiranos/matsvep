<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Feature;

use PDO;
use RuntimeException;
use Throwable;

final class FeatureSeeder
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function seed(string $seedDirectory): int
    {
        $rows = CsvTable::read(
            $seedDirectory . '/leftover_keywords.csv',
            ['keyword', 'signal_type', 'value'],
        );
        $upsert = $this->db->prepare(
            'INSERT INTO leftover_keyword (keyword, signal_type, value) VALUES (?, ?, ?) ' .
            'ON DUPLICATE KEY UPDATE value = VALUES(value)'
        );
        $this->db->beginTransaction();
        try {
            foreach ($rows as $row) {
                if ($row['keyword'] === '' || !in_array(
                    $row['signal_type'],
                    ['dish_type', 'method', 'protein_form'],
                    true
                )
                    || !is_numeric($row['value']) || (float) $row['value'] < -1
                    || (float) $row['value'] > 1) {
                    throw new RuntimeException('Invalid leftover keyword seed row.');
                }
                $upsert->execute([$row['keyword'], $row['signal_type'], $row['value']]);
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
        return count($rows);
    }
}
