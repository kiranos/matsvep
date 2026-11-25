<?php

declare(strict_types=1);

namespace Matsvep\Engine;

use Closure;
use InvalidArgumentException;

final class PlanBuilder
{
    /** @var array<int, string> */
    private array $kindCache = [];

    /** @var array<string, int> */
    private array $weekdayCache = [];

    /** @var array<string, list<array{protein: ?string, cuisine: ?string, carb: ?string, ingredients: list<int>, adjacent: bool, chosen: bool, in_plan_week: bool, vegetarian: bool}>> */
    private array $recentCache = [];

    /** @var list<array{date: string, recipe_id: int}> */
    private array $recentHistory = [];

    /** @var list<PlanCandidate> */
    private array $likedRecipes = [];

    /** @var array<int, PlanCandidate> */
    private array $explainedCandidates = [];

    /** @var \WeakMap<PlanCandidate, array<string, array<string, float>>> */
    private \WeakMap $baseScoreCache;

    /** @var \WeakMap<PlanCandidate, array{score: array<string, float>, date: string, profile: string, block: bool}> */
    private \WeakMap $referenceScoreCache;

    private readonly Scorer $scorer;

    private readonly Explainer $explainer;

    public function __construct(private readonly EngineConfig $config, private readonly ?Closure $observer = null)
    {
        $this->scorer = new Scorer($config);
        $this->explainer = new Explainer($config);
        $this->baseScoreCache = new \WeakMap();
        $this->referenceScoreCache = new \WeakMap();
    }

    public static function seed(int $householdId, string $weekStart, int $part, int $reshuffleCount): string
    {
        return hash('sha256', implode('|', [$householdId, $weekStart, $part, $reshuffleCount]));
    }

