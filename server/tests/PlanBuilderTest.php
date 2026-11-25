<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\Explainer;
use Matsvep\Engine\PlanBuilder;
use Matsvep\Engine\PlanCandidate;
use Matsvep\Engine\PlanCandidatePool;
use PHPUnit\Framework\TestCase;

final class PlanBuilderTest extends TestCase
{
    private function config(): EngineConfig
    {
        $values = [];
        foreach (['017_engine_defaults.sql', '025_plan_builder_defaults.sql'] as $name) {
            $sql = file_get_contents(__DIR__ . '/../migrations/' . $name);
            self::assertIsString($sql);
            preg_match_all("/\\('([^']+)', '([^']*)', '(?:decimal|integer|json|string)'/", $sql, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $values[$match[1]] = $match[2];
            }
        }
        return new EngineConfig($values);
    }

    private function recipe(int $id, ?int $rating, ?int $group = null, float $leftover = 0.5, float $festive = 0.5): PlanCandidate
    {
        return new PlanCandidate(
            $id,
            $group ?? $id,
            $rating,
            null,
            null,
            40,
            $leftover,
            $festive,
            2,
            [],
            [],
            null,
            0.0,
            $id % 3 === 0 ? 'fish' : 'vegetarian',
            null,
            null,
            [$id],
            $id % 3 !== 0,
            false
        );
    }

    /** @return list<array{date: string, profile: string}> */
    private function week(): array
    {
        $days = [];
        foreach (['weekday_leftovers', 'weekday_leftovers', 'weekday_leftovers', 'weekday_leftovers',
            'weekday_leftovers', 'friday_treat', 'saturday_dinner'] as $offset => $profile) {
            $days[] = ['date' => (new \DateTimeImmutable('2026-11-22'))->modify('+' . $offset . ' days')->format('Y-m-d'),
                'profile' => $profile];
        }
        return $days;
    }

    /** @return list<PlanCandidate> */
    private function recipes(): array
    {
        $recipes = [];
        for ($id = 1; $id <= 14; $id++) {
            $recipes[] = $this->recipe($id, $id <= 8 ? 5 : null, null, ($id % 4) / 4, ($id % 5) / 5);
        }
        return $recipes;
    }

    public function testGoldenPlanAndDeterminism(): void
    {
        $builder = new PlanBuilder($this->config());
        $seed = PlanBuilder::seed(17, '2026-11-22', 1, 0);
        $first = $builder->build($this->week(), $this->recipes(), 0.7, $seed);
        $second = $builder->build($this->week(), $this->recipes(), 0.7, $seed);
        self::assertSame($first, $second);
        self::assertSame(
            [2, 3, 7, 6, 13, 9, 8],
            array_column(array_values($first['slots']), 'recipe_id')
        );
        self::assertCount(5, array_filter($first['slots'], static fn (array $slot): bool => $slot['kind'] === 'favourite'));
        foreach ($first['decks'] as $date => $deck) {
            self::assertSame($first['slots'][$date]['recipe_id'], $deck[0]['recipe_id']);
            self::assertLessThanOrEqual(20, count($deck));
        }
    }

    public function testSplitWeekQuotaSubtractsEarlierFavourites(): void
    {
        $builder = new PlanBuilder($this->config());
        $week = $this->week();
        $earlier = [
            ['date' => $week[0]['date'], 'recipe_id' => 1, 'kind' => 'favourite'],
            ['date' => $week[1]['date'], 'recipe_id' => 2, 'kind' => 'favourite'],
            ['date' => $week[2]['date'], 'recipe_id' => 9, 'kind' => 'discovery'],
        ];
        $secondHalf = $builder->build(array_slice($week, 3), $this->recipes(), 0.7, 'split', [], [], $earlier);
        self::assertCount(3, array_filter(
            $secondHalf['slots'],
            static fn (array $slot): bool => $slot['kind'] === 'favourite'
        ));
    }

