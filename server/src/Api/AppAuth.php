<?php

declare(strict_types=1);

namespace Matsvep\Api;

use Matsvep\Engine\EngineConfig;
use PDO;
use Throwable;

final class AppAuth
{
    public function __construct(private readonly PDO $db, private readonly EngineConfig $config)
    {
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed> */
    public function register(array $input): array
    {
        $email = $this->email($input['email'] ?? null);
        $password = $input['password'] ?? null;
        $platform = $this->platform($input['platform'] ?? null);
        if (!is_string($password) || strlen($password) < $this->config->integer('auth.password_min_length')) {
            throw new ApiError(422, 'validation_failed');
        }
        $hasInvite = isset($input['invite_code']);
        if ($hasInvite === isset($input['household_name'])) {
            throw new ApiError(422, 'validation_failed');
        }
        $this->db->beginTransaction();
        try {
            if ($hasInvite) {
                $code = $input['invite_code'];
                if (!is_string($code) || $code === '') {
                    throw new ApiError(422, 'validation_failed');
                }
                $query = $this->db->prepare('SELECT id, household_id, expires_at, used_at FROM household_invite WHERE code_hash = ? FOR UPDATE');
                $query->execute([hash('sha256', $code)]);
                $invite = $query->fetch(PDO::FETCH_ASSOC);
                if (!is_array($invite)) {
                    throw new ApiError(422, 'invite_invalid');
                }
                if ($invite['used_at'] !== null) {
                    throw new ApiError(422, 'invite_used');
                }
                if (strtotime((string) $invite['expires_at'] . ' UTC') <= time()) {
                    throw new ApiError(422, 'invite_expired');
                }
                $householdId = (int) $invite['household_id'];
            } else {
                $name = $input['household_name'];
                $size = $input['household_size'] ?? 1;
                $timezone = $input['timezone'] ?? 'Europe/Stockholm';
                if (!is_string($name) || trim($name) === '' || !is_int($size) || $size < 1 || $size > 100
                    || !is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
                    throw new ApiError(422, 'validation_failed');
                }
                $insert = $this->db->prepare('INSERT INTO household (name, size, timezone) VALUES (?, ?, ?)');
                $insert->execute([trim($name), $size, $timezone]);
                $householdId = (int) $this->db->lastInsertId();
            }
            $insert = $this->db->prepare("INSERT INTO `user` (email, password_hash, role, household_id) VALUES (?, ?, 'member', ?)");
            $insert->execute([$email, password_hash($password, PASSWORD_DEFAULT), $householdId]);
            $userId = (int) $this->db->lastInsertId();
            if ($hasInvite) {
                $update = $this->db->prepare('UPDATE household_invite SET used_by = ?, used_at = UTC_TIMESTAMP(6) WHERE id = ?');
                $update->execute([$userId, $invite['id']]);
            }
            $tokens = $this->issue($userId, $platform);
            $this->db->commit();
            return $tokens;
        } catch (Throwable $error) {
            $this->db->rollBack();
            if ($error instanceof \PDOException && $error->getCode() === '23000') {
                throw new ApiError(409, 'validation_failed');
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed> */
    public function login(array $input, string $ip): array
    {
        $email = $this->email($input['email'] ?? null);
        $platform = $this->platform($input['platform'] ?? null);
        $password = $input['password'] ?? null;
        if (!is_string($password) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new ApiError(400, 'invalid_request');
        }
        $emailHash = hash('sha256', $email);
        $window = $this->config->integer('auth.login_window_s');
        $limit = $this->config->integer('auth.login_max_attempts');
        $count = $this->db->prepare('SELECT (SELECT COUNT(*) FROM app_login_attempt WHERE email_hash = ? AND attempted_at > DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) AS email_count, (SELECT COUNT(*) FROM app_login_attempt WHERE ip_address = ? AND attempted_at > DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) AS ip_count');
        $count->execute([$emailHash, $window, $ip, $window]);
        $attempts = $count->fetch(PDO::FETCH_ASSOC);
        if (is_array($attempts) && ((int) $attempts['email_count'] >= $limit || (int) $attempts['ip_count'] >= $limit)) {
            throw new ApiError(429, 'rate_limited');
        }
        $query = $this->db->prepare('SELECT id, password_hash FROM `user` WHERE email = ?');
        $query->execute([$email]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            password_verify($password, '$2y$10$R3w0wq27VzUoW4QSh0w4xOww3kyLJvQDd6XxDwfToYJ1pG5C.sNdy');
        }
        if (!is_array($user) || !password_verify($password, (string) $user['password_hash'])) {
            $insert = $this->db->prepare('INSERT INTO app_login_attempt (email_hash, ip_address) VALUES (?, ?)');
            $insert->execute([$emailHash, $ip]);
            throw new ApiError(401, 'invalid_credentials');
        }
        $this->db->beginTransaction();
        try {
            $tokens = $this->issue((int) $user['id'], $platform);
            $clear = $this->db->prepare('DELETE FROM app_login_attempt WHERE email_hash = ? OR ip_address = ?');
            $clear->execute([$emailHash, $ip]);
            $this->db->commit();
            return $tokens;
        } catch (Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    /** @return array<string, mixed> */
    public function refresh(string $token): array
    {
        $hash = hash('sha256', $token);
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT id, user_id, revoked_at, expires_at FROM device_token WHERE refresh_token_hash = ? FOR UPDATE');
            $query->execute([$hash]);
            $device = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($device)) {
                $history = $this->db->prepare('SELECT device_token_id FROM device_refresh_history WHERE token_hash = ?');
                $history->execute([$hash]);
                $deviceId = $history->fetchColumn();
                if ($deviceId !== false) {
                    $this->revokeDevice((int) $deviceId);
                }
                $this->db->commit();
                throw new ApiError(401, 'refresh_token_invalid');
            }
            if ($device['revoked_at'] !== null || strtotime((string) $device['expires_at'] . ' UTC') <= time()) {
                $this->db->commit();
                throw new ApiError(401, 'refresh_token_invalid');
            }
            $history = $this->db->prepare('INSERT INTO device_refresh_history (token_hash, device_token_id) VALUES (?, ?)');
            $history->execute([$hash, $device['id']]);
            $access = $this->randomToken();
            $refresh = $this->randomToken();
            $accessSeconds = $this->config->integer('auth.access_ttl_minutes') * 60;
            $refreshSeconds = $this->config->integer('auth.refresh_ttl_days') * 86400;
            $update = $this->db->prepare('UPDATE device_token SET refresh_token_hash = ?, access_token_hash = ?, access_expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), last_seen_at = UTC_TIMESTAMP(6) WHERE id = ?');
            $update->execute([hash('sha256', $refresh), hash('sha256', $access), $accessSeconds, $refreshSeconds, $device['id']]);
            $result = $this->tokenResponse((int) $device['user_id'], $access, $refresh, $accessSeconds);
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @return array{user_id:int,device_id:int,role:string,household_id:?int} */
    public function authenticate(string $token): array
    {
        $query = $this->db->prepare('SELECT d.id AS device_id, d.user_id, d.access_expires_at, u.role, u.household_id FROM device_token d JOIN `user` u ON u.id = d.user_id WHERE d.access_token_hash = ? AND d.revoked_at IS NULL');
        $query->execute([hash('sha256', $token)]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || strtotime((string) $row['access_expires_at'] . ' UTC') <= time()) {
            throw new ApiError(401, 'access_token_expired');
        }
        return ['user_id' => (int) $row['user_id'], 'device_id' => (int) $row['device_id'], 'role' => (string) $row['role'], 'household_id' => $row['household_id'] === null ? null : (int) $row['household_id']];
    }

    public function revoke(string $refreshToken, int $userId): void
    {
        $query = $this->db->prepare('SELECT id FROM device_token WHERE refresh_token_hash = ? AND user_id = ?');
        $query->execute([hash('sha256', $refreshToken), $userId]);
        $id = $query->fetchColumn();
        if ($id === false) {
            throw new ApiError(401, 'refresh_token_invalid');
        }
        $this->revokeDevice((int) $id);
    }

    public function revokeDevice(int $id): void
    {
        $update = $this->db->prepare('UPDATE device_token SET revoked_at = UTC_TIMESTAMP(6), access_token_hash = NULL, access_expires_at = NULL WHERE id = ?');
        $update->execute([$id]);
    }

    /** @return array<string, mixed> */
    private function issue(int $userId, string $platform): array
    {
        $access = $this->randomToken();
        $refresh = $this->randomToken();
        $accessSeconds = $this->config->integer('auth.access_ttl_minutes') * 60;
        $refreshSeconds = $this->config->integer('auth.refresh_ttl_days') * 86400;
        $insert = $this->db->prepare('INSERT INTO device_token (user_id, platform, refresh_token_hash, access_token_hash, expires_at, access_expires_at) VALUES (?, ?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND))');
        $insert->execute([$userId, $platform, hash('sha256', $refresh), hash('sha256', $access), $refreshSeconds, $accessSeconds]);
        return $this->tokenResponse($userId, $access, $refresh, $accessSeconds);
    }

    /** @return array<string, mixed> */
    private function tokenResponse(int $userId, string $access, string $refresh, int $accessSeconds): array
    {
        $query = $this->db->prepare('SELECT u.id, u.email, u.role, u.household_id, h.name, h.size, h.timezone, h.created_at FROM `user` u LEFT JOIN household h ON h.id = u.household_id WHERE u.id = ?');
        $query->execute([$userId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(401, 'invalid_credentials');
        }
        $household = $row['household_id'] === null ? null : ['id' => (int) $row['household_id'], 'name' => $row['name'], 'size' => (int) $row['size'], 'timezone' => $row['timezone'], 'created_at' => str_replace(' ', 'T', (string) $row['created_at']) . 'Z'];
        return ['access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'bearer', 'expires_in_s' => $accessSeconds, 'user' => ['id' => (int) $row['id'], 'email' => $row['email'], 'role' => $row['role'], 'household_id' => $row['household_id'] === null ? null : (int) $row['household_id']], 'household' => $household];
    }

    private function randomToken(): string
    {
        return bin2hex(random_bytes($this->config->integer('auth.token_bytes')));
    }

    private function email(mixed $value): string
    {
        if (!is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new ApiError(422, 'validation_failed');
        }
        return strtolower(trim($value));
    }

    private function platform(mixed $value): string
    {
        if ($value !== 'android' && $value !== 'windows') {
            throw new ApiError(422, 'validation_failed');
        }
        return $value;
    }
}