    /**
     * @param list<array{date: string, profile: string}> $days
     * @param list<PlanCandidate> $recipes Hard-filtered candidates, including carry-overs and fixed choices.
     * @param array<string, int> $fixed Date to already chosen recipe ID.
     * @param list<array{recipe_id: int, from_date: string}> $carryovers
     * @param list<array{date: string, recipe_id: int, kind: string}> $earlierWeek
     * @param list<array{date: string, recipe_id: int}> $recentHistory
     * @return array{slots: array<string, array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}>, decks: array<string, list<array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}>>}
     */
    public function build(
        array $days,
        array $recipes,
        float $favouriteShare,
        string $seed,
        array $fixed = [],
        array $carryovers = [],
        array $earlierWeek = [],
        bool $blockVegetarian = false,
        ?int $weekDayCount = null,
        array $recentHistory = [],
    ): array {
        $this->kindCache = [];
        $this->recentCache = [];
        $this->explainedCandidates = [];
        $this->recentHistory = $recentHistory;
        $minimumRating = $this->config->integer('explain.similar_rating_min');
        $this->likedRecipes = array_values(array_filter(
            $recipes,
            static fn (PlanCandidate $recipe): bool => $recipe->rating !== null && $recipe->rating >= $minimumRating
        ));
        $weekDayCount ??= $this->config->integer('builder.default_plan_days');
        if ($favouriteShare < 0 || $favouriteShare > 1 || $weekDayCount < count($days)) {
            throw new InvalidArgumentException('Invalid planning quota.');
        }
        $byId = [];
        foreach ($recipes as $recipe) {
            $byId[$recipe->id] = $recipe;
        }
        $profiles = [];
        foreach ($days as $day) {
            $profiles[$day['date']] = $day['profile'];
        }
        if (count($profiles) !== count($days)) {
            throw new InvalidArgumentException('Planning dates must be unique.');
        }
        $slots = [];
        $usedGroups = [];
        $favouritesChosen = 0;
        foreach ($earlierWeek as $prior) {
            if ($prior['kind'] === 'favourite') {
                $favouritesChosen++;
            }
        }
        $pinnedDates = $fixed;
        $carryoverDates = [];
        foreach ($fixed as $date => $recipeId) {
            if (!isset($profiles[$date], $byId[$recipeId])) {
                throw new InvalidArgumentException('Unknown fixed planning choice.');
            }
            $recipe = $byId[$recipeId];
            $kind = $this->chosenKind($recipe);
            $usedGroups[$recipe->groupId] = true;
            $favouritesChosen += $kind === 'favourite' ? 1 : 0;
            $slots[$date] = $this->card($recipe, $kind, $date, $profiles[$date], $slots, $earlierWeek, $byId, $blockVegetarian);
        }
        foreach ($carryovers as $carryover) {
            $recipe = $byId[$carryover['recipe_id']] ?? null;
            if ($recipe === null || isset($usedGroups[$recipe->groupId])) {
                continue;
            }
            $bestDate = null;
            $bestFit = -INF;
            foreach ($profiles as $date => $profile) {
                if (isset($slots[$date])) {
                    continue;
                }
                $fit = $this->scorer->dayFit($recipe->scoreInput(
                    $profile,
                    $this->weekday($date) === 0,
                    [],
                    $this->daysLeft($date),
                    $blockVegetarian,
                ));
                if ($fit > $bestFit || ($fit === $bestFit && ($bestDate === null || $date < $bestDate))) {
                    $bestFit = $fit;
                    $bestDate = $date;
                }
            }
            if ($bestDate !== null) {
                $kind = $this->chosenKind($recipe);
                $usedGroups[$recipe->groupId] = true;
                $pinnedDates[$bestDate] = $recipe->id;
                $carryoverDates[$bestDate] = $carryover['from_date'];
                $favouritesChosen += $kind === 'favourite' ? 1 : 0;
                $slots[$bestDate] = $this->card(
                    $recipe,
                    $kind,
                    $bestDate,
                    $profiles[$bestDate],
                    $slots,
                    $earlierWeek,
                    $byId,
                    $blockVegetarian,
                    $carryover['from_date'],
                );
            }
        }
        $targetFavourites = (int) round($weekDayCount * $favouriteShare);
        $remainingFavourite = max(0, $targetFavourites - $favouritesChosen);
        $priority = array_flip($this->config->stringList('builder.profile_order'));
        $openDays = array_values(array_filter($days, static fn (array $day): bool => !isset($slots[$day['date']])));
        usort($openDays, static fn (array $a, array $b): int =>
            (($priority[$a['profile']] ?? count($priority)) <=> ($priority[$b['profile']] ?? count($priority)))
            ?: strcmp($a['date'], $b['date']));
        $phaseStarted = hrtime(true);
        foreach ($openDays as $index => $day) {
            $date = $day['date'];
            $ranked = ['favourite' => [], 'discovery' => []];
            $daysRemaining = count($openDays) - $index;
            $forcedKind = $remainingFavourite >= $daysRemaining ? 'favourite'
                : ($remainingFavourite <= 0 ? 'discovery' : null);
            foreach ($recipes as $recipe) {
                if (isset($usedGroups[$recipe->groupId])) {
                    continue;
                }
                $kind = $this->kind($recipe);
                if ($kind === 'unavailable') {
                    continue;
                }
                if ($forcedKind !== null && $kind !== $forcedKind) {
                    continue;
                }
                $card = $this->card($recipe, $kind, $date, $day['profile'], $slots, $earlierWeek, $byId, $blockVegetarian);
                $ranked[$kind][] = $card;
            }
            if ($forcedKind !== null && $ranked[$forcedKind] === []) {
                $otherKind = $forcedKind === 'favourite' ? 'discovery' : 'favourite';
                foreach ($recipes as $recipe) {
                    if (!isset($usedGroups[$recipe->groupId]) && $this->kind($recipe) === $otherKind) {
                        $ranked[$otherKind][] = $this->card(
                            $recipe,
                            $otherKind,
                            $date,
                            $day['profile'],
                            $slots,
                            $earlierWeek,
                            $byId,
                            $blockVegetarian
                        );
                    }
                }
            }
            foreach ($ranked as &$pool) {
                usort($pool, self::compareCards(...));
            }
            unset($pool);
            $kind = $remainingFavourite >= $daysRemaining && $ranked['favourite'] !== [] ? 'favourite' :
                ($ranked['favourite'] !== [] && $remainingFavourite > 0 &&
                    ($ranked['discovery'] === [] || $ranked['favourite'][0]['score'] >= $ranked['discovery'][0]['score'])
                    ? 'favourite' : 'discovery');
            if ($ranked[$kind] === []) {
                $kind = $kind === 'favourite' ? 'discovery' : 'favourite';
            }
            if ($ranked[$kind] === []) {
                continue;
            }
            $chosen = $this->sample($ranked[$kind], $seed, $date);
            $slots[$date] = $chosen;
            $usedGroups[$byId[$chosen['recipe_id']]->groupId] = true;
            if ($kind === 'favourite') {
                $remainingFavourite--;
            }
        }
        $this->observe('select_slots', $phaseStarted);
        $phaseStarted = hrtime(true);
        $this->swap($slots, $pinnedDates, $profiles, $byId, $earlierWeek, $blockVegetarian);
        $this->observe('swap', $phaseStarted);
        $phaseStarted = hrtime(true);
        foreach ($slots as $date => $slot) {
            $slots[$date] = $this->card(
                $byId[$slot['recipe_id']],
                $slot['kind'],
                $date,
                $profiles[$date],
                $slots,
                $earlierWeek,
                $byId,
                $blockVegetarian,
                $carryoverDates[$date] ?? null,
                $date,
                true
            );
        }
        $this->observe('explain_slots', $phaseStarted);
        ksort($slots);
        $decks = [];
        $phaseStarted = hrtime(true);
        foreach ($days as $day) {
            $date = $day['date'];
            if (isset($fixed[$date])) {
                continue;
            }
            $otherGroups = [];
            foreach ($slots as $otherDate => $slot) {
                if ($date !== $otherDate) {
                    $otherGroups[$byId[$slot['recipe_id']]->groupId] = true;
                }
            }
            $pools = ['favourite' => [], 'discovery' => []];
            foreach ($recipes as $recipe) {
                if (isset($otherGroups[$recipe->groupId])) {
                    continue;
                }
                $kind = $this->kind($recipe);
                if ($kind !== 'unavailable') {
                    $pools[$kind][] = $this->card(
                        $recipe,
                        $kind,
                        $date,
                        $day['profile'],
                        $slots,
                        $earlierWeek,
                        $byId,
                        $blockVegetarian,
                        null,
                        $date,
                    );
                }
            }
            foreach ($pools as &$pool) {
                usort($pool, self::compareCards(...));
            }
            unset($pool);
            $deck = $this->interleave($pools, $favouriteShare, $byId, $slots[$date] ?? null);
            foreach ($deck as $position => $card) {
                if ($position === 0 && isset($slots[$date]) && $card['recipe_id'] === $slots[$date]['recipe_id']) {
                    $deck[$position] = $slots[$date];
                } else {
                    $deck[$position] = $this->card(
                        $byId[$card['recipe_id']],
                        $card['kind'],
                        $date,
                        $day['profile'],
                        $slots,
                        $earlierWeek,
                        $byId,
                        $blockVegetarian,
                        null,
                        $date,
                        true
                    );
                }
            }
            $decks[$date] = $deck;
        }
        $this->observe('build_decks', $phaseStarted);
        return ['slots' => $slots, 'decks' => $decks];
    }

