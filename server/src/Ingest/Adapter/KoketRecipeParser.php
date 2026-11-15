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
    public function parse(string $html): ParsedRecipe
    {
        $document = new DOMDocument();
        if (!@$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('Cannot parse recipe HTML.');
        }
        $xpath = new DOMXPath($document);
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
                return $this->fromJsonLd($recipe);
            }
        }
        return $this->fromHtml($xpath);
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
    private function fromJsonLd(array $recipe): ParsedRecipe
    {
        $title = $this->text($recipe['name'] ?? null);
        $ingredients = $this->strings($recipe['recipeIngredient'] ?? null);
        $steps = $this->instructions($recipe['recipeInstructions'] ?? null);
        if ($title === null || $ingredients === [] || $steps === []) {
            throw new RuntimeException('Recipe JSON-LD lacks required fields.');
        }
        $time = $this->minutes($recipe['totalTime'] ?? null)
            ?? $this->minutes($recipe['cookTime'] ?? null);
        $yield = $recipe['recipeYield'] ?? null;
        if (is_array($yield)) {
            $yield = $yield[0] ?? null;
        }
        return new ParsedRecipe(
            $title,
            $ingredients,
            $steps,
            $this->image($recipe['image'] ?? null),
            $time,
            $this->servings($yield),
            $this->text($recipe['recipeCuisine'] ?? null),
            $this->text($recipe['recipeCategory'] ?? null),
            'jsonld',
        );
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
                $ingredients[] = $text;
            }
        }
        $steps = [];
        foreach ($xpath->query('//*[@itemprop="recipeInstructions"]//*[self::li or self::p]') ?: [] as $node) {
            if (!$node instanceof DOMNode) {
                continue;
            }
            $text = trim($node->textContent);
            if ($text !== '') {
                $steps[] = $text;
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
            $image !== '' ? $image : null,
            $this->minutes($time),
            $this->servings($yield),
            null,
            null,
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
    private function strings(mixed $value): array
    {
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

    /** @return list<string> */
    private function instructions(mixed $value): array
    {
        if (is_string($value)) {
            $text = $this->text($value);
            return $text !== null ? [$text] : [];
        }
        if (!is_array($value)) {
            return [];
        }
        if (isset($value['text'])) {
            return $this->instructions($value['text']);
        }
        if (isset($value['itemListElement'])) {
            return $this->instructions($value['itemListElement']);
        }
        $result = [];
        foreach ($value as $item) {
            array_push($result, ...$this->instructions($item));
        }
        return $result;
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
