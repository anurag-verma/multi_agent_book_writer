<?php

namespace Tests\Unit;

use App\AI\Helpers\TextUtils;
use Tests\TestCase;

/**
 * TextUtils is the foundation the Final Validator's rules are built on. If the
 * word counter is wrong, every length check is wrong, so it gets tested directly
 * rather than only through the validator.
 */
class TextUtilsTest extends TestCase
{
    public function test_decimals_count_as_one_word(): void
    {
        // Matters because the book is full of figures like "16.4 billion".
        $this->assertSame(1, TextUtils::countWords('16.4'));
        $this->assertSame(2, TextUtils::countWords('16.4 billion'));
    }

    public function test_hyphenated_and_apostrophised_words_count_as_one(): void
    {
        $this->assertSame(1, TextUtils::countWords('small-business'));
        $this->assertSame(1, TextUtils::countWords("merchant's"));
    }

    public function test_citation_markers_are_not_counted_as_words(): void
    {
        $this->assertSame(2, TextUtils::countWords('Volume rose [1].'));
    }

    public function test_markdown_syntax_is_ignored_but_text_is_kept(): void
    {
        $this->assertSame(2, TextUtils::countWords('## Getting started'));
        $this->assertSame(1, TextUtils::countWords('**bold**'));
    }

    public function test_urls_are_not_counted_as_words(): void
    {
        // "See" and "now" are the only words; the URL must not add any.
        $this->assertSame(2, TextUtils::countWords('See https://example.com/a/very/long/path now'));
    }

    public function test_empty_text_is_zero_words(): void
    {
        $this->assertSame(0, TextUtils::countWords(''));
        $this->assertSame(0, TextUtils::countWords("   \n  "));
    }

    public function test_citations_are_extracted_in_order_of_appearance(): void
    {
        $this->assertSame([2, 1, 2], TextUtils::extractCitations('A [2] then [1] then [2].'));
    }

    public function test_unique_citations_are_sorted_ascending(): void
    {
        $this->assertSame([1, 2, 12], TextUtils::uniqueCitations('See [12] and [2] and [1].'));
    }

    public function test_sentence_splitting_does_not_break_on_abbreviations(): void
    {
        $sentences = TextUtils::sentences('UPI is fast. Ask the shop owner for the merchant id. It settles next day.');

        $this->assertCount(3, $sentences);
    }

    public function test_bullet_detection_covers_common_markers(): void
    {
        $this->assertTrue(TextUtils::hasBulletPoints("- a\n- b"));
        $this->assertTrue(TextUtils::hasBulletPoints("1. a\n2. b"));
        $this->assertTrue(TextUtils::hasBulletPoints("* a"));
        $this->assertFalse(TextUtils::hasBulletPoints('Just prose here.'));
    }

    public function test_canonical_url_strips_tracking_and_www(): void
    {
        $this->assertSame(
            'https://example.com/page',
            TextUtils::canonicalUrl('http://www.example.com/page/?utm_source=x')
        );
    }

    public function test_canonical_url_adds_a_scheme_when_missing(): void
    {
        $this->assertSame('https://example.com/page', TextUtils::canonicalUrl('example.com/page'));
    }

    /**
     * PIB serves one press release under several locale variants. These were
     * being catalogued as separate sources, so the same document was cited twice
     * in one chapter's reference list.
     */
    public function test_canonical_url_collapses_government_locale_variants(): void
    {
        $english = TextUtils::canonicalUrl('https://pib.gov.in/PressReleasePage.aspx?PRID=2257087&reg=3&lang=1');
        $hindi = TextUtils::canonicalUrl('https://pib.gov.in/PressReleasePage.aspx?PRID=2257087&reg=3&lang=2');

        $this->assertSame($english, $hindi);
        $this->assertStringContainsString('PRID=2257087', $english);
        $this->assertStringNotContainsString('lang=', $english);
    }

    public function test_canonical_url_keeps_genuinely_different_documents_apart(): void
    {
        $this->assertNotSame(
            TextUtils::canonicalUrl('https://pib.gov.in/PressReleasePage.aspx?PRID=2257087&lang=1'),
            TextUtils::canonicalUrl('https://pib.gov.in/PressReleasePage.aspx?PRID=2257099&lang=1')
        );
    }

    public function test_domain_extraction_handles_indian_government_suffixes(): void
    {
        $this->assertSame('npci.org.in', TextUtils::domain('https://www.npci.org.in/product/upi'));
        $this->assertSame('rbi.org.in', TextUtils::domain('https://rbi.org.in/statistics'));
        $this->assertSame('pib.gov.in', TextUtils::domain('https://pib.gov.in/PressReleasePage.aspx'));
    }

    public function test_url_validation_requires_http_or_https(): void
    {
        $this->assertTrue(TextUtils::isValidUrl('https://npci.org.in'));
        $this->assertFalse(TextUtils::isValidUrl('ftp://npci.org.in'));
        $this->assertFalse(TextUtils::isValidUrl('npci.org.in'));
        $this->assertFalse(TextUtils::isValidUrl(''));
    }

    public function test_html_is_reduced_to_readable_text(): void
    {
        $text = TextUtils::htmlToText('<html><head><style>a{color:red}</style></head><body><p>Hello</p></body></html>');

        $this->assertStringContainsString('Hello', $text);
        $this->assertStringNotContainsString('color:red', $text);
    }

    public function test_line_endings_are_normalised(): void
    {
        $this->assertSame("a\nb\nc", TextUtils::normalizeLineEndings("a\r\nb\rc"));
    }
}