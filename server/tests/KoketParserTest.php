<?php

declare(strict_types=1);

namespace Matsvep\Tests;

use Matsvep\Ingest\Adapter\KoketDiscovery;
use Matsvep\Ingest\Adapter\KoketRecipeParser;
use PHPUnit\Framework\TestCase;

final class KoketParserTest extends TestCase
{
    public function testRealRecipePagesPreserveTagsAndSectionNames(): void
    {
        $parser = new KoketRecipeParser();
        $soup = $this->fixture('recipe-soup.html');
        self::assertSame(
            $this->canonical('recipe-soup.html'),
            $parser->canonicalUrl($soup, 'https://www.koket.se/recipes/alias')
        );
        $withoutCanonical = preg_replace('~<link[^>]*rel="canonical"[^>]*>~i', '', $soup);
        self::assertIsString($withoutCanonical);
        self::assertSame(
            'https://www.koket.se/recipes/alias',
            $parser->canonicalUrl($withoutCanonical, 'https://www.koket.se/recipes/alias')
        );
        self::assertSame(
            $this->canonical('recipe-soup.html'),
            $parser->canonicalUrl(
                '<script type="application/ld+json">{"@type":"Recipe","url":"' .
                $this->canonical('recipe-soup.html') . '"}</script>',
                'https://www.koket.se/recipes/alias'
            )
        );
        self::assertTrue($parser->isRecipe($soup));
        $withoutJsonLd = preg_replace('~<script[^>]*type="application/ld\+json"[^>]*>.*?</script>~is', '', $soup);
        self::assertIsString($withoutJsonLd);
        self::assertTrue($parser->isRecipe($withoutJsonLd));
        $first = $parser->parse($soup);
        self::assertSame('jsonld', $first->format);
        self::assertCount(21, $first->ingredients);
        self::assertCount(19, $first->steps);
        self::assertCount(1, $first->tags);
        self::assertNotNull($first->ingredients[0]['group_name']);
        self::assertSame($first->ingredients[0]['group_name'], $first->steps[0]['group_name']);
        self::assertNotSame($first->ingredients[0]['group_name'], $first->ingredients[20]['group_name']);

        $second = $parser->parse($this->fixture('recipe-2.html'));
        self::assertCount(10, $second->ingredients);
        self::assertCount(14, $second->steps);
        self::assertCount(2, $second->tags);
        self::assertNotNull($second->ingredients[0]['group_name']);
        self::assertNotSame($second->steps[0]['group_name'], $second->steps[13]['group_name']);
    }

    public function testIncompleteJsonLdRecipeRemainsVisibleForQualityReview(): void
    {
        $parser = new KoketRecipeParser();
        $html = $this->fixture('recipe-without-steps.html');
        self::assertTrue($parser->isRecipe($html));
        $recipe = $parser->parse($html);
        self::assertSame('jsonld', $recipe->format);
        self::assertCount(3, $recipe->ingredients);
        self::assertSame([], $recipe->steps);
        self::assertNull($recipe->totalTimeMinutes);
    }

    public function testRealChefAndCategoryPagesAreNotRecipes(): void
    {
        $parser = new KoketRecipeParser();
        self::assertFalse($parser->isRecipe($this->fixture('chef-page.html')));
        self::assertFalse($parser->isRecipe($this->fixture('category.html')));
    }

    public function testRealSitemapAndCategoryDiscoverRootLevelRecipesButExcludeSections(): void
    {
        $discovery = new KoketDiscovery();
        $xml = $this->fixture('sitemap.xml');
        $document = new \DOMDocument();
        self::assertTrue($document->loadXML($xml, LIBXML_NONET));
        $xpath = new \DOMXPath($document);
        self::assertSame(40.0, $xpath->evaluate('count(//*[local-name()="url"])'));
        self::assertSame(38.0, $xpath->evaluate('count(//*[local-name()="url"]/*[local-name()="lastmod"])'));

        $sitemap = $discovery->sitemap($xml, 'https://www.koket.se/sitemap.xml');
        self::assertCount(33, $sitemap['recipes']);
        self::assertArrayHasKey($this->canonical('recipe-soup.html'), $sitemap['recipes']);
        self::assertArrayHasKey($this->canonical('recipe-2.html'), $sitemap['recipes']);
        self::assertArrayHasKey($this->canonical('chef-page.html'), $sitemap['recipes']);
        self::assertNull($discovery->candidateUrl($this->canonical('category.html'), $this->canonical('category.html')));
        self::assertSame(
            $this->canonical('recipe-soup.html'),
            $discovery->canonicalUrl(
                str_replace('https://', 'http://', $this->canonical('recipe-soup.html')),
                $this->canonical('category.html'),
            ),
        );
        foreach (array_slice(array_keys($sitemap['recipes']), 0, 10) as $url) {
            self::assertStringStartsWith('https://www.koket.se/', $url);
        }

        $category = $discovery->category($this->fixture('category.html'), $this->canonical('category.html'));
        self::assertCount(28, $category['recipes']);
        foreach ($category['recipes'] as $url) {
            self::assertStringStartsWith('https://www.koket.se/', $url);
        }
        self::assertSame([], $category['pages']);
    }

    private function canonical(string $name): string
    {
        $document = new \DOMDocument();
        @$document->loadHTML($this->fixture($name), LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($document);
        $url = $xpath->evaluate('string((//link[@rel="canonical"]/@href)[1])');
        self::assertIsString($url);
        return $url;
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(__DIR__ . '/fixtures/koket/' . $name);
        self::assertNotFalse($content);
        return $content;
    }
}
