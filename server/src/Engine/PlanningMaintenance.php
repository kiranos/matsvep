<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use DateTimeImmutable;
use DateTimeZone;
use Matsvep\Api\DbValue;
use Matsvep\Database\WriteTransaction;
use PDO;

final class PlanningMaintenance
{
    public function __construct(
        private readonly PDO $db,
        private readonly EngineConfig $config,
        private readonly ?DateTimeImmutable $now = null
    ) {
    }

    /** @return array{unconfirmed:int,expired_carryovers:int} */
    public function run(): array
    {
        return WriteTransaction::run($this->db, function (): array {
            $now = $this->now ?? new DateTimeImmutable('now');
            $unconfirmedDays = $this->config->integer('after_dinner.unconfirmed_days');
            $expireWeeks = $this->config->integer('carryover.expire_weeks');
            $counts = ['unconfirmed' => 0, 'expired_carryovers' => 0];

            $chosen = $this->db->prepare("SELECT s.id, s.household_id, s.recipe_id, s.date, h.timezone "
                . "FROM plan_slot s JOIN household h ON h.id = s.household_id "
                . "WHERE s.status = 'chosen' FOR UPDATE");
            $chosen->execute();
            $slots = DbValue::rows($chosen->fetchAll(PDO::FETCH_ASSOC));
            $mark = $this->db->prepare("UPDATE plan_slot SET status = 'unconfirmed' WHERE id = ? AND status = 'chosen'");
            $meal = $this->db->prepare('INSERT IGNORE INTO meal_log '
                . '(household_id, recipe_id, cooked_on, plan_slot_id) VALUES (?, ?, ?, ?)');
            $state = $this->db->prepare('INSERT INTO household_recipe '
                . '(household_id, recipe_id, times_cooked, last_cooked_at, wishlist) VALUES (?, ?, 1, ?, FALSE) '
                . 'ON DUPLICATE KEY UPDATE times_cooked = times_cooked + 1, '
                . 'last_cooked_at = GREATEST(COALESCE(last_cooked_at, VALUES(last_cooked_at)), VALUES(last_cooked_at)), '
                . 'wishlist = FALSE');
            foreach ($slots as $slot) {
                $zone = new DateTimeZone(DbValue::text($slot['timezone']));
                $today = $now->setTimezone($zone)->format('Y-m-d');
                $deadline = (new DateTimeImmutable(DbValue::text($slot['date']), $zone))
                    ->modify('+' . $unconfirmedDays . ' days')->format('Y-m-d');
                if ($today <= $deadline) {
                    continue;
                }
                $mark->execute([$slot['id']]);
                if ($mark->rowCount() !== 1) {
                    continue;
                }
                $meal->execute([$slot['household_id'], $slot['recipe_id'], $slot['date'], $slot['id']]);
                if ($meal->rowCount() === 1) {
                    $state->execute([$slot['household_id'], $slot['recipe_id'], $slot['date']]);
                }
                $counts['unconfirmed']++;
            }

            $pending = $this->db->prepare("SELECT c.id, c.household_id, c.recipe_id, c.target_week, h.timezone "
                . "FROM carryover c JOIN household h ON h.id = c.household_id "
                . "WHERE c.status = 'pending' FOR UPDATE");
            $pending->execute();
            $carryovers = DbValue::rows($pending->fetchAll(PDO::FETCH_ASSOC));
            $expire = $this->db->prepare("UPDATE carryover SET status = 'expired' WHERE id = ? AND status = 'pending'");
            $wishlist = $this->db->prepare('INSERT INTO household_recipe (household_id, recipe_id, wishlist) '
                . 'VALUES (?, ?, TRUE) ON DUPLICATE KEY UPDATE wishlist = TRUE');
            foreach ($carryovers as $carryover) {
                $zone = new DateTimeZone(DbValue::text($carryover['timezone']));
                $today = $now->setTimezone($zone)->format('Y-m-d');
                $deadline = (new DateTimeImmutable(DbValue::text($carryover['target_week']), $zone))
                    ->modify('+' . $expireWeeks . ' weeks')->format('Y-m-d');
                if ($today < $deadline) {
                    continue;
                }
                $expire->execute([$carryover['id']]);
                if ($expire->rowCount() !== 1) {
                    continue;
                }
                $wishlist->execute([$carryover['household_id'], $carryover['recipe_id']]);
                $counts['expired_carryovers']++;
            }
            return $counts;
        });
    }
}
