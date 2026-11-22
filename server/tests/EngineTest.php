<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Engine\EngineConfig;
use Matsvep\Engine\FilterInput;
use Matsvep\Engine\Filters;
use Matsvep\Engine\ScoreInput;
use Matsvep\Engine\Scorer;
use PHPUnit\Framework\TestCase;

final class EngineTest extends TestCase
{
    /** @param array<string, string> $changes */
    private function config(array $changes = []): EngineConfig
    {
        return new EngineConfig(array_replace([
            'weight.pref' => '0.45', 'weight.dayfit' => '0.25', 'weight.due' => '0.15',
            'weight.explore' => '0.10', 'weight.variety' => '0.35', 'weight.skip' => '0.30',
            'pref.content_weight' => '1', 'pref.rating_weight' => '3', 'pref.sibling_weight' => '2',
            'content.beta.ingredients' => '0.30', 'content.beta.protein' => '0.25',
            'content.beta.cuisine' => '0.20', 'content.beta.meal_type' => '0.15',
            'content.beta.carb' => '0.10', 'content.confidence_epsilon' => '0.000001',
            'dayfit.weekday_max_minutes' => '60', 'dayfit.sunday_max_minutes' => '90',
            'dayfit.softcap_factor' => '0.3',
            'dayfit.leftovers.leftover_weight' => '0.7', 'dayfit.leftovers.effort_weight' => '0.3',
            'dayfit.friday.festive_weight' => '0.4', 'dayfit.saturday.festive_weight' => '0.6',
            'dayfit.saturday.low_leftover_weight' => '0.4', 'dayfit.friday_tags' => '["weekend","taco","pizza","burger"]',
            'filter.hide_at_or_below' => '2', 'cooldown.days.5' => '14',
            'cooldown.days.4' => '21', 'cooldown.days.3' => '42',
            'variety.max_protein_week' => '2', 'variety.max_cuisine_week' => '2',
            'variety.max_carb_week' => '3', 'variety.pen.adjacent_protein' => '0.5',
            'variety.pen.protein' => '0.4', 'variety.pen.cuisine' => '0.3',
            'variety.pen.carb' => '0.2', 'variety.pen.overlap' => '0.3',
            'variety.overlap_jaccard' => '0.5', 'variety.target_fish' => '1',
            'variety.target_veg' => '1', 'variety.target_days_left' => '2',
            'variety.bonus.fish' => '0.3', 'variety.bonus.vegetarian' => '0.2',
            'skip.replace_days' => '14', 'swipe.reject_sessions' => '5',
            'swipe.reject_days' => '30', 'wishlist.bonus' => '0.10',
        ], $changes));
    }

    /**
     * @param array{
     *   profile?: string, isSunday?: bool, totalTimeMinutes?: ?int, leftoverScore?: float,
     *   festiveScore?: float, effort?: int, tags?: list<string>, rating?: ?int,
     *   siblingMeanRating?: ?float,
     *   affinities?: array<string, list<array{affinity: float, confidence: float}>>,
     *   daysSinceGroupCooked?: ?int, meanConfidence?: float, mainProtein?: ?string,
     *   cuisine?: ?string, carbBase?: ?string, mainIngredientIds?: list<int>,
     *   recentDinners?: list<array{protein: ?string, cuisine: ?string, carb: ?string, ingredients: list<int>, adjacent: bool, chosen: bool, in_plan_week: bool, vegetarian: bool}>,
     *   daysLeftInPlanWeek?: int, vegetarian?: bool, blockVegetarian?: bool, daysSinceReplaced?: ?int,
     *   rejectedSessions?: int, daysSinceRejected?: ?int, wishlisted?: bool
     * } $changes
     */
    private function input(array $changes = []): ScoreInput
    {
        $data = array_replace([
            'profile' => 'weekday_leftovers', 'isSunday' => false, 'totalTimeMinutes' => 40,
            'leftoverScore' => 0.8, 'festiveScore' => 0.5, 'effort' => 2,
            'tags' => [], 'rating' => null, 'siblingMeanRating' => null,
            'affinities' => [], 'daysSinceGroupCooked' => null, 'meanConfidence' => 0.4,
            'mainProtein' => 'chicken', 'cuisine' => 'italian', 'carbBase' => 'pasta',
            'mainIngredientIds' => [1, 2], 'recentDinners' => [], 'daysLeftInPlanWeek' => 4,
            'vegetarian' => false, 'blockVegetarian' => false,
            'daysSinceReplaced' => null, 'rejectedSessions' => 0,
            'daysSinceRejected' => null, 'wishlisted' => false,
        ], $changes);
        return new ScoreInput(...$data);
    }

    public function testContentAndPreferenceFormulas(): void
    {
        $scorer = new Scorer($this->config());
        self::assertEqualsWithDelta(0.5, $scorer->content([]), 0.000001);
        $content = $scorer->content(['protein' => [['affinity' => 1.0, 'confidence' => 1.0]]]);
        self::assertEqualsWithDelta(0.625, $content, 0.000001);
        self::assertEqualsWithDelta(0.71875, $scorer->preference(0.625, 4, null), 0.000001);
        self::assertEqualsWithDelta(0.708333, $scorer->preference(0.625, null, 4.0), 0.000001);
    }