    private function observe(string $phase, int $started): void
    {
        if ($this->observer !== null) {
            ($this->observer)($phase, (hrtime(true) - $started) / 1_000_000);
        }
    }

    private function kind(PlanCandidate $recipe): string
    {
        if (isset($this->kindCache[$recipe->id])) {
            return $this->kindCache[$recipe->id];
        }
        if ($recipe->rating === null) {
            return $this->kindCache[$recipe->id] = 'discovery';
        }
        $content = $this->scorer->content($recipe->affinities);
        $pref = $this->scorer->preference($content, $recipe->rating, $recipe->siblingMeanRating);
        return $this->kindCache[$recipe->id] = $pref >= $this->config->decimal('builder.fav_threshold')
            ? 'favourite' : 'unavailable';
    }

    private function chosenKind(PlanCandidate $recipe): string
    {
        $kind = $this->kind($recipe);
        return $kind === 'unavailable' ? 'favourite' : $kind;
    }

    /** @param array<string, array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}> $slots
     *  @param list<array{date: string, recipe_id: int, kind: string}> $earlierWeek
     *  @param array<int, PlanCandidate> $byId
     *  @return array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}
     */
    private function card(PlanCandidate $recipe, string $kind, string $date, string $profile, array $slots, array $earlierWeek, array $byId, bool $blockVegetarian, ?string $fromDate = null, ?string $excludeDate = null, bool $withExplanation = false): array
    {
        $historyKey = $date . '|' . $excludeDate;
        foreach ($slots as $otherDate => $slot) {
            if ($otherDate !== $excludeDate) {
                $historyKey .= '|' . $otherDate . ':' . $slot['recipe_id'];
            }
        }
        if (!isset($this->recentCache[$historyKey])) {
            $recent = [];
            foreach ($this->recentHistory as $history) {
                if (isset($byId[$history['recipe_id']]) &&
                    (new \DateTimeImmutable($date))->diff(new \DateTimeImmutable($history['date']))->days
                        <= $this->config->integer('variety.history_days')) {
                    $recent[] = $this->recent($byId[$history['recipe_id']], $date, $history['date'], false);
                }
            }
            foreach ($earlierWeek as $prior) {
                if (isset($byId[$prior['recipe_id']])) {
                    $recent[] = $this->recent($byId[$prior['recipe_id']], $date, $prior['date']);
                }
            }
            foreach ($slots as $otherDate => $slot) {
                if ($otherDate !== $excludeDate && isset($byId[$slot['recipe_id']])) {
                    $recent[] = $this->recent($byId[$slot['recipe_id']], $date, $otherDate);
                }
            }
            $this->recentCache[$historyKey] = $recent;
        }
        $recent = $this->recentCache[$historyKey];
        $baseKey = $date . '|' . $profile . '|' . (int) $blockVegetarian;
        $scores = $this->baseScoreCache[$recipe] ?? [];
        if (!isset($scores[$baseKey])) {
            $input = $recipe->scoreInput(
                $profile,
                $this->weekday($date) === 0,
                [],
                $this->daysLeft($date),
                $blockVegetarian
            );
            $reference = $this->referenceScoreCache[$recipe] ?? null;
            if ($reference === null || $reference['block'] !== $blockVegetarian) {
                $scores[$baseKey] = $this->scorer->score($input);
                $this->referenceScoreCache[$recipe] = [
                    'score' => $scores[$baseKey], 'date' => $date, 'profile' => $profile, 'block' => $blockVegetarian,
                ];
            } else {
                $scores[$baseKey] = $reference['score'];
                $newDayFit = $this->scorer->dayFit($input);
                $newVariety = $this->scorer->varietyPenalty($input);
                $scores[$baseKey]['total'] += $this->config->decimal('weight.dayfit')
                    * ($newDayFit - $reference['score']['dayfit'])
                    - $this->config->decimal('weight.variety')
                    * ($newVariety - $reference['score']['variety_pen']);
                $scores[$baseKey]['dayfit'] = $newDayFit;
                $scores[$baseKey]['variety_pen'] = $newVariety;
            }
            $this->baseScoreCache[$recipe] = $scores;
        }
        $score = $scores[$baseKey];
        if ($recent !== []) {
            $variety = $this->scorer->varietyPenalty($recipe->scoreInput(
                $profile,
                $this->weekday($date) === 0,
                $recent,
                $this->daysLeft($date),
                $blockVegetarian
            ));
            $score['total'] -= $this->config->decimal('weight.variety') * ($variety - $score['variety_pen']);
            $score['variety_pen'] = $variety;
        }
        return ['recipe_id' => $recipe->id, 'kind' => $kind, 'score' => $score['total'],
            'explanation' => $withExplanation
                ? $this->explainer->explain($this->withSimilarRecipe($recipe), $profile, $score, $fromDate) : []];
    }

