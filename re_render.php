<?php

/**
 * Rebuild output/ artifacts from a persisted run record.
 *
 * The renderer is deterministic, so re-rendering a stored run needs no LLM or
 * search calls. Useful when artifacts are cleared but the run history is not.
 *
 * Usage: php re_render.php [run-id]
 */

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\AI\DTO\BookBrief;
use App\AI\DTO\ChapterDraft;
use App\AI\DTO\SourceItem;
use App\AI\DTO\ValidationReport;

$runId = $argv[1] ?? null;

if ($runId === null) {
    $runs = glob(__DIR__.'/storage/app/runs/*.json') ?: [];

    usort($runs, static fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));

    // Rank candidates rather than taking the newest that merely claims success.
    // Several older runs produced a book citing blocked domains, and the newest
    // of those was once picked over the run that actually passed validation.
    // A run that failed validation is never a good default.
    $best = null;
    $bestScore = -1;

    foreach ($runs as $file) {
        $data = json_decode((string) file_get_contents($file), true);

        if (($data['success'] ?? false) !== true || count($data['chapters'] ?? []) === 0) {
            continue;
        }

        $passed = ($data['validation']['passed'] ?? false) === true ? 1 : 0;
        $score = $passed * 1000 + count($data['chapters']);

        if ($score > $bestScore) {
            $bestScore = $score;
            $best = $data['id'] ?? basename($file, '.json');
        }
    }

    $runId = $best;
}

if ($runId === null) {
    fwrite(STDERR, "No successful run found.\n");
    exit(1);
}

$path = __DIR__.'/storage/app/runs/'.$runId.'.json';

if (! is_file($path)) {
    fwrite(STDERR, "Run not found: {$runId}\n");
    exit(1);
}

$data = json_decode((string) file_get_contents($path), true);

$brief = BookBrief::make($data['brief'] ?? []);

$chapters = array_map(
    static fn (array $row): ChapterDraft => new ChapterDraft(
        chapterNumber: (int) $row['chapter_number'],
        title: (string) $row['title'],
        body: (string) $row['body'],
        sources: array_map(
            static fn (array $source): SourceItem => new SourceItem(
                citationId: (int) $source['id'],
                organization: (string) $source['organization'],
                title: (string) $source['title'],
                url: (string) $source['url'],
                claimsSupported: $source['claims_supported'] ?? [],
                retrievedContent: (string) ($source['retrieved_content'] ?? ''),
                verified: (bool) ($source['verified'] ?? false),
                verificationNote: (string) ($source['verification_note'] ?? ''),
                httpStatus: (int) ($source['http_status'] ?? 0),
            ),
            $row['sources'] ?? []
        ),
        warnings: $row['warnings'] ?? [],
    ),
    $data['chapters'] ?? []
);

$validation = $data['validation'] ?? [];

$report = new ValidationReport(
    passed: (bool) ($validation['passed'] ?? false),
    chapters: $validation['chapters'] ?? [],
    errors: $validation['errors'] ?? [],
    warnings: $validation['warnings'] ?? [],
);

$artifacts = $app->make(App\AI\Services\BookRenderer::class)
    ->writeArtifacts($brief, $chapters, $report);

echo 'run: '.($data['id'] ?? $runId)."\n";
echo 'title: '.$brief->title."\n";
echo 'chapters: '.count($chapters)."\n";

foreach ($artifacts as $type => $file) {
    echo '  '.str_pad((string) $type, 10).$file.' ('.number_format((int) filesize($file))." bytes)\n";
}