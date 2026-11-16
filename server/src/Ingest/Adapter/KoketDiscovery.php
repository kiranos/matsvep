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
    /** @var list<array{path: string, match: string}> */
    private array $excludedPaths = [];

    public function __construct(?string $exclusionsFile = null)
    {
        $path = $exclusionsFile ?? dirname(__DIR__, 3) . '/seeds/koket_non_recipe_paths.csv';
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false || array_shift($lines) !== 'path,match') {
            throw new RuntimeException('Cannot read non-recipe path data.');
        }
        foreach ($lines as $line) {
            $fields = str_getcsv($line);
            if (count($fields) !== 2 || !is_string($fields[0]) || !is_string($fields[1])
                || !str_starts_with($fields[0], '/') || !in_array($fields[1], ['exact', 'prefix'], true)) {
                throw new RuntimeException('Invalid non-recipe path data.');
            }
            $this->excludedPaths[] = ['path' => $fields[0], 'match' => $fields[1]];
        }
    }

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
            $url = $this->candidateUrl($location, $pageUrl);
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
            $url = $this->canonicalUrl($location, $pageUrl);
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
        foreach ($xpath->query('//*[@data-testid="recipe-card"]//*[@data-testid="recipe-card-title"]//a[@href]') ?: [] as $anchor) {
            if (!$anchor instanceof DOMElement) {
                continue;
            }
            $href = $anchor->getAttribute('href');
            $recipe = $this->candidateUrl($href, $pageUrl);
            if ($recipe !== null) {
                $recipes[$recipe] = true;
            }
        }
        foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@rel), " "), " next ")] | //link[contains(concat(" ", normalize-space(@rel), " "), " next ")]') ?: [] as $anchor) {
            if ($anchor instanceof DOMElement) {
                $url = $this->canonicalUrl($anchor->getAttribute('href'), $pageUrl);
                if ($url !== null) {
                    $pages[$url] = true;
                }
            }
        }
        return ['recipes' => array_keys($recipes), 'pages' => array_keys($pages)];
    }

    public function candidateUrl(string $url, string $pageUrl): ?string
    {
        $absolute = $this->canonicalUrl($url, $pageUrl);
        if ($absolute === null) {
            return null;
        }
        $path = parse_url($absolute, PHP_URL_PATH);
        if (!is_string($path) || in_array($path, ['/', '/robots.txt', '/sitemap.xml'], true)
            || str_ends_with($path, '.xml') || $this->isExcluded($path)) {
            return null;
        }
        return strtok($absolute, '?') ?: null;
    }

    private function isExcluded(string $path): bool
    {
        foreach ($this->excludedPaths as $entry) {
            if (($entry['match'] === 'exact' && $path === $entry['path'])
                || ($entry['match'] === 'prefix' && str_starts_with($path, $entry['path']))) {
                return true;
            }
        }
        return false;
    }

    public function canonicalUrl(string $url, string $pageUrl): ?string
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
        } elseif (!str_starts_with($url, 'https://') && !str_starts_with($url, 'http://')) {
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
        if (is_string($query) && $query !== '') {
            $canonical .= '?' . $query;
        }
        return $canonical;
    }
}