    public function testCarryoverTakesBestDayAndDishGroupsStayUnique(): void
    {
        $builder = new PlanBuilder($this->config());
        $recipes = $this->recipes();
        $recipes[] = $this->recipe(15, 5, 1, 0.0, 1.0);
        $result = $builder->build(
            $this->week(),
            $recipes,
            0.7,
            'carryover',
            [],
            [['recipe_id' => 15, 'from_date' => '2026-11-20']]
        );
        self::assertSame(15, $result['slots']['2026-11-28']['recipe_id']);
        $reasons = $result['slots']['2026-11-28']['explanation']['reasons'];
        self::assertIsArray($reasons);
        self::assertIsArray($reasons[0]);
        self::assertSame('carried_over', $reasons[0]['code']);
        $ids = array_column(array_values($result['slots']), 'recipe_id');
        self::assertNotContains(1, $ids);
        foreach ($result['decks'] as $deck) {
            $groups = [];
            foreach ($deck as $card) {
                $group = $card['recipe_id'] === 15 ? 1 : $card['recipe_id'];
                self::assertArrayNotHasKey($group, $groups);
                $groups[$group] = true;
            }
        }
    }

    public function testRerankingUsesFixedChoiceAndKeepsOtherChosenDays(): void
    {
        $builder = new PlanBuilder($this->config());
        $first = $builder->build($this->week(), $this->recipes(), 0.7, 'initial');
        $picked = $first['decks']['2026-11-22'][1]['recipe_id'];
        $second = $builder->build(
            $this->week(),
            $this->recipes(),
            0.7,
            'initial',
            ['2026-11-22' => $picked]
        );
        self::assertSame($picked, $second['slots']['2026-11-22']['recipe_id']);
        self::assertArrayNotHasKey('2026-11-22', $second['decks']);
        self::assertNotSame($first['decks']['2026-11-23'], $second['decks']['2026-11-23']);
    }

    public function testExplanationUsesCodesAndRetainsScoreComponents(): void
    {
        $explainer = new Explainer($this->config());
        $score = ['content' => 0.8, 'pref' => 0.9, 'dayfit' => 0.8, 'due' => 0.1,
            'explore' => 0.0, 'variety_pen' => 0.2, 'skip_pen' => 0.0,
            'wishlist_bonus' => 0.0, 'total' => 0.54];
        $rated = $explainer->explain($this->recipe(1, 5), 'weekday_leftovers', $score);
        self::assertSame('rated_stars', $rated['reasons'][0]['code']);
        self::assertSame(['stars' => 5], $rated['reasons'][0]['params']);
        self::assertSame($score, $rated['breakdown']);
        $similar = $this->recipe(2, null)->withSimilarity(1, 5);
        self::assertSame(
            'similar_rated_recipe',
            $explainer->explain($similar, 'weekday_leftovers', $score)['reasons'][0]['code']
        );
        $variant = new PlanCandidate(
            3,
            9,
            null,
            5.0,
            null,
            30,
            0.5,
            0.5,
            2,
            [],
            [],
            null,
            0.0,
            null,
            null,
            null,
            [],
            null,
            false
        );
        self::assertSame(
            'liked_dish_group_variant',
            $explainer->explain($variant, 'weekday_leftovers', $score)['reasons'][0]['code']
        );
    }

    public function testDiscoveryPrefilterKeepsTopContentAndEveryWishlist(): void
    {
        $recipes = [];
        for ($id = 1; $id <= 305; $id++) {
            $recipes[] = new PlanCandidate(
                $id,
                $id,
                null,
                null,
                null,
                40,
                0.5,
                0.5,
                2,
                [],
                $id === 305 ? ['protein' => [['affinity' => 1.0, 'confidence' => 1.0]]] : [],
                null,
                0.0,
                'fish',
                null,
                null,
                [],
                false,
                $id === 304
            );
        }
        $pool = (new PlanCandidatePool($this->config()))->select($recipes);
        $ids = array_map(static fn (PlanCandidate $candidate): int => $candidate->id, $pool);
        self::assertCount(301, $pool);
        self::assertContains(304, $ids);
        self::assertContains(305, $ids);
        self::assertNotContains(303, $ids);
    }
}
