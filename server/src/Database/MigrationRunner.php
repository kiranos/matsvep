<?php

declare(strict_types=1);

namespace Matsvep\Database;

use PDO;
use RuntimeException;

final class MigrationRunner
{
    private const BOOTSTRAP = '000_schema_migrations.sql';
    private const REQUIRED_PRIVILEGES = ['CREATE', 'ALTER', 'DROP', 'INDEX', 'REFERENCES', 'TRIGGER'];

    public function __construct(
        private readonly PDO $db,
        private readonly string $directory,
        private readonly string $databaseName,
    ) {
    }

    /** @return list<string> */
    public function run(): array
    {
        $files = $this->migrationFiles();
        $lockName = 'matsvep:migrate:' . substr(hash('sha256', $this->databaseName), 0, 32);
        $lock = $this->db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Another migration runner is active for this database.');
        }

        try {
            $result = [];
            $hasHistoryTable = $this->hasHistoryTable();
            $history = $hasHistoryTable ? $this->history() : [];
            if ($hasHistoryTable && !isset($history[self::BOOTSTRAP])) {
                throw new RuntimeException('schema_migrations exists without its bootstrap record; manual repair is required.');
            }
            foreach ($history as $name => $_row) {
                if (!isset($files[$name])) {
                    throw new RuntimeException("Recorded migration is missing: $name");
                }
            }
            foreach ($history as $name => $row) {
                if ($row['checksum'] !== hash('sha256', $files[$name])) {
                    throw new RuntimeException("Applied migration changed: $name");
                }
                if ($row['status'] !== 'applied') {
                    throw new RuntimeException("Migration $name was interrupted; manual repair is required.");
                }
            }
            if (array_diff_key($files, $history) !== []) {
                $this->assertSchemaPrivileges();
            }

            if (!$hasHistoryTable) {
                $bootstrap = $files[self::BOOTSTRAP];
                $this->executeSql($bootstrap);
                $this->record(self::BOOTSTRAP, $bootstrap, 'applied');
                $history[self::BOOTSTRAP] = ['checksum' => hash('sha256', $bootstrap), 'status' => 'applied'];
                $result[] = 'APPLIED ' . self::BOOTSTRAP;
            }

            foreach ($files as $name => $sql) {
                if (isset($history[$name])) {
                    if ($name !== self::BOOTSTRAP || !in_array('APPLIED ' . self::BOOTSTRAP, $result, true)) {
                        $result[] = "SKIPPED $name";
                    }
                    continue;
                }

                $this->record($name, $sql, 'started');
                $this->executeSql($sql);
                $markApplied = $this->db->prepare(
                    "UPDATE schema_migrations SET status = 'applied', applied_at = UTC_TIMESTAMP(6) WHERE migration = ? AND status = 'started'"
                );
                $markApplied->execute([$name]);
                $result[] = "APPLIED $name";
            }

            return $result;
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    private function assertSchemaPrivileges(): void
    {
        $account = $this->db->prepare('SELECT CURRENT_USER()');
        $account->execute();
        $identity = $account->fetchColumn();
        if (!is_string($identity) || !str_contains($identity, '@')) {
            throw new RuntimeException('Cannot determine the migration database account.');
        }
        $separator = strrpos($identity, '@');
        if ($separator === false) {
            throw new RuntimeException('Cannot determine the migration database account.');
        }
        $user = substr($identity, 0, $separator);
        $host = substr($identity, $separator + 1);
        $privileges = $this->db->prepare('SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES '
            . "WHERE GRANTEE = CONCAT(QUOTE(?), '@', QUOTE(?)) "
            . 'UNION SELECT PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES '
            . "WHERE GRANTEE = CONCAT(QUOTE(?), '@', QUOTE(?)) AND TABLE_SCHEMA = ?");
        $privileges->execute([$user, $host, $user, $host, $this->databaseName]);
        $granted = [];
        foreach ($privileges->fetchAll(PDO::FETCH_COLUMN) as $privilege) {
            if (!is_string($privilege)) {
                throw new RuntimeException('Cannot read migration database privileges.');
            }
            $granted[] = strtoupper($privilege);
        }
        $missing = array_diff(self::REQUIRED_PRIVILEGES, $granted);
        if ($missing !== []) {
            throw new RuntimeException('Migration account lacks ' . implode(', ', $missing)
                . ' on database ' . $this->databaseName
                . '. On the VPS, run sudo bash deploy/setup-ubuntu.sh from the repository root to apply migrations.');
        }
    }

    /** @return array<string, string> */
    private function migrationFiles(): array
    {
        $paths = glob($this->directory . DIRECTORY_SEPARATOR . '*.sql');
        if ($paths === false) {
            throw new RuntimeException('Cannot list migration files.');
        }
        $files = [];
        $numbers = [];
        foreach ($paths as $path) {
            $name = basename($path);
            if (!preg_match('/^([0-9]{3})_[a-z0-9_]+\.sql$/', $name, $matches)) {
                throw new RuntimeException("Invalid migration filename: $name");
            }
            if (isset($numbers[$matches[1]])) {
                throw new RuntimeException("Duplicate migration number: {$matches[1]}");
            }
            $numbers[$matches[1]] = true;
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException("Cannot read migration: $name");
            }
            $files[$name] = str_replace(["\r\n", "\r"], "\n", $contents);
        }
        ksort($files, SORT_STRING);
        if (!isset($files[self::BOOTSTRAP])) {
            throw new RuntimeException('Missing bootstrap migration 000_schema_migrations.sql.');
        }
        return $files;
    }

