<?php

namespace App\AI\DTO;

use App\AI\Helpers\TextUtils;
use Illuminate\Contracts\Support\Arrayable;

/**
 * A single verified source. This is the unit of trust in the whole system.
 *
 * Only the Researcher creates SourceItems. The Writer may reference a source by
 * its citationId but can never construct one, which is what makes "the Writer
 * cannot invent citations" a structural guarantee rather than a prompt request.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class SourceItem implements Arrayable
{
    /**
     * @param  array<int, string>  $claimsSupported  Factual claims this source can back.
     * @param  string  $retrievedContent  Page text we actually obtained, used by the Fact Checker.
     */
    public function __construct(
        public int $citationId,
        public string $organization,
        public string $title,
        public string $url,
        public array $claimsSupported,
        public string $retrievedContent = '',
        public bool $verified = false,
        public string $verificationNote = '',
        public int $httpStatus = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, int $fallbackId = 0): self
    {
        $claims = $data['claims_supported'] ?? $data['claims'] ?? [];
        $claims = is_array($claims) ? array_values(array_filter(array_map(
            static fn (mixed $c): string => trim((string) $c),
            $claims
        ))) : [];

        $url = TextUtils::canonicalUrl((string) ($data['url'] ?? ''));

        return new self(
            citationId: (int) ($data['id'] ?? $data['citation_id'] ?? $fallbackId),
            organization: trim((string) ($data['organization'] ?? $data['publisher'] ?? '')),
            title: trim((string) ($data['title'] ?? '')),
            url: $url,
            claimsSupported: $claims,
            retrievedContent: (string) ($data['retrieved_content'] ?? ''),
            verified: (bool) ($data['verified'] ?? false),
            verificationNote: (string) ($data['verification_note'] ?? ''),
            httpStatus: (int) ($data['http_status'] ?? 0),
        );
    }

    /**
     * Citation line rendered into the reference list.
     */
    public function referenceLine(): string
    {
        return sprintf('[%d] %s. "%s". %s', $this->citationId, $this->organization, $this->title, $this->url);
    }

    public function domain(): string
    {
        return TextUtils::domain($this->url);
    }

    /**
     * Text the Fact Checker should reason over. Falls back to the claims list
     * when no page content could be retrieved.
     */
    public function evidence(int $maxChars = 6000): string
    {
        if (trim($this->retrievedContent) !== '') {
            return mb_substr($this->retrievedContent, 0, $maxChars);
        }

        return 'No page text could be retrieved for this URL. Claims recorded by the researcher: '
            .implode('; ', $this->claimsSupported);
    }

    public function hasEvidence(): bool
    {
        return trim($this->retrievedContent) !== '';
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->citationId,
            'organization' => $this->organization,
            'title' => $this->title,
            'url' => $this->url,
            'claims_supported' => $this->claimsSupported,
            'verified' => $this->verified,
            'verification_note' => $this->verificationNote,
            'http_status' => $this->httpStatus,
            'retrieved_content_chars' => mb_strlen($this->retrievedContent),
        ];
    }
}