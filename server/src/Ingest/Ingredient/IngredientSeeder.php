<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Ingredient;

use PDO;
use RuntimeException;
use Throwable;

final class IngredientSeeder
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function seed(string $path): int
    {
        $file = fopen($path, 'rb');
        if ($file === false) {
            throw new RuntimeException('Cannot read ingredient seed file.');
        }
        $header = fgetcsv($file, escape: '');
        if ($header !== ['canonical_name', 'category', 'is_staple', 'aliases']) {
            fclose($file);
            throw new RuntimeException('Invalid ingredient seed header.');
        }
        $findIngredient = $this->db->prepare('SELECT id FROM ingredient WHERE canonical_name = ?');
        $insertIngredient = $this->db->prepare(
            'INSERT INTO ingredient (canonical_name, category, is_staple) VALUES (?, ?, ?)'
        );
        $updateIngredient = $this->db->prepare(
            'UPDATE ingredient SET category = ?, is_staple = ? WHERE id = ?'
        );
        $findAlias = $this->db->prepare('SELECT ingredient_id FROM ingredient_alias WHERE alias = ?');
        $insertAlias = $this->db->prepare('INSERT INTO ingredient_alias (alias, ingredient_id) VALUES (?, ?)');
        $count = 0;
        $this->db->beginTransaction();
        try {
            while (($row = fgetcsv($file, escape: '')) !== false) {
                if (count($row) !== 4 || !is_string($row[0]) || !is_string($row[1])
                    || !in_array($row[2], ['0', '1'], true) || !is_string($row[3])
                    || trim($row[0]) === '' || !preg_match('/^[a-z_]+$/', $row[1])) {
                    throw new RuntimeException('Invalid ingredient seed row.');
                }
                [$name, $category, $staple, $aliases] = $row;
                $findIngredient->execute([$name]);
                $value = $findIngredient->fetchColumn();
                if ($value === false) {
                    $insertIngredient->execute([$name, $category, $staple]);
                    $id = (int) $this->db->lastInsertId();
                } else {
                    $id = (int) $value;
                    $updateIngredient->execute([$category, $staple, $id]);
                }
                foreach (array_unique(array_merge([$name], explode('|', $aliases))) as $alias) {
                    $alias = trim($alias);
                    if ($alias === '') {
                        continue;
                    }
                    $findAlias->execute([$alias]);
                    $owner = $findAlias->fetchColumn();
                    if ($owner !== false && (int) $owner !== $id) {
                        throw new RuntimeException('Ingredient aliases overlap across seed rows.');
                    }
                    if ($owner === false) {
                        $insertAlias->execute([$alias, $id]);
                    }
                }
                $count++;
            }
            $this->db->commit();
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        } finally {
            fclose($file);
        }
        return $count;
    }
}