    private function withSimilarRecipe(PlanCandidate $recipe): PlanCandidate
    {
        if ($recipe->rating !== null || $recipe->siblingMeanRating !== null
            || $recipe->similarRatedRecipeId !== null) {
            return $recipe;
        }
        if (isset($this->explainedCandidates[$recipe->id])) {
            return $this->explainedCandidates[$recipe->id];
        }
        $best = null;
        $bestScore = 0.0;
        foreach ($this->likedRecipes as $liked) {
            $similarity = $this->similarity($recipe, $liked);
            if ($similarity > $bestScore || ($similarity === $bestScore && $best !== null && $liked->id < $best->id)) {
                $best = $liked;
                $bestScore = $similarity;
            }
        }
        return $this->explainedCandidates[$recipe->id] = $best === null
            ? $recipe : $recipe->withSimilarity($best->id, $best->rating);
    }

    private function similarity(PlanCandidate $left, PlanCandidate $right): float
    {
        $score = 0.0;
        foreach (['mainProtein' => 'protein', 'cuisine' => 'cuisine',
            'carbBase' => 'carb', 'mealType' => 'meal_type'] as $property => $type) {
            if ($left->$property !== null && $left->$property === $right->$property) {
                $score += $this->config->decimal('content.beta.' . $type);
            }
        }
        $leftIngredients = array_unique($left->mainIngredientIds);
        $rightIngredients = array_unique($right->mainIngredientIds);
        if ($leftIngredients !== [] && $rightIngredients !== []) {
            $shared = count(array_intersect($leftIngredients, $rightIngredients));
            $total = count(array_unique(array_merge($leftIngredients, $rightIngredients)));
            $score += $this->config->decimal('content.beta.ingredients') * $shared / $total;
        }
        return $score;
    }

