<?php

declare(strict_types=1);

namespace Matsvep\Api;

use Matsvep\Engine\EngineConfig;
use PDO;
use Throwable;

final class AppAuth
{
    private const UNKNOWN_PASSWORD_HASH = '$2y$12$4fchHGuKojlVWxvPlb1gY.SoFouRXmSrrD7z5FOuPZkxuyG9kX5iq';

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
        $registrationLock = !$hasInvite && $this->config->integer('auth.open_registration') === 0;
        if ($registrationLock) {
            $lock = $this->db->prepare('SELECT GET_LOCK(?, ?)');
            $lock->execute(['matsvep:household_bootstrap', $this->config->integer('auth.registration_lock_s')]);
            if (DbValue::integer($lock->fetchColumn()) !== 1) {
                throw new ApiError(429, 'rate_limited');
            }
        }
        try {
            $this->db->beginTransaction();
            $existing = $this->db->prepare('SELECT 1 FROM `user` WHERE email = ?');
            $existing->execute([$email]);
            if ($existing->fetchColumn() !== false) {
                throw new ApiError(409, 'email_taken');
            }
            if ($registrationLock) {
                $count = $this->db->prepare('SELECT COUNT(*) FROM household');
                $count->execute();
                if (DbValue::integer($count->fetchColumn()) > 0) {
                    throw new ApiError(403, 'registration_closed');
                }
            }
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
                if (strtotime(DbValue::text($invite['expires_at']) . ' UTC') <= time()) {
                    throw new ApiError(422, 'invite_expired');
                }
                $householdId = DbValue::integer($invite['household_id']);
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
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            if ($error instanceof \PDOException && $error->getCode() === '23000'
                && str_contains($error->getMessage(), 'uq_user_email')) {
                throw new ApiError(409, 'email_taken');
            }
            throw $error;
        } finally {
            if ($registrationLock) {
                $release = $this->db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute(['matsvep:household_bootstrap']);
            }
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
        $emailLimit = $this->config->integer('auth.login_max_attempts');
        $ipLimit = $this->config->integer('auth.login_max_attempts_ip');
        $count = $this->db->prepare('SELECT (SELECT COUNT(*) FROM app_login_attempt WHERE email_hash = ? AND attempted_at > DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) AS email_count, (SELECT COUNT(*) FROM app_login_attempt WHERE ip_address = ? AND attempted_at > DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND)) AS ip_count');
        $count->execute([$emailHash, $window, $ip, $window]);
        $attempts = $count->fetch(PDO::FETCH_ASSOC);
        if (is_array($attempts) && (DbValue::integer($attempts['email_count']) >= $emailLimit
            || DbValue::integer($attempts['ip_count']) >= $ipLimit)) {
            throw new ApiError(429, 'rate_limited');
        }
        $query = $this->db->prepare('SELECT id, password_hash FROM `user` WHERE email = ?');
        $query->execute([$email]);
        $user = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($user)) {
            password_verify($password, self::UNKNOWN_PASSWORD_HASH);
        }
        if (!is_array($user) || !password_verify($password, DbValue::text($user['password_hash']))) {
            $insert = $this->db->prepare('INSERT INTO app_login_attempt (email_hash, ip_address) VALUES (?, ?)');
            $insert->execute([$emailHash, $ip]);
            throw new ApiError(401, 'invalid_credentials');
        }
        $this->db->beginTransaction();
        try {
            $tokens = $this->issue(DbValue::integer($user['id']), $platform);
            $clear = $this->db->prepare('UPDATE app_login_attempt SET email_hash = NULL WHERE email_hash = ?');
            $clear->execute([$emailHash]);
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
            $query = $this->db->prepare('SELECT id, user_id, refresh_token_hash, revoked_at, expires_at FROM device_token WHERE refresh_token_hash = ? FOR UPDATE');
            $query->execute([$hash]);
            $device = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($device)) {
                $history = $this->db->prepare('SELECT h.rotation_id, d.id, d.user_id, d.refresh_token_hash, d.revoked_at, d.expires_at FROM device_refresh_history h JOIN device_token d ON d.id = h.device_token_id WHERE h.token_hash = ? FOR UPDATE');
                $history->execute([$hash]);
                $device = $history->fetch(PDO::FETCH_ASSOC);
                if (!is_array($device)) {
                    $this->db->commit();
                    throw new ApiError(401, 'refresh_token_invalid');
                }
                if ($device['revoked_at'] !== null || strtotime(DbValue::text($device['expires_at']) . ' UTC') <= time()) {
                    $this->db->commit();
                    throw new ApiError(401, 'refresh_token_invalid');
                }
                $latest = $this->db->prepare('SELECT rotation_id FROM device_refresh_history WHERE device_token_id = ? ORDER BY rotation_id DESC LIMIT 1');
                $latest->execute([$device['id']]);
                $grace = $this->db->prepare('SELECT rotated_at >= DATE_SUB(UTC_TIMESTAMP(6), INTERVAL ? SECOND) AND rotated_at <= UTC_TIMESTAMP(6) FROM device_refresh_history WHERE token_hash = ?');
                $grace->execute([$this->config->integer('auth.refresh_reuse_grace_s'), $hash]);
                if (DbValue::integer($latest->fetchColumn()) !== DbValue::integer($device['rotation_id'])
                    || DbValue::integer($grace->fetchColumn()) !== 1) {
                    $this->revokeDevice(DbValue::integer($device['id']));
                    $this->db->commit();
                    throw new ApiError(401, 'refresh_token_invalid');
                }
            }
            if ($device['revoked_at'] !== null || strtotime(DbValue::text($device['expires_at']) . ' UTC') <= time()) {
                $this->db->commit();
                throw new ApiError(401, 'refresh_token_invalid');
            }
            $result = $this->rotateDevice($device);
            $this->db->commit();
            return $result;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $device
     *  @return array<string,mixed> */
    private function rotateDevice(array $device): array
    {
        $history = $this->db->prepare('INSERT INTO device_refresh_history (token_hash, device_token_id) VALUES (?, ?)');
        $history->execute([$device['refresh_token_hash'], $device['id']]);
        $access = $this->randomToken();
        $refresh = $this->randomToken();
        $accessSeconds = $this->config->integer('auth.access_ttl_minutes') * 60;
        $refreshSeconds = $this->config->integer('auth.refresh_ttl_days') * 86400;
        $update = $this->db->prepare('UPDATE device_token SET refresh_token_hash = ?, access_token_hash = ?, access_expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), expires_at = DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? SECOND), last_seen_at = UTC_TIMESTAMP(6) WHERE id = ?');
        $update->execute([hash('sha256', $refresh), hash('sha256', $access), $accessSeconds, $refreshSeconds, $device['id']]);
        return $this->tokenResponse(DbValue::integer($device['user_id']), $access, $refresh, $accessSeconds);
    }

