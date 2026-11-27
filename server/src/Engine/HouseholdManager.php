<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use Matsvep\Api\ApiError;
use Matsvep\Api\DbValue;
use Matsvep\Database\WriteTransaction;
use PDO;

final class HouseholdManager
{
    public function __construct(private readonly PDO $db, private readonly EngineConfig $config)
    {
    }

    /** @return array<string,mixed> */
    public function get(int $householdId): array
    {
        $query = $this->db->prepare('SELECT id, name, size, timezone, created_at FROM household WHERE id = ?');
        $query->execute([$householdId]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new ApiError(404, 'not_found');
        }
        return ['id' => DbValue::integer($row['id']), 'name' => $row['name'], 'size' => DbValue::integer($row['size']),
            'timezone' => $row['timezone'], 'created_at' => self::timestamp($row['created_at'])];
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    public function update(int $householdId, array $input): array
    {
        if ($input === [] || array_diff(array_keys($input), ['name', 'size', 'timezone']) !== []) {
            throw new ApiError(422, 'validation_failed');
        }
        return WriteTransaction::run($this->db, function () use ($householdId, $input): array {
            $current = $this->get($householdId);
            $name = array_key_exists('name', $input) ? $input['name'] : $current['name'];
            $size = array_key_exists('size', $input) ? $input['size'] : $current['size'];
            $timezone = array_key_exists('timezone', $input) ? $input['timezone'] : $current['timezone'];
            if (!is_string($name) || trim($name) === '' || !is_int($size) || $size < 1 || $size > 100
                || !is_string($timezone) || !in_array($timezone, timezone_identifiers_list(), true)) {
                throw new ApiError(422, 'validation_failed');
            }
            $update = $this->db->prepare('UPDATE household SET name = ?, size = ?, timezone = ? WHERE id = ?');
            $update->execute([trim($name), $size, $timezone, $householdId]);
            return $this->get($householdId);
        });
    }

    /** @return array<string,mixed> */
    public function createInvite(int $householdId, int $userId): array
    {
        return WriteTransaction::run($this->db, function () use ($householdId, $userId): array {
            $code = bin2hex(random_bytes($this->config->integer('auth.token_bytes')));
            $hours = $this->config->integer('household.invite_ttl_hours');
            $insert = $this->db->prepare('INSERT INTO household_invite (household_id, created_by, code_hash, expires_at) VALUES (?, ?, ?, DATE_ADD(UTC_TIMESTAMP(6), INTERVAL ? HOUR))');
            $insert->execute([$householdId, $userId, hash('sha256', $code), $hours]);
            $expires = $this->db->prepare('SELECT expires_at FROM household_invite WHERE id = ? AND household_id = ?');
            $expires->execute([(int) $this->db->lastInsertId(), $householdId]);
            return ['invite_code' => $code, 'expires_at' => self::timestamp($expires->fetchColumn()),
                'event' => self::event('invite_created')];
        });
    }

    /** @return array<string,mixed> */
    public function settings(int $householdId): array
    {
        $defaultDays = $this->config->integer('builder.default_plan_days');
        $allSeven = $this->config->integer('builder.plan_days.all_seven');
        $sundayThursday = $this->config->integer('builder.plan_days.sunday_thursday');
        if ($defaultDays !== $allSeven && $defaultDays !== $sundayThursday) {
            throw new \RuntimeException('Invalid default plan days.');
        }
        $values = ['favourite_share' => $this->config->decimal('builder.default_favourite_share'),
            'plan_days' => $defaultDays === $allSeven ? 'all_seven' : 'sunday_thursday',
            'planning_mode' => 'whole_week', 'block_vegetarian' => false];
        $query = $this->db->prepare('SELECT `key`, value FROM household_setting WHERE household_id = ?');
        $query->execute([$householdId]);
        foreach (DbValue::rows($query->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $key = DbValue::text($row['key']);
            if (array_key_exists($key, $values)) {
                $values[$key] = json_decode(DbValue::text($row['value']), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        return $values;
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    public function updateSettings(int $householdId, array $input): array
    {
        if ($input === [] || array_diff(array_keys($input), ['favourite_share', 'plan_days', 'planning_mode', 'block_vegetarian']) !== []) {
            throw new ApiError(422, 'validation_failed');
        }
        foreach ($input as $key => $value) {
            $valid = match ($key) {
                'favourite_share' => (is_int($value) || is_float($value)) && $value >= 0 && $value <= 1,
                'plan_days' => in_array($value, ['all_seven', 'sunday_thursday'], true),
                'planning_mode' => in_array($value, ['whole_week', 'split_week'], true),
                'block_vegetarian' => is_bool($value),
                default => false,
            };
            if (!$valid) {
                throw new ApiError(422, 'validation_failed');
            }
        }
        return WriteTransaction::run($this->db, function () use ($householdId, $input): array {
            $upsert = $this->db->prepare('INSERT INTO household_setting (household_id, `key`, value) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)');
            foreach ($input as $key => $value) {
                $upsert->execute([$householdId, $key, json_encode($value, JSON_THROW_ON_ERROR)]);
            }
            return $this->settings($householdId);
        });
    }

    /** @return array{items:list<array{weekday:int,profile_code:string}>} */
    public function dayProfiles(int $householdId): array
    {
        $query = $this->db->prepare('SELECT d.weekday, COALESCE(p.profile_code, d.profile_code) AS profile_code FROM day_profile_default d LEFT JOIN day_profile p ON p.weekday = d.weekday AND p.household_id = ? ORDER BY d.weekday');
        $query->execute([$householdId]);
        $items = [];
        foreach (DbValue::rows($query->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $items[] = ['weekday' => DbValue::integer($row['weekday']), 'profile_code' => DbValue::text($row['profile_code'])];
        }
        return ['items' => $items];
    }

    /** @param array<string,mixed> $input
     *  @return array{items:list<array{weekday:int,profile_code:string}>} */
    public function setDayProfiles(int $householdId, array $input): array
    {
        $items = $input['items'] ?? null;
        if (array_keys($input) !== ['items'] || !is_array($items) || !array_is_list($items) || count($items) !== 7) {
            throw new ApiError(422, 'validation_failed');
        }
        $profiles = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_int($item['weekday'] ?? null) || !is_string($item['profile_code'] ?? null)
                || $item['weekday'] < 0 || $item['weekday'] > 6 || isset($profiles[$item['weekday']])
                || !in_array($item['profile_code'], ['weekday_leftovers', 'friday_treat', 'saturday_dinner'], true)) {
                throw new ApiError(422, 'validation_failed');
            }
            $profiles[$item['weekday']] = $item['profile_code'];
        }
        return WriteTransaction::run($this->db, function () use ($householdId, $profiles): array {
            $delete = $this->db->prepare('DELETE FROM day_profile WHERE household_id = ?');
            $delete->execute([$householdId]);
            $insert = $this->db->prepare('INSERT INTO day_profile (household_id, weekday, profile_code) VALUES (?, ?, ?)');
            foreach ($profiles as $weekday => $profile) {
                $insert->execute([$householdId, $weekday, $profile]);
            }
            return $this->dayProfiles($householdId);
        });
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    public function updateDevice(int $householdId, int $userId, int $deviceId, array $input): array
    {
        if (array_keys($input) !== ['push_token'] || ($input['push_token'] !== null
            && (!is_string($input['push_token']) || strlen($input['push_token']) > 512))) {
            throw new ApiError(422, 'validation_failed');
        }
        return WriteTransaction::run($this->db, function () use ($householdId, $userId, $deviceId, $input): array {
            $update = $this->db->prepare('UPDATE device_token d JOIN `user` u ON u.id = d.user_id SET d.push_token = ?, d.last_seen_at = UTC_TIMESTAMP(6) WHERE d.id = ? AND d.user_id = ? AND u.household_id = ? AND d.revoked_at IS NULL');
            $update->execute([$input['push_token'], $deviceId, $userId, $householdId]);
            $query = $this->db->prepare('SELECT d.id, d.platform, d.push_token, d.last_seen_at FROM device_token d JOIN `user` u ON u.id = d.user_id WHERE d.id = ? AND d.user_id = ? AND u.household_id = ? AND d.revoked_at IS NULL');
            $query->execute([$deviceId, $userId, $householdId]);
            $row = $query->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row)) {
                throw new ApiError(404, 'not_found');
            }
            return ['id' => DbValue::integer($row['id']), 'platform' => $row['platform'],
                'push_token_registered' => $row['push_token'] !== null, 'last_seen_at' => self::timestamp($row['last_seen_at'])];
        });
    }

    /** @return array{code:string,params:array<string,mixed>} */
    private static function event(string $code): array
    {
        return ['code' => $code, 'params' => []];
    }

    private static function timestamp(mixed $value): string
    {
        return str_replace(' ', 'T', DbValue::text($value)) . 'Z';
    }
}