    /** @return array{protein: ?string, cuisine: ?string, carb: ?string, ingredients: list<int>, adjacent: bool, chosen: bool, in_plan_week: bool, vegetarian: bool} */
    private function recent(PlanCandidate $recipe, string $date, string $otherDate, bool $chosen = true): array
    {
        $gap = abs((new \DateTimeImmutable($date))->diff(new \DateTimeImmutable($otherDate))->days);
        $weekStart = static fn (string $value): string => (new \DateTimeImmutable($value))
            ->modify('-' . (new \DateTimeImmutable($value))->format('w') . ' days')->format('Y-m-d');
        return ['protein' => $recipe->mainProtein, 'cuisine' => $recipe->cuisine, 'carb' => $recipe->carbBase,
            'ingredients' => $recipe->mainIngredientIds, 'adjacent' => $gap === 1, 'chosen' => $chosen,
            'in_plan_week' => $weekStart($date) === $weekStart($otherDate),
            'vegetarian' => $recipe->vegetarian === true];
    }

    private function daysLeft(string $date): int
    {
        return 6 - $this->weekday($date);
    }

    private function weekday(string $date): int
    {
        return $this->weekdayCache[$date] ??= (int) (new \DateTimeImmutable($date))->format('w');
    }

    /** @param list<array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}> $cards
     *  @return array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}
     */
    private function sample(array $cards, string $seed, string $date): array
    {
        $top = array_slice($cards, 0, $this->config->integer('builder.top_k'));
        $temperature = $this->config->decimal('builder.temperature');
        if ($temperature <= 0) {
            throw new InvalidArgumentException('Softmax temperature must be positive.');
        }
        $weightSum = 0.0;
        $weights = [];
        foreach ($top as $card) {
            $weights[] = exp(($card['score'] - $top[0]['score']) / $temperature);
            $weightSum += $weights[array_key_last($weights)];
        }
        $hash = hash('sha256', $seed . '|' . $date . '|pick');
        $draw = hexdec(substr($hash, 0, 13)) / 4503599627370496 * $weightSum;
        foreach ($weights as $index => $weight) {
            $draw -= $weight;
            if ($draw < 0) {
                return $top[$index];
            }
        }
        return $top[count($top) - 1];
    }