    /** @return array{user_id:int,device_id:int,role:string,household_id:?int} */
    public function authenticate(string $token): array
    {
        $query = $this->db->prepare('SELECT d.id AS device_id, d.user_id, d.access_expires_at, u.role, u.household_id FROM device_token d JOIN `user` u ON u.id = d.user_id WHERE d.access_token_hash = ? AND d.revoked_at IS NULL');
        $query->execute([hash('sha256', $token)]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || $row['access_expires_at'] === null
            || strtotime(DbValue::text($row['access_expires_at']) . ' UTC') <= time()) {
            throw new ApiError(401, 'access_token_expired');
        }
        return ['user_id' => DbValue::integer($row['user_id']), 'device_id' => DbValue::integer($row['device_id']), 'role' => DbValue::text($row['role']), 'household_id' => $row['household_id'] === null ? null : DbValue::integer($row['household_id'])];
    }

    public function revoke(string $refreshToken, int $userId): void
    {
        $query = $this->db->prepare('SELECT id FROM device_token WHERE refresh_token_hash = ? AND user_id = ?');
        $query->execute([hash('sha256', $refreshToken), $userId]);
        $id = $query->fetchColumn();
        if ($id === false) {
            throw new ApiError(401, 'refresh_token_invalid');
        }
        $this->revokeDevice(DbValue::integer($id));
    }

    public function revokeDevice(int $id): void
    {
        $update = $this->db->prepare('UPDATE device_token SET revoked_at = UTC_TIMESTAMP(6) WHERE id = ?');
        $update->execute([$id]);
    }

    public function revokedUserForReplay(string $token): ?int
    {
        $query = $this->db->prepare('SELECT user_id FROM device_token WHERE access_token_hash = ? AND revoked_at IS NOT NULL AND access_expires_at > UTC_TIMESTAMP(6)');
        $query->execute([hash('sha256', $token)]);
        $id = $query->fetchColumn();
        return $id === false ? null : DbValue::integer($id);
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
        $household = $row['household_id'] === null ? null : ['id' => DbValue::integer($row['household_id']), 'name' => $row['name'], 'size' => DbValue::integer($row['size']), 'timezone' => $row['timezone'], 'created_at' => str_replace(' ', 'T', DbValue::text($row['created_at'])) . 'Z'];
        return ['access_token' => $access, 'refresh_token' => $refresh, 'token_type' => 'bearer', 'expires_in_s' => $accessSeconds, 'user' => ['id' => DbValue::integer($row['id']), 'email' => $row['email'], 'role' => $row['role'], 'household_id' => $row['household_id'] === null ? null : DbValue::integer($row['household_id'])], 'household' => $household];
    }

    private function randomToken(): string
    {
        $bytes = $this->config->integer('auth.token_bytes');
        if ($bytes < 1) {
            throw new \RuntimeException('Invalid token length configuration.');
        }
        return bin2hex(random_bytes($bytes));
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
