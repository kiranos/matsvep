<?php

declare(strict_types=1);

namespace Matsvep\Ingest\Adapter;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;

final class KoketDiscovery
{
    /** @return array{recipes: array<string, DateTimeImmutable|null>, sitemaps: list<string>} */
    public function sitemap(string $xml, string $pageUrl): array
    {
        $document = new DOMDocument();
        if (!@$document->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('Invalid sitemap XML.');
        }
        $xpath = new DOMXPath($document);
        $recipes = [];
        $sitemaps = [];
        foreach ($xpath->query('/*[local-name()="urlset"]/*[local-name()="url"]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $locationNode = $node->getElementsByTagNameNS('*', 'loc')->item(0);
            $location = $locationNode !== null ? trim($locationNode->textContent) : '';
            $url = $this->recipeUrl($location, $pageUrl);
            if ($url === null) {
                continue;
            }
            $modifiedNode = $node->getElementsByTagNameNS('*', 'lastmod')->item(0);
            $modified = $modifiedNode !== null ? trim($modifiedNode->textContent) : '';
            try {
                $recipes[$url] = $modified !== '' ? new DateTimeImmutable($modified) : null;
            } catch (\Exception) {
                $recipes[$url] = null;
            }
        }
        foreach ($xpath->query('/*[local-name()="sitemapindex"]/*[local-name()="sitemap"]') ?: [] as $node) {
            if (!$node instanceof DOMElement) {
                continue;
            }
            $locationNode = $node->getElementsByTagNameNS('*', 'loc')->item(0);
            $location = $locationNode !== null ? trim($locationNode->textContent) : '';
            $url = $this->absoluteUrl($location, $pageUrl);
            if ($url !== null) {
                $sitemaps[] = $url;
            }
        }
        return ['recipes' => $recipes, 'sitemaps' => $sitemaps];
    }

    /** @return array{recipes: list<string>, pages: list<string>} */
    public function category(string $html, string $pageUrl): array
    {
        $document = new DOMDocument();
        if (!@$document->loadHTML($html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new RuntimeException('Invalid category HTML.');
        }
        $xpath = new DOMXPath($document);
        $recipes = [];
        $pages = [];
        foreach ($xpath->query('//a[@href]') ?: [] as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }
            $href = $anchor->getAttribute('href');
            $url = $this->absoluteUrl($href, $pageUrl);
            if ($url === null) {
                continue;
            }
            $rel = strtolower($anchor->getAttribute('rel'));
            if (preg_match('/(?:^|\s)next(?:\s|$)/', $rel) === 1) {
                $pages[$url] = true;
            } else {
                $recipe = $this->recipeUrl($url, $pageUrl);
                if ($recipe !== null) {
                    $recipes[$recipe] = true;
                }
            }
        }
        return ['recipes' => array_keys($recipes), 'pages' => array_keys($pages)];
    }

    public function recipeUrl(string $url, string $pageUrl): ?string
    {
        $absolute = $this->absoluteUrl($url, $pageUrl);
        if ($absolute === null) {
            return null;
        }
        $path = parse_url($absolute, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/recept/')) {
            return null;
        }
        return $absolute;
    }

    private function absoluteUrl(string $url, string $pageUrl): ?string
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '#')) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = 'https:' . $url;
        } elseif (str_starts_with($url, '?')) {
            $pagePath = parse_url($pageUrl, PHP_URL_PATH);
            $url = 'https://www.koket.se' . (is_string($pagePath) ? $pagePath : '/') . $url;
        } elseif (str_starts_with($url, '/')) {
            $url = 'https://www.koket.se' . $url;
        } elseif (!str_starts_with($url, 'https://')) {
            if (str_contains($url, ':')) {
                return null;
            }
            $pagePath = parse_url($pageUrl, PHP_URL_PATH);
            $directory = is_string($pagePath) ? rtrim(dirname($pagePath), '/') : '';
            $url = 'https://www.koket.se' . $directory . '/' . $url;
        }
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || !in_array(strtolower($host), ['koket.se', 'www.koket.se'], true)) {
            return null;
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }
        $query = parse_url($url, PHP_URL_QUERY);
        $canonical = 'https://www.koket.se' . (rtrim($path, '/') ?: '/');
        if (is_string($query) && $query !== '' && !str_starts_with($path, '/recept/')) {
            $canonical .= '?' . $query;
        }
        return $canonical;
    }
}