    /** @param array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>} $a
     *  @param array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>} $b
     */
    private static function compareCards(array $a, array $b): int
    {
        return ($b['score'] <=> $a['score']) ?: ($a['recipe_id'] <=> $b['recipe_id']);
    }

    /** @param array<string, array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}> $slots
     *  @param array<string, int> $fixed
     *  @param array<string, string> $profiles
     *  @param array<int, PlanCandidate> $byId
     *  @param list<array{date: string, recipe_id: int, kind: string}> $earlierWeek
     */
    private function swap(array &$slots, array $fixed, array $profiles, array $byId, array $earlierWeek, bool $blockVegetarian): void
    {
        $open = array_values(array_diff(array_keys($slots), array_keys($fixed)));
        for ($pass = 0; $pass < $this->config->integer('builder.swap_passes'); $pass++) {
            $improved = false;
            foreach ($open as $leftIndex => $left) {
                foreach (array_slice($open, $leftIndex + 1) as $right) {
                    $leftSlot = $slots[$left];
                    $rightSlot = $slots[$right];
                    $baseline = $this->planTotal($slots, $profiles, $byId, $earlierWeek, $blockVegetarian);
                    $slots[$left] = $rightSlot;
                    $slots[$right] = $leftSlot;
                    if ($this->planTotal($slots, $profiles, $byId, $earlierWeek, $blockVegetarian) > $baseline + 0.000000001) {
                        $improved = true;
                    } else {
                        $slots[$left] = $leftSlot;
                        $slots[$right] = $rightSlot;
                    }
                }
            }
            if (!$improved) {
                break;
            }
        }
    }

    /** @param array<string, array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}> $slots
     *  @param array<string, string> $profiles
     *  @param array<int, PlanCandidate> $byId
     *  @param list<array{date: string, recipe_id: int, kind: string}> $earlierWeek
     */
    private function planTotal(array $slots, array $profiles, array $byId, array $earlierWeek, bool $blockVegetarian): float
    {
        $total = 0.0;
        foreach ($slots as $date => $slot) {
            $card = $this->card(
                $byId[$slot['recipe_id']],
                $slot['kind'],
                $date,
                $profiles[$date],
                $slots,
                $earlierWeek,
                $byId,
                $blockVegetarian,
                null,
                $date
            );
            $total += $card['score'];
        }
        return $total;
    }

    /** @param array<string, list<array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}>> $pools
     *  @param array<int, PlanCandidate> $byId
     *  @param array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}|null $first
     *  @return list<array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}>
     */
    private function interleave(array $pools, float $share, array $byId, ?array $first): array
    {
        $result = [];
        $used = [];
        $favCount = 0;
        if ($first !== null) {
            $result[] = $first;
            $used[$byId[$first['recipe_id']]->groupId] = true;
            $favCount = $first['kind'] === 'favourite' ? 1 : 0;
        }
        $offset = ['favourite' => 0, 'discovery' => 0];
        while (count($result) < $this->config->integer('deck.size')) {
            $kind = $favCount < (int) round((count($result) + 1) * $share) ? 'favourite' : 'discovery';
            $next = $this->nextCard($pools[$kind], $offset[$kind], $used, $byId);
            if ($next === null) {
                $other = $kind === 'favourite' ? 'discovery' : 'favourite';
                $next = $this->nextCard($pools[$other], $offset[$other], $used, $byId);
                $kind = $other;
            }
            if ($next === null) {
                break;
            }
            $result[] = $next;
            $used[$byId[$next['recipe_id']]->groupId] = true;
            $favCount += $kind === 'favourite' ? 1 : 0;
        }
        return $result;
    }

    /** @param list<array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}> $cards
     *  @param array<int, bool> $used
     *  @param array<int, PlanCandidate> $byId
     *  @return array{recipe_id: int, kind: string, score: float, explanation: array<string, mixed>}|null
     */
    private function nextCard(array $cards, int &$offset, array $used, array $byId): ?array
    {
        while (isset($cards[$offset])) {
            $card = $cards[$offset++];
            if (!isset($used[$byId[$card['recipe_id']]->groupId])) {
                return $card;
            }
        }
        return null;
    }
}
