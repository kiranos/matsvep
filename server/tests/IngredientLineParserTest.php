<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Ingest\Adapter\KoketRecipeParser;
use Matsvep\Ingest\Ingredient\IngredientLineParser;
use PHPUnit\Framework\TestCase;

final class IngredientLineParserTest extends TestCase
{
    private function parser(): IngredientLineParser
    {
        return new IngredientLineParser(__DIR__ . '/../seeds');
    }

    private function value(string $key, string $field = 'raw'): string
    {
        $data = json_decode((string) file_get_contents(__DIR__ . '/fixtures/ingredient_lines.json'), true);
        self::assertIsArray($data);
        $entry = $data[$key] ?? null;
        $value = $field === '' ? $entry : (is_array($entry) ? ($entry[$field] ?? null) : null);
        self::assertIsString($value);
        return $value;
    }

    public function testQuantitiesRangesAndNotes(): void
    {
        $line = $this->parser()->parse($this->value('range_notes'));
        self::assertSame(2.0, $line->quantity);
        self::assertSame(3.0, $line->quantityMax);
        self::assertSame('piece', $line->unit);
        self::assertSame($this->value('range_notes', 'ingredient'), $line->ingredientText);
        self::assertSame($this->value('range_notes', 'note'), $line->note);
        $reversed = $this->parser()->parse($this->value('reversed_range'));
        self::assertSame(200.0, $reversed->quantity);
        self::assertSame(250.0, $reversed->quantityMax);
        self::assertSame(0.5, $this->parser()->parse($this->value('half_unicode'))->quantity);
        self::assertSame(0.5, $this->parser()->parse($this->value('half_ascii'))->quantity);
        self::assertSame(1.5, $this->parser()->parse($this->value('mixed_fraction'))->quantity);
        self::assertSame('clove', $this->parser()->parse($this->value('cloves'))->unit);
        self::assertSame('piece', $this->parser()->parse($this->value('eggs'))->unit);
    }

    public function testConjunctionsAndDescriptions(): void
    {
        $parser = $this->parser();
        self::assertSame(
            [$this->value('split_pair', 'first'), $this->value('split_pair', 'second')],
            $parser->parse($this->value('split_pair'))->parts
        );
        $oil = $parser->parse($this->value('split_with_note'));
        self::assertSame(
            [$this->value('split_with_note', 'first'), $this->value('split_with_note', 'second')],
            $oil->parts
        );
        self::assertSame($this->value('split_with_note', 'note'), $oil->note);
        self::assertTrue($parser->parse($this->value('unsafe_quantity'))->unsafeSplit);
        self::assertTrue($parser->parse($this->value('unsafe_alternative'))->unsafeSplit);
        $citrus = $parser->parse($this->value('citrus_note'));
        self::assertSame([$this->value('citrus_note', 'ingredient')], $citrus->parts);
        self::assertSame($this->value('citrus_note', 'note'), $citrus->note);
        self::assertSame(
            [$this->value('descriptor', 'ingredient'), [$this->value('descriptor', 'note')]],
            $parser->stripDescriptors($this->value('descriptor'))
        );
    }

    public function testSavedKoketFixtures(): void
    {
        $recipeParser = new KoketRecipeParser();
        $lineParser = $this->parser();
        $soup = $recipeParser->parse((string) file_get_contents(__DIR__ . '/fixtures/koket/recipe-soup.html'));
        $first = $lineParser->parse($soup->ingredients[0]['text']);
        self::assertSame(800.0, $first->quantity);
        self::assertSame(1000.0, $first->quantityMax);
        self::assertSame('gram', $first->unit);
        self::assertSame($this->value('soup_first_ingredient', ''), $first->ingredientText);
        $second = $recipeParser->parse((string) file_get_contents(__DIR__ . '/fixtures/koket/recipe-2.html'));
        self::assertSame('teaspoon', $lineParser->parse($second->ingredients[1]['text'])->unit);
    }
}
