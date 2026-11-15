<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Ingest\Adapter\KoketDiscovery;
use Matsvep\Ingest\Adapter\KoketRecipeParser;
use PHPUnit\Framework\TestCase;

final class KoketParserTest extends TestCase
{
    public function testJsonLdGraphIsPreferred(): void
    {
        $recipe = (new KoketRecipeParser())->parse($this->fixture('recipe-jsonld.html'));
        self::assertSame('Oven curry', $recipe->title);
        self::assertSame(['2 onions', '1 tbsp oil'], $recipe->ingredients);
        self::assertSame(['Chop the onions.', 'Bake until tender.'], $recipe->steps);
        self::assertSame(75, $recipe->totalTimeMinutes);
        self::assertSame(4.0, $recipe->servings);
        self::assertSame('jsonld', $recipe->format);
    }

    public function testHtmlFallbackUsesSavedFixture(): void
    {
        $recipe = (new KoketRecipeParser())->parse($this->fixture('recipe-html.html'));
        self::assertSame('Bean stew', $recipe->title);
        self::assertSame(['1 cup beans', '2 tomatoes'], $recipe->ingredients);
        self::assertSame(40, $recipe->totalTimeMinutes);
        self::assertSame('html', $recipe->format);
    }

    public function testSitemapAndCategoryDiscoveryRejectExternalUrls(): void
    {
        $discovery = new KoketDiscovery();
        $sitemap = $discovery->sitemap($this->fixture('sitemap.xml'), 'https://www.koket.se/sitemap.xml');
        self::assertSame(['https://www.koket.se/recept/oven-curry'], array_keys($sitemap['recipes']));
        $category = $discovery->category($this->fixture('category.html'), 'https://www.koket.se/recept');
        self::assertSame(
            ['https://www.koket.se/recept/oven-curry', 'https://www.koket.se/recept/bean-stew'],
            $category['recipes'],
        );
        self::assertSame(['https://www.koket.se/recept?page=2'], $category['pages']);
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__ . '/fixtures/koket/' . $name);
        self::assertNotFalse($content);
        return $content;
    }
}
