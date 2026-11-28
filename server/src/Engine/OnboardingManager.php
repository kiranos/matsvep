<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use DateTimeImmutable;
use DateTimeZone;
use Matsvep\Api\ApiError;
use Matsvep\Api\DbValue;
use Matsvep\Database\WriteTransaction;
use PDO;

final class OnboardingManager
{
    private const QUESTIONS = ['dish_rating', 'cuisine_rating', 'protein_rating', 'disliked_ingredients'];

    public function __construct(
        private readonly PDO $db,
        private readonly Learner $learner,
        private readonly ?DateTimeImmutable $now = null
    ) {
    }

    /** @return array<string,mixed> */
    public function questions(): array
    {
        $dishOptions = [];
        $seen = [];
        $dish = $this->db->prepare('SELECT id FROM recipe WHERE active = TRUE AND is_dinner_candidate = TRUE AND title LIKE ? ORDER BY id LIMIT 1');
        foreach ($this->seedLines('onboarding_dishes.csv') as $keyword) {
            $dish->execute(['%' . addcslashes($keyword, '%_\\') . '%']);
            $id = $dish->fetchColumn();
            if ($id !== false && !isset($seen[(string) $id])) {
                $seen[(string) $id] = true;
                $dishOptions[] = ['recipe_id' => DbValue::integer($id)];
            }
        }
        $cuisines = $this->db->prepare('SELECT DISTINCT cuisine FROM recipe WHERE active = TRUE AND cuisine IS NOT NULL ORDER BY cuisine');
        $cuisines->execute();
        $proteins = $this->db->prepare('SELECT DISTINCT main_protein FROM recipe WHERE active = TRUE AND main_protein IS NOT NULL ORDER BY main_protein');
        $proteins->execute();
        $ingredientOptions = [];
        $ingredient = $this->db->prepare('SELECT id FROM ingredient WHERE canonical_name = ? AND is_staple = FALSE');
        foreach ($this->seedLines('onboarding_disliked_ingredients.csv') as $name) {
            $ingredient->execute([$name]);
            $id = $ingredient->fetchColumn();
            if ($id !== false) {
                $ingredientOptions[] = ['ingredient_id' => DbValue::integer($id)];
            }
        }
        $codes = static fn (array $values): array => array_map(static fn (mixed $value): array => ['code' => DbValue::text($value)], $values);
        return ['items' => [
            ['question_id' => 'dish_rating', 'kind' => 'dish_rating', 'options' => $dishOptions],
            ['question_id' => 'cuisine_rating', 'kind' => 'cuisine_rating', 'options' => $codes($cuisines->fetchAll(PDO::FETCH_COLUMN))],
            ['question_id' => 'protein_rating', 'kind' => 'protein_rating', 'options' => $codes($proteins->fetchAll(PDO::FETCH_COLUMN))],
            ['question_id' => 'disliked_ingredients', 'kind' => 'disliked_ingredients', 'options' => $ingredientOptions],
        ]];
    }

    /** @return array<string,mixed> */
    public function answers(int $householdId): array
    {
        $query = $this->db->prepare('SELECT question_id, answer, answered_at FROM onboarding_answer WHERE household_id = ? ORDER BY question_id');
        $query->execute([$householdId]);
        $items = [];
        foreach (DbValue::rows($query->fetchAll(PDO::FETCH_ASSOC)) as $row) {
            $items[] = ['question_id' => $row['question_id'], 'answer' => DbValue::map(json_decode(DbValue::text($row['answer']), true, 512, JSON_THROW_ON_ERROR)),
                'answered_at' => self::timestamp($row['answered_at'])];
        }
        return ['items' => $items];
    }

