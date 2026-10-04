<?php

namespace Tests\Support;

use App\AI\DTO\BookBrief;
use App\AI\DTO\ChapterDraft;
use App\AI\DTO\ChapterOutline;
use App\AI\DTO\ResearchPackage;
use App\AI\DTO\SourceItem;
use App\AI\Helpers\TextUtils;

/**
 * Fixtures shared by the test suite.
 *
 * Building DTOs here keeps individual tests focused on the single rule each one
 * is checking, and gives one place to change if a constructor signature moves.
 */
trait BuildsFixtures
{
    protected function source(int $id = 1, string $organization = 'National Payments Corporation of India'): SourceItem
    {
        return new SourceItem(
            $id,
            $organization,
            'UPI Product Statistics',
            'https://www.npci.org.in/product/upi/product-statistics',
            ['UPI processed 16.58 billion transactions in a month.'],
            str_repeat('UPI transaction volume and growth data. ', 200),
            true,
            'Fetched and verified during research',
            200,
        );
    }

    /** @param array<int, SourceItem> $sources */
    protected function package(array $sources = [], int $chapter = 1): ResearchPackage
    {
        return new ResearchPackage($chapter, 'Getting started', $sources ?: [$this->source(1), $this->source(2)]);
    }

    /** A body that satisfies every rule: cited prose, one Takeaway, no bullets. */
    protected function validBody(int $targetWords = 0): string
    {
        $body = "UPI is India's instant payment system [1]. It lets a customer send money "
            ."to a shop in seconds from a phone [1]. A small business can accept a UPI payment "
            ."without signing up for a card terminal [2]. Many customers already have a UPI app "
            ."installed on their phone [1].\n\n"
            ."The cost is lower than a card machine because there is no monthly rental [2]. "
            ."Money usually reaches the merchant account the next working day [2].\n\n"
            .'Takeaway: UPI is a cheap way for a small shop to accept digital payments.';

        if ($targetWords > 0) {
            $body = $this->pad($body, $targetWords);
        }

        return $body;
    }

    /**
     * Insert filler prose before the Takeaway line, since anything after the
     * Takeaway would break the placement rule we usually want to keep valid.
     */
    protected function pad(string $body, int $targetWords, string $filler = 'UPI payments settle quickly for everyone involved in daily trade. [1] '): string
    {
        $count = static fn (string $t): int => TextUtils::countWords($t);

        if ($count($body) >= $targetWords) {
            return $body;
        }

        $short = ($targetWords + 10) - $count($body);
        $padding = str_repeat($filler, (int) ceil(max($short, 0) / max($count($filler), 1)));

        if (preg_match('/^\s*Takeaway:.*$/mi', $body) === 1) {
            $parts = preg_split('/(?=^\s*Takeaway:)/mi', $body, 2);

            return rtrim($parts[0])."\n\n".trim($padding)."\n".trim($parts[1]);
        }

        return rtrim($body)."\n\n".trim($padding);
    }

    /** @param array<int, SourceItem> $sources */
    protected function draft(string $body, array $sources = []): ChapterDraft
    {
        return new ChapterDraft(1, 'Getting started with UPI', $body, $sources);
    }

    protected function brief(): BookBrief
    {
        return BookBrief::make([
            'title' => 'UPI for Small Business',
            'audience' => 'First-time small-business owners in India',
            'tone' => 'Warm, practical and encouraging',
        ]);
    }

    protected function outline(int $number = 1): ChapterOutline
    {
        return new ChapterOutline(
            $number,
            'Getting started with UPI',
            'Explain what UPI is and why it suits a small shop',
            ['what UPI is', 'how a customer pays'],
            ['What is UPI?', 'How do I accept it?'],
            ['UPI transaction statistics', 'merchant onboarding'],
        );
    }
}