    private function hasHistoryTable(): bool
    {
        $query = $this->db->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
        );
        $query->execute(['schema_migrations']);
        return (int) $query->fetchColumn() === 1;
    }

    /** @return array<string, array{checksum: string, status: string}> */
    private function history(): array
    {
        $query = $this->db->prepare('SELECT migration, checksum, status FROM schema_migrations');
        $query->execute();
        $history = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (!is_array($row) || !isset($row['migration'], $row['checksum'], $row['status'])
                || !is_string($row['migration']) || !is_string($row['checksum']) || !is_string($row['status'])) {
                throw new RuntimeException('Invalid migration history row.');
            }
            $history[$row['migration']] = ['checksum' => $row['checksum'], 'status' => $row['status']];
        }
        return $history;
    }

    private function record(string $name, string $sql, string $status): void
    {
        $query = $this->db->prepare(
            'INSERT INTO schema_migrations (migration, checksum, status, started_at, applied_at) ' .
            'VALUES (?, ?, ?, UTC_TIMESTAMP(6), IF(? = \'applied\', UTC_TIMESTAMP(6), NULL))'
        );
        $query->execute([$name, hash('sha256', $sql), $status, $status]);
    }

    private function executeSql(string $sql): void
    {
        foreach (self::splitStatements($sql) as $statement) {
            $query = $this->db->prepare($statement);
            $query->execute();
        }
    }

    /** @return list<string> */
    public static function splitStatements(string $sql): array
    {
        $sql = preg_replace('/^\xEF\xBB\xBF/', '', $sql) ?? $sql;
        $statements = [];
        $current = '';
        $quote = null;
        $lineComment = false;
        $blockComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $sql[$i + 1] ?? '';
            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $current .= "\n";
                }
                continue;
            }
            if ($blockComment) {
                if ($char === '*' && $next === '/') {
                    $blockComment = false;
                    $current .= ' ';
                    $i++;
                }
                continue;
            }
            if ($quote !== null) {
                $current .= $char;
                if ($char === '\\' && $quote !== '`' && $next !== '') {
                    $current .= $next;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $current .= $next;
                        $i++;
                    } else {
                        $quote = null;
                    }
                }
                continue;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $current .= $char;
            } elseif ($char === '-' && $next === '-' && preg_match('/\s/', $sql[$i + 2] ?? '')) {
                $lineComment = true;
                $i++;
            } elseif ($char === '#') {
                $lineComment = true;
            } elseif ($char === '/' && $next === '*') {
                $blockComment = true;
                $i++;
            } elseif ($char === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }
        if ($quote !== null || $blockComment) {
            throw new RuntimeException('Unterminated SQL string or block comment.');
        }
        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}