    /** @param array<string,mixed> $input
     *  @return array<string,mixed> */
    public function saveAnswer(int $householdId, string $questionId, array $input): array
    {
        if (!in_array($questionId, self::QUESTIONS, true)) {
            throw new ApiError(404, 'not_found');
        }
        $answer = $input['answer'] ?? null;
        if (array_keys($input) !== ['answer'] || !is_array($answer) || array_is_list($answer)
            || array_filter(array_keys($answer), 'is_string') !== array_keys($answer)) {
            throw new ApiError(422, 'validation_failed');
        }
        /** @var array<string,mixed> $answer */
        if (!$this->validAnswer($questionId, $answer)) {
            throw new ApiError(422, 'validation_failed');
        }
        $this->requireReferences($questionId, $answer);
        return WriteTransaction::run($this->db, function () use ($householdId, $questionId, $answer): array {
            $write = $this->db->prepare('INSERT INTO onboarding_answer (household_id, question_id, answer, answered_at) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE answer = VALUES(answer), answered_at = VALUES(answered_at)');
            $answeredAt = ($this->now ?? new DateTimeImmutable('now'))
                ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            $write->execute([$householdId, $questionId, json_encode($answer, JSON_THROW_ON_ERROR), $answeredAt]);
            $read = $this->db->prepare('SELECT answered_at FROM onboarding_answer WHERE household_id = ? AND question_id = ?');
            $read->execute([$householdId, $questionId]);
            return ['question_id' => $questionId, 'answer' => $answer, 'answered_at' => self::timestamp($read->fetchColumn())];
        });
    }

    /** @return array<string,mixed> */
    public function complete(int $householdId): array
    {
        $result = $this->learner->applyOnboarding($householdId);
        return ['event' => ['code' => 'onboarding_completed', 'params' => $result]];
    }

    /** @param array<string,mixed> $answer */
    private function validAnswer(string $questionId, array $answer): bool
    {
        if (count($answer) !== 1) {
            return false;
        }
        $key = $questionId === 'disliked_ingredients' ? 'ingredient_ids' : 'ratings';
        $items = $answer[$key] ?? null;
        if (!is_array($items) || !array_is_list($items)) {
            return false;
        }
        $seen = [];
        foreach ($items as $item) {
            if ($questionId === 'disliked_ingredients') {
                if (!is_int($item) || $item < 1 || isset($seen[$item])) {
                    return false;
                }
                $seen[$item] = true;
                continue;
            }
            if (!is_array($item) || count($item) !== 2) {
                return false;
            }
            if ($questionId === 'dish_rating') {
                $id = $item['recipe_id'] ?? null;
                if (!is_int($id) || $id < 1 || !in_array($item['choice'] ?? null, ['favourite', 'ok', 'not_for_us'], true)) {
                    return false;
                }
                $unique = (string) $id;
            } else {
                $code = $item['code'] ?? null;
                $rating = $item['rating'] ?? null;
                if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]*$/', $code) !== 1
                    || !is_int($rating) || $rating < 1 || $rating > 5) {
                    return false;
                }
                $unique = $code;
            }
            if (isset($seen[$unique])) {
                return false;
            }
            $seen[$unique] = true;
        }
        return true;
    }

    /** @param array<string,mixed> $answer */
    private function requireReferences(string $questionId, array $answer): void
    {
        if ($questionId === 'dish_rating') {
            $query = $this->db->prepare('SELECT 1 FROM recipe WHERE id = ? AND active = TRUE AND is_dinner_candidate = TRUE');
            foreach (DbValue::rows($answer['ratings']) as $item) {
                $query->execute([$item['recipe_id']]);
                if ($query->fetchColumn() === false) {
                    throw new ApiError(422, 'validation_failed');
                }
            }
        }
        if ($questionId === 'disliked_ingredients') {
            $query = $this->db->prepare('SELECT 1 FROM ingredient WHERE id = ?');
            /** @var list<int> $ids */
            $ids = $answer['ingredient_ids'];
            foreach ($ids as $id) {
                $query->execute([$id]);
                if ($query->fetchColumn() === false) {
                    throw new ApiError(422, 'validation_failed');
                }
            }
        }
    }

    /** @return list<string> */
    private function seedLines(string $filename): array
    {
        $lines = file(dirname(__DIR__, 2) . '/seeds/' . $filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            throw new \RuntimeException('Missing onboarding seed data.');
        }
        return array_values(array_filter(array_map('trim', array_slice($lines, 1)), static fn (string $line): bool => $line !== '' && $line[0] !== '#'));
    }

    private static function timestamp(mixed $value): string
    {
        return str_replace(' ', 'T', DbValue::text($value)) . 'Z';
    }
}
