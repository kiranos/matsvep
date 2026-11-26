<?php

declare(strict_types=1);

namespace Matsvep\Api;

use Matsvep\Database\WriteTransaction;
use Matsvep\Engine\EngineConfig;
use PDO;

final class ApiStatePurger
{
    public function __construct(private readonly PDO $db, private readonly EngineConfig $config)
    {
    }

    /** @return array<string,int> */
    public function purge(): array
    {
        return WriteTransaction::run($this->db, function (): array {
            $queries = [
                'api_idempotency' => ['DELETE FROM api_idempotency WHERE expires_at <= UTC_TIMESTAMP(6)', []],
                'app_login_attempt' => [
                    'DELETE FROM app_login_attempt WHERE attempted_at <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND)',
                    [$this->config->integer('auth.login_window_s')],
                ],
                'device_refresh_history' => [
                    'DELETE FROM device_refresh_history WHERE rotated_at <= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? DAY)',
                    [$this->config->integer('auth.refresh_ttl_days')],
                ],
                'device_token' => [
                    'DELETE FROM device_token WHERE expires_at <= UTC_TIMESTAMP(6) OR '
                    . '(revoked_at IS NOT NULL AND (access_expires_at IS NULL OR access_expires_at <= UTC_TIMESTAMP(6)))',
                    [],
                ],
            ];
            $counts = [];
            foreach ($queries as $name => [$sql, $parameters]) {
                $delete = $this->db->prepare($sql);
                $delete->execute($parameters);
                $counts[$name] = $delete->rowCount();
            }
            return $counts;
        });
    }
}
