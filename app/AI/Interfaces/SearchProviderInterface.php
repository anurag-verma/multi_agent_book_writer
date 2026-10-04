<?php

namespace App\AI\Interfaces;

interface SearchProviderInterface
{
    /**
     * Run a web search and return normalised results.
     *
     * Each result carries:
     *   'title'       string  page title
     *   'url'         string  absolute URL
     *   'snippet'     string  short extract around the match
     *   'raw_content' string  page text retrieved by the provider (may be '')
     *   'score'       string  provider relevance score (may be '')
     *
     * Supported $options keys, all optional:
     *   'include_domains' array<int, string> restrict to these domains
     *   'exclude_domains' array<int, string> never return these domains
     *   'search_depth'   string              'basic' or 'advanced'
     *
     * @param  array<string, mixed>  $options
     * @return array<int, array<string, string>>
     */
    public function search(string $query, int $limit = 8, array $options = []): array;

    /**
     * Fetch a page and return readable text. Returns '' when unreachable.
     */
    public function fetch(string $url): string;

    /**
     * Cheap reachability probe used by the fact checker. Returns [bool $ok, int $status, string $finalUrl].
     *
     * @return array{0: bool, 1: int, 2: string}
     */
    public function probe(string $url): array;

    /**
     * Name of the configured provider, e.g. "duckduckgo".
     */
    public function provider(): string;
}