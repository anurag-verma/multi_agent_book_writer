<?php

namespace Tests\Fakes;

use App\AI\Interfaces\SearchProviderInterface;

/**
 * Search double. Returns a fixed result set so Researcher tests exercise the
 * selection and verification logic without touching the network.
 */
class FakeSearch implements SearchProviderInterface
{
    /** @var array<int, array<int, array<string, string>>> */
    private array $byQuery = [];

    /** @var array<int, array{query: string, limit: int, options: array}> */
    public array $searches = [];

    /** @var array<int, string> */
    public array $fetched = [];

    /** @var array<int, array{0: bool, 1: int, 2: string}> */
    private array $probes = [];

    /**
     * Returned for any query that has no explicit stub. Without this the
     * Researcher would discover nothing and tests could not reach the stages
     * that matter.
     *
     * @var array<int, array<string, string>>
     */
    private array $fallback = [];

    /** @param array<int, array<string, string>> $results */
    public function alwaysReturns(array $results): self
    {
        $this->fallback = $results;

        return $this;
    }

    /**
     * A single plausible NPCI statistics page with real body text.
     *
     * @return array<int, array<string, string>>
     */
    /**
     * A second, independent source so tests can exercise behaviour that depends
     * on a chapter citing more than one reference.
     *
     * @return array<int, array<string, string>>
     */
    public static function rbiGuidance(): array
    {
        return [[
            'title' => 'Reserve Bank of India: Digital Payments',
            'url' => 'https://www.rbi.org.in/Scripts/BS_PressReleaseDisplay.aspx',
            'snippet' => 'Supervisory guidance for payment system operators.',
            'raw_content' => str_repeat('Payment system operators must follow the issued guidelines. ', 90),
            'score' => '0.90',
        ]];
    }

    /** @return array<int, array<string, string>> */
    public static function npciStatistics(): array
    {
        return [[
            'title' => 'UPI Product Statistics',
            'url' => 'https://www.npci.org.in/product/upi/product-statistics',
            'snippet' => 'Monthly UPI transaction volume and value statistics.',
            'raw_content' => str_repeat('UPI processed 16.58 billion transactions in the month. ', 90),
            'score' => '0.95',
        ]];
    }

    /** @param array<int, string> $urls */
    public function returnsFor(string $query, array $results): self
    {
        $this->byQuery[strtolower($query)] = $results;

        return $this;
    }

    public function probeResult(string $url, bool $ok = true, int $status = 200): self
    {
        $this->probes[$url] = [$ok, $status, $url];

        return $this;
    }

    public function provider(): string
    {
        return 'fake';
    }

    public function search(string $query, int $limit = 8, array $options = []): array
    {
        $this->searches[] = ['query' => $query, 'limit' => $limit, 'options' => $options];

        return $this->byQuery[strtolower($query)] ?? $this->fallback;
    }

    public function fetch(string $url): string
    {
        $this->fetched[] = $url;

        return str_repeat('Retrieved page text about UPI transactions. ', 120);
    }

    public function probe(string $url): array
    {
        return $this->probes[$url] ?? [true, 200, $url];
    }
}