<?php

declare(strict_types=1);

namespace Matsvep\Api;

use Matsvep\Engine\EngineConfig;
use PDO;

final class Idempotency
{
    public function __construct(private readonly PDO $db, private readonly EngineConfig $config)
    {
    }

    /** @param callable():array{0:int,1:array<string,mixed>} $action
     *  @return array{0:int,1:array<string,mixed>} */
    public function execute(int $userId, string $endpoint, string $key, string $body, callable $action): array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $key) !== 1) {
            throw new ApiError(400, 'invalid_request');
        }
        $lock = 'api:' . substr(hash('sha256', $userId . ':' . $endpoint . ':' . $key), 0, 60);
        $acquire = $this->db->prepare('SELECT GET_LOCK(?, ?)');
        $acquire->execute([$lock, $this->config->integer('api.idempotency_lock_s')]);
        if ((int) $acquire->fetchColumn() !== 1) {
            throw new ApiError(429, 'rate_limited');
        }
        try {
            $query = $this->db->prepare('SELECT request_hash, response_status, response_json FROM api_idempotency WHERE user_id = ? AND endpoint = ? AND idempotency_key = ? AND expires_at > UTC_TIMESTAMP(6)');
            $query->execute([$userId, $endpoint, $key]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            $hash = hash('sha256', $body);
            if (is_array($row)) {
                if (!hash_equals(DbValue::text($row['request_hash']), $hash)) {
                    throw new ApiError(422, 'idempotency_key_reused');
                }
                $response = DbValue::map(json_decode(DbValue::text($row['response_json']), true, 512, JSON_THROW_ON_ERROR));
                return [DbValue::integer($row['response_status']), $response];
            }
            $this->db->beginTransaction();
            try {
                [$status, $response] = $action();
                $delete = $this->db->prepare('DELETE FROM api_idempotency WHERE user_id = ? AND endpoint = ? AND idempotency_key = ?');
                $delete->execute([$userId, $endpoint, $key]);
                $hours = $this->config->integer('api.idempotency_ttl_hours');
                $insert = $this->db->prepare('INSERT INTO api_idempotency (user_id, endpoint, idempotency_key, request_hash, response_status, response_json, expires_at) VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? HOUR))');
                $insert->execute([$userId, $endpoint, $key, $hash, $status, json_encode($response, JSON_THROW_ON_ERROR), $hours]);
                $this->db->commit();
                return [$status, $response];
            } catch (\Throwable $error) {
                $this->db->rollBack();
                throw $error;
            }
        } finally {
            $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        }
    }

    /** @return array{0:int,1:array<string,mixed>} */
    public function replayOnly(int $userId, string $endpoint, string $key, string $body): array
    {
        return $this->execute($userId, $endpoint, $key, $body, static function (): array {
            throw new ApiError(401, 'access_token_expired');
        });
    }
}
