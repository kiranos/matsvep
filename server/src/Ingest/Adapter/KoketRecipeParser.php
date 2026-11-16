<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Adapter;

use DOMDocument;
use DOMNode;
use DOMXPath;
use JsonException;
use RuntimeException;

final class KoketRecipeParser
{
    public function isRecipe(string $html): bool
    {
        $document = $this->document($html);
        $xpath = new DOMXPath($document);
        $pageType = $this->xpathText(
            $xpath,
            'string((//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "page-type" '
                . 'or translate(@property, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz") = "page-type"]/@content)[1])',
        );
        if (strtolower($pageType) === 'recipe') {
            return true;
        }
        return $this->jsonLdRecipe($xpath) !== null;
    }

    public function parse(string $html): ParsedRecipe
    {
        $document = $this->document($html);
        $xpath = new DOMXPath($document);
        $recipe = $this->jsonLdRecipe($xpath);
        return $recipe !== null ? $this->fromJsonLd($recipe, $xpath) : $this->fromHtml($xpath);
    }

    private function document(string $html): DOMDocument
    {
        $document = new DOMDocument();
        if (!@$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('Cannot parse recipe HTML.');
        }
        return $document;
    }

    /** @return array<string, mixed>|null */
    private function jsonLdRecipe(DOMXPath $xpath): ?array
    {
        foreach ($xpath->query('//script[contains(translate(@type, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "application/ld+json")]') ?: [] as $script) {
            if (!$script instanceof DOMNode) {
                continue;
            }
            try {
                $data = json_decode($script->textContent, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                continue;
            }
            $recipe = $this->findRecipe($data);
            if ($recipe !== null) {
                return $recipe;
            }
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function findRecipe(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $types = $value['@type'] ?? null;
        if (is_string($types)) {
            $types = [$types];
        }
        if (is_array($types) && in_array('Recipe', $types, true)) {
            $recipe = [];
            foreach ($value as $key => $part) {
                if (!is_string($key)) {
                    return null;
                }
                $recipe[$key] = $part;
            }
            return $recipe;
        }
        foreach ($value as $child) {
            $recipe = $this->findRecipe($child);
            if ($recipe !== null) {
                return $recipe;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $recipe */
    private function fromJsonLd(array $recipe, DOMXPath $xpath): ParsedRecipe
    {
        $title = $this->text($recipe['name'] ?? null);
        $headings = $this->sectionHeadings($xpath);
        $ingredients = $this->ingredientLines($recipe['recipeIngredient'] ?? null, $headings);
        $steps = $this->instructions($recipe['recipeInstructions'] ?? null, $headings);
        if ($title === null || $ingredients === [] || $steps === []) {
            throw new RuntimeException('Recipe JSON-LD lacks required fields.');
        }
        $time = $this->minutes($recipe['totalTime'] ?? null)
            ?? $this->minutes($recipe['cookTime'] ?? null);
        $yield = $recipe['recipeYield'] ?? null;
        if (is_array($yield)) {
            $yield = $yield[0] ?? null;
        }
        $tags = array_values(array_unique(array_merge(
            $this->textList($recipe['recipeCuisine'] ?? null),
            $this->textList($recipe['recipeCategory'] ?? null),
        )));
        return new ParsedRecipe(
            $title,
            $ingredients,
            $steps,
            $tags,
            $this->image($recipe['image'] ?? null),
            $time,
            $this->servings($yield),
            'jsonld',
        );
    }

    /** @return list<string> */
    private function sectionHeadings(DOMXPath $xpath): array
    {
        $headings = [];
        foreach ($xpath->query('//h3[contains(@class, "sectionTitle")]') ?: [] as $node) {
            if ($node instanceof DOMNode) {
                $text = trim($node->textContent);
                if ($text !== '') {
                    $headings[] = $text;
                }
            }
        }
        return array_values(array_unique($headings));
    }

    private function fromHtml(DOMXPath $xpath): ParsedRecipe
    {
        $title = $this->xpathText($xpath, 'string((//h1)[1])');
        $ingredients = [];
        foreach ($xpath->query('//*[@itemprop="recipeIngredient"]') ?: [] as $node) {
            if (!$node instanceof DOMNode) {
                continue;
            }
            $text = trim($node->textContent);
            if ($text !== '') {
                $ingredients[] = ['text' => $text, 'group_name' => null];
            }
        }
        $steps = [];
        foreach ($xpath->query('//*[@itemprop="recipeInstructions"]//*[self::li or self::p]') ?: [] as $node) {
            if (!$node instanceof DOMNode) {
                continue;
            }
            $text = trim($node->textContent);
            if ($text !== '') {
                $steps[] = ['text' => $text, 'group_name' => null];
            }
        }
        if ($title === '' || $ingredients === [] || $steps === []) {
            throw new RuntimeException('Recipe page has no usable JSON-LD or HTML recipe fields.');
        }
        $image = $this->xpathText($xpath, 'string((//meta[@property="og:image"]/@content)[1])');
        $time = $this->xpathText($xpath, 'string((//*[@itemprop="totalTime"]/@datetime)[1])');
        $yield = $this->xpathText($xpath, 'string((//*[@itemprop="recipeYield"])[1])');
        return new ParsedRecipe(
            $title,
            $ingredients,
            $steps,
            [],
            $image !== '' ? $image : null,
            $this->minutes($time),
            $this->servings($yield),
            'html',
        );
    }

    private function xpathText(DOMXPath $xpath, string $expression): string
    {
        $value = $xpath->evaluate($expression);
        return is_string($value) ? trim($value) : '';
    }

    private function text(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $text = trim(strip_tags($value));
        return $text !== '' ? $text : null;
    }

    /** @return list<string> */
    private function textList(mixed $value): array
    {
        if (is_string($value)) {
            $text = $this->text($value);
            return $text !== null ? [$text] : [];
        }
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $text = $this->text($item);
            if ($text !== null) {
                $result[] = $text;
            }
        }
        return $result;
    }

    /**
     * @param list<string> $headings
     * @return list<array{text: string, group_name: string|null}>
     */
    private function ingredientLines(mixed $value, array $headings): array
    {
        $result = [];
        $group = null;
        $this->appendLines($value, $headings, $group, $result);
        return $result;
    }

    /**
     * @param list<string> $headings
     * @return list<array{text: string, group_name: string|null}>
     */
    private function instructions(mixed $value, array $headings): array
    {
        $result = [];
        $group = null;
        $this->appendLines($value, $headings, $group, $result);
        return $result;
    }

    /**
     * @param list<string> $headings
     * @param list<array{text: string, group_name: string|null}> $result
     */
    private function appendLines(mixed $value, array $headings, ?string &$group, array &$result): void
    {
        if (is_string($value)) {
            $text = $this->text($value);
            if ($text !== null) {
                if (in_array($text, $headings, true)) {
                    $group = $text;
                } else {
                    $result[] = ['text' => $text, 'group_name' => $group];
                }
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        if (isset($value['itemListElement'])) {
            $group = $this->text($value['name'] ?? null) ?? $group;
            $this->appendLines($value['itemListElement'], $headings, $group, $result);
            return;
        }
        if (isset($value['text'])) {
            $this->appendLines($value['text'], $headings, $group, $result);
            return;
        }
        foreach ($value as $item) {
            $this->appendLines($item, $headings, $group, $result);
        }
    }

    private function image(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->text($value);
        }
        if (is_array($value)) {
            if (isset($value['url'])) {
                return $this->image($value['url']);
            }
            if (isset($value['contentUrl'])) {
                return $this->image($value['contentUrl']);
            }
            return $this->image($value[0] ?? null);
        }
        return null;
    }

    private function minutes(mixed $value): ?int
    {
        if (!is_string($value) || !preg_match('/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?)?$/', $value, $parts)) {
            return null;
        }
        $days = (int) ($parts[1] ?? 0);
        $hours = (int) ($parts[2] ?? 0);
        $minutes = (int) ($parts[3] ?? 0);
        $total = $days * 1440 + $hours * 60 + $minutes;
        return $total > 0 ? $total : null;
    }

    private function servings(mixed $value): ?float
    {
        if (!is_string($value) && !is_int($value) && !is_float($value)) {
            return null;
        }
        if (!preg_match('/\d+(?:[.,]\d+)?/', (string) $value, $matches)) {
            return null;
        }
        $servings = (float) str_replace(',', '.', $matches[0]);
        return $servings > 0 ? $servings : null;
    }
}
