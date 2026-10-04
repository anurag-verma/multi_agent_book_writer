<?php

namespace App\AI\DTO;

use Illuminate\Contracts\Support\Arrayable;

/**
 * Outcome of verifying one citation against one claim.
 *
 * @implements Arrayable<string, mixed>
 */
final readonly class FactCheckItem implements Arrayable
{
    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNCERTAIN = 'UNCERTAIN';

    public function __construct(
        public int $citation,
        public string $claim,
        public string $sourceUrl,
        public string $sourceOrganization,
        public string $status,
        public string $reason,
        public string $checkType = 'support',
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $status = strtoupper(trim((string) ($data['status'] ?? self::UNCERTAIN)));

        if (! in_array($status, [self::PASS, self::FAIL, self::UNCERTAIN], true)) {
            $status = self::UNCERTAIN;
        }

        return new self(
            citation: (int) ($data['citation'] ?? $data['citation_id'] ?? 0),
            claim: trim((string) ($data['claim'] ?? '')),
            sourceUrl: (string) ($data['source_url'] ?? ''),
            sourceOrganization: (string) ($data['source_organization'] ?? ''),
            status: $status,
            reason: trim((string) ($data['reason'] ?? '')),
            checkType: (string) ($data['check_type'] ?? 'support'),
        );
    }

    public function isPass(): bool
    {
        return $this->status === self::PASS;
    }

    public function isFail(): bool
    {
        return $this->status === self::FAIL;
    }

    public function isUncertain(): bool
    {
        return $this->status === self::UNCERTAIN;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'citation' => $this->citation,
            'claim' => $this->claim,
            'source_url' => $this->sourceUrl,
            'source_organization' => $this->sourceOrganization,
            'status' => $this->status,
            'reason' => $this->reason,
            'check_type' => $this->checkType,
        ];
    }

    /**
     * Compact instruction handed back to the Writer on revision.
     */
    public function toRevisionInstruction(): string
    {
        return sprintf(
            'Citation [%d] was marked %s by fact checking. Claim: "%s". Reason: %s',
            $this->citation,
            $this->status,
            $this->claim,
            $this->reason
        );
    }
}