    public function testDayFitSoftCapsAndUnknownTime(): void
    {
        $scorer = new Scorer($this->config());
        self::assertEqualsWithDelta(0.66, $scorer->dayFit($this->input()), 0.000001);
        self::assertEqualsWithDelta(0.198, $scorer->dayFit($this->input(['totalTimeMinutes' => 61])), 0.000001);
        self::assertEqualsWithDelta(0.66, $scorer->dayFit($this->input(['totalTimeMinutes' => null])), 0.000001);
        self::assertEqualsWithDelta(0.66, $scorer->dayFit($this->input(['isSunday' => true, 'totalTimeMinutes' => 80])), 0.000001);
        self::assertSame(1.0, $scorer->dayFit($this->input(['profile' => 'friday_treat', 'tags' => ['pizza']])));
        self::assertEqualsWithDelta(0.2, $scorer->dayFit($this->input(['profile' => 'friday_treat'])), 0.000001);
        self::assertEqualsWithDelta(0.38, $scorer->dayFit($this->input(['profile' => 'saturday_dinner'])), 0.000001);
        $tuned = new Scorer($this->config(['dayfit.friday.festive_weight' => '0.8']));
        self::assertEqualsWithDelta(0.4, $tuned->dayFit($this->input(['profile' => 'friday_treat'])), 0.000001);
    }

    public function testDueAndSkipFormulas(): void
    {
        $scorer = new Scorer($this->config());
        self::assertSame(1.0, $scorer->due(5, null));
        self::assertSame(0.0, $scorer->due(null, null));
        self::assertEqualsWithDelta(0.5, $scorer->due(5, 21), 0.000001);
        self::assertSame(0.0, $scorer->due(3, 30));
        self::assertEqualsWithDelta(0.5, $scorer->skipPenalty(7, 0, null), 0.000001);
        self::assertEqualsWithDelta(0.5, $scorer->skipPenalty(null, 5, 15), 0.000001);
        self::assertSame(0.0, $scorer->skipPenalty(null, 4, 0));
    }

    public function testVarietyAndTotalFormula(): void
    {
        $scorer = new Scorer($this->config());
        $result = $scorer->score($this->input());
        self::assertEqualsWithDelta(0.5, $result['content'], 0.000001);
        self::assertEqualsWithDelta(0.66, $result['dayfit'], 0.000001);
        self::assertEqualsWithDelta(0.45 * 0.5 + 0.25 * 0.66 + 0.10 * 0.6, $result['total'], 0.000001);
        $dinner = ['protein' => 'chicken', 'cuisine' => 'italian', 'carb' => 'pasta',
            'ingredients' => [1, 2], 'adjacent' => true, 'chosen' => true, 'in_plan_week' => true, 'vegetarian' => false];
        self::assertSame(1.0, $scorer->varietyPenalty($this->input(['recentDinners' => [$dinner, $dinner]])));
        $fish = $this->input(['mainProtein' => 'fish', 'recentDinners' => [], 'daysLeftInPlanWeek' => 2]);
        self::assertEqualsWithDelta(-0.3, $scorer->varietyPenalty($fish), 0.000001);
        $fishScore = $scorer->score($fish);
        self::assertEqualsWithDelta(0.105, -0.35 * $fishScore['variety_pen'], 0.000001);
        $oldFish = ['protein' => 'fish', 'cuisine' => null, 'carb' => null, 'ingredients' => [],
            'adjacent' => false, 'chosen' => false, 'in_plan_week' => false, 'vegetarian' => false];
        self::assertEqualsWithDelta(-0.3, $scorer->varietyPenalty($this->input([
            'mainProtein' => 'fish', 'recentDinners' => [$oldFish], 'daysLeftInPlanWeek' => 2,
        ])), 0.000001);
        $oldFish['in_plan_week'] = true;
        self::assertSame(0.0, $scorer->varietyPenalty($this->input([
            'mainProtein' => 'fish', 'recentDinners' => [$oldFish], 'daysLeftInPlanWeek' => 2,
        ])));
        $oldVegetarian = ['protein' => 'vegetarian', 'cuisine' => null, 'carb' => null, 'ingredients' => [],
            'adjacent' => false, 'chosen' => false, 'in_plan_week' => false, 'vegetarian' => true];
        self::assertEqualsWithDelta(-0.2, $scorer->varietyPenalty($this->input([
            'mainProtein' => 'vegetarian', 'vegetarian' => true, 'recentDinners' => [$oldVegetarian],
            'daysLeftInPlanWeek' => 2,
        ])), 0.000001);
        $oldVegetarian['in_plan_week'] = true;
        self::assertSame(0.0, $scorer->varietyPenalty($this->input([
            'mainProtein' => 'vegetarian', 'vegetarian' => true, 'recentDinners' => [$oldVegetarian],
            'daysLeftInPlanWeek' => 2,
        ])));
    }

    public function testFilters(): void
    {
        $filters = new Filters($this->config());
        $input = new FilterInput(true, true, 'ok', 'available', false, false, 5, 13, false, false);
        self::assertSame(['cooldown'], $filters->reasons($input));
        self::assertTrue($filters->passes(new FilterInput(true, true, 'source_gone', 'available', false, false, 5, 14, false, false)));
        self::assertSame(
            ['inactive_recipe', 'not_dinner_candidate', 'blocked', 'vegetarian_blocked', 'low_rating', 'already_chosen'],
            $filters->reasons(new FilterInput(false, false, 'gone', 'blocked', true, true, 2, null, true, false))
        );
    }
}
