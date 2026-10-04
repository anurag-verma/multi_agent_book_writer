@extends('layouts.app')

@section('title', $run['brief']['title'] ?? 'Run')

@section('content')

@php
    $status = $run['status'] ?? 'unknown';
    $validation = $run['validation'] ?? [];
    $chapters = $run['chapters'] ?? [];
    $artifacts = $run['artifacts'] ?? [];
@endphp

<div class="card">
    <div class="row" style="justify-content:space-between;">
        <div>
            <h2 style="margin:0;">{{ $run['brief']['title'] ?? 'Untitled' }}</h2>
            <p class="muted" style="margin:0.35rem 0 0;">
                {{ $run['brief']['audience'] ?? '' }}
                @if (! empty($run['elapsed_seconds']))
                    &middot; took {{ $run['elapsed_seconds'] }}s
                @endif
            </p>
        </div>
        <div class="row">
            @if ($status === 'running')
                <span class="badge run">RUNNING</span>
            @elseif ($validation['passed'] ?? false)
                <span class="badge pass">VALIDATION PASSED</span>
            @else
                <span class="badge fail">VALIDATION FAILED</span>
            @endif
            <a href="{{ route('book.index') }}" class="muted">Back</a>
        </div>
    </div>

    @if (! empty($run['error']))
        <div class="notice err" style="margin-top:1rem;margin-bottom:0;">
            <strong>{{ $run['failed_agent'] ?? 'Pipeline' }} failed:</strong> {{ $run['error'] }}
        </div>
    @endif

    @if ($status === 'running')
        <p class="muted" style="margin-bottom:0;">This page refreshes itself while the agents work.</p>
    @endif
</div>

{{-- Validation summary --}}
@if (! empty($validation))
    <div class="card">
        <h2 style="margin-top:0;">Validation</h2>
        <p class="muted">
            Every check below is deterministic PHP. No language model is involved, so the
            result is reproducible from the chapters alone.
        </p>

        @if (count($validation['chapters'] ?? []) > 0)
            <table>
                <thead>
                    <tr>
                        <th>Ch.</th>
                        <th>Title</th>
                        <th>Words</th>
                        <th>Citations</th>
                        <th>References</th>
                        <th>Fact check</th>
                        <th>Takeaway</th>
                        <th>Result</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($validation['chapters'] as $chapter)
                        <tr>
                            <td>{{ $chapter['chapter'] }}</td>
                            <td>{{ $chapter['title'] }}</td>
                            <td>{{ $chapter['word_count'] }}</td>
                            <td>{{ $chapter['citations'] }}</td>
                            <td>{{ $chapter['references'] }}</td>
                            <td class="muted">{{ $chapter['fact_check'] ?? 'not run' }}</td>
                            <td><span class="badge {{ $chapter['takeaway'] === 'PASS' ? 'pass' : 'fail' }}">{{ $chapter['takeaway'] }}</span></td>
                            <td><span class="badge {{ $chapter['validation'] === 'PASS' ? 'pass' : 'fail' }}">{{ $chapter['validation'] }}</span></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        @if (count($validation['errors'] ?? []) > 0)
            <h2>Errors</h2>
            <ul>
                @foreach ($validation['errors'] as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        @if (count($validation['warnings'] ?? []) > 0)
            <h2>Warnings</h2>
            <ul>
                @foreach ($validation['warnings'] as $warning)
                    <li>{{ $warning }}</li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

{{-- Artifacts --}}
@if (count($artifacts) > 0)
    <div class="card">
        <h2 style="margin-top:0;">Generated files</h2>
        <ul>
            @foreach ($artifacts as $name => $path)
                <li><code>{{ $path }}</code></li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Chapters --}}
@foreach ($chapters as $chapter)
    <div class="card">
        <h2 style="margin-top:0;">Chapter {{ $chapter['chapter_number'] }}: {{ $chapter['title'] }}</h2>
        <p class="muted" style="margin-top:-0.4rem;">
            {{ $chapter['word_count'] }} words &middot; citations {{ implode(', ', array_map(fn ($c) => "[$c]", $chapter['citations_used'])) }}
        </p>

        <div class="prose">
            @php
                // Split the body into paragraphs and pull the Takeaway line out so
                // it can be styled, rather than rendering it as ordinary prose.
                $parts = preg_split('/\R{2,}/', trim($chapter['body']));
                $takeaway = null;
                $prose = [];
                foreach ($parts as $part) {
                    if (preg_match('/^Takeaway:\s*(.+)$/i', trim($part)) === 1) {
                        $takeaway = preg_replace('/^Takeaway:\s*/i', '', trim($part));
                        continue;
                    }
                    $prose[] = $part;
                }
            @endphp

            @foreach ($prose as $paragraph)
                @if (trim($paragraph) !== '')
                    <p>{{ $paragraph }}</p>
                @endif
            @endforeach

            @if ($takeaway)
                <div class="takeaway"><strong>Takeaway:</strong> {{ $takeaway }}</div>
            @endif
        </div>

        @if (count($chapter['sources'] ?? []) > 0)
            <div class="refs">
                <strong>References</strong>
                <ol>
                    @foreach ($chapter['sources'] as $source)
                        <li>
                            {{ $source['organization'] }}. &ldquo;{{ $source['title'] }}&rdquo;.
                            <a href="{{ $source['url'] }}" target="_blank" rel="noopener noreferrer">{{ $source['url'] }}</a>
                        </li>
                    @endforeach
                </ol>
            </div>
        @endif

        @if (count($chapter['warnings'] ?? []) > 0)
            <details>
                <summary>{{ count($chapter['warnings']) }} writer warning(s)</summary>
                <div class="body">
                    <ul>
                        @foreach ($chapter['warnings'] as $warning)
                            <li>{{ $warning }}</li>
                        @endforeach
                    </ul>
                </div>
            </details>
        @endif
    </div>

    {{-- Fact check detail for this chapter --}}
    @php $check = $factChecks[$chapter['chapter_number']] ?? null; @endphp
    @if ($check)
        <div class="card">
            <h2 style="margin-top:0;">Fact check: Chapter {{ $chapter['chapter_number'] }}</h2>
            <p class="muted" style="margin-top:-0.4rem;">
                {{ $check['passed'] }} passed &middot; {{ $check['failed'] }} failed &middot;
                {{ $check['uncertain'] }} uncertain out of {{ $check['total'] }} citations
            </p>

            <table>
                <thead>
                    <tr>
                        <th>[n]</th>
                        <th>Claim</th>
                        <th>Status</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($check['citations'] as $item)
                        <tr>
                            <td>{{ $item['citation'] }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($item['claim'], 160) }}</td>
                            <td>
                                <span class="badge {{ $item['status'] === 'PASS' ? 'pass' : ($item['status'] === 'FAIL' ? 'fail' : 'warn') }}">
                                    {{ $item['status'] }}
                                </span>
                            </td>
                            <td class="muted">{{ $item['reason'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endforeach

{{-- Execution log --}}
@if (count($run['events'] ?? []) > 0)
    <div class="card">
        <h2 style="margin-top:0;">Execution log</h2>
        <p class="muted">Every agent transition, in order. This is the trail the bounded revision loop produced.</p>
        <div class="log" id="log">
            @foreach ($run['events'] as $event)
                <div>
                    <span class="at">{{ $event['at'] ?? '' }}</span>
                    <span class="msg">{{ $event['message'] }}</span>
                </div>
            @endforeach
        </div>
    </div>
@endif

@if ($status === 'running')
    <script>
        // Poll while the pipeline runs. Three chapters of live research and
        // writing takes minutes, so the page reloads itself rather than
        // asking the user to sit there and press refresh.
        setTimeout(function () { window.location.reload(); }, 5000);
    </script>
@endif

@endsection