@extends('layouts.app')

@section('title', 'Generate a book')

@section('content')
<div class="card">
    <h2 style="margin-top:0;">Generate a book</h2>
    <p class="muted">
        Five agents run in sequence: Planner, Researcher, Writer, Fact Checker, Editor.
        The Researcher searches live public sources and only verified sources are citable.
        A full three-chapter run makes many live API calls and takes a few minutes.
    </p>

    <form method="POST" action="{{ route('book.store') }}">
        @csrf

        <div class="field">
            <label for="title">Book title</label>
            <input type="text" id="title" name="title" value="{{ old('title', $defaults['title']) }}" required>
        </div>

        <div class="field">
            <label for="audience">Audience</label>
            <input type="text" id="audience" name="audience" value="{{ old('audience', $defaults['audience']) }}" required>
            <p class="hint">Drives the tone and the level of explanation in every chapter.</p>
        </div>

        <div class="field">
            <label for="tone">Tone</label>
            <input type="text" id="tone" name="tone" value="{{ old('tone', $defaults['tone']) }}">
            <p class="hint">Optional. Leave blank to use the default mentor voice.</p>
        </div>

        <div class="row">
            <button type="submit">Start generation</button>
            <span class="muted">Chapter count and length come from <code>config/ai.php</code>.</span>
        </div>
    </form>
</div>

<div class="card">
    <h2 style="margin-top:0;">Recent runs</h2>

    @if (count($runs) === 0)
        <p class="empty">No runs yet.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Status</th>
                    <th>Chapters</th>
                    <th>Words</th>
                    <th>Took</th>
                    <th>Finished</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($runs as $run)
                    <tr>
                        <td>{{ $run['title'] }}</td>
                        <td>
                            @if ($run['status'] === 'running')
                                <span class="badge run">RUNNING</span>
                            @elseif ($run['passed'])
                                <span class="badge pass">PASSED</span>
                            @else
                                <span class="badge fail">FAILED</span>
                            @endif
                        </td>
                        <td>{{ $run['chapters'] }}</td>
                        <td>{{ number_format($run['words']) }}</td>
                        <td>{{ $run['seconds'] !== null ? $run['seconds'].'s' : '—' }}</td>
                        <td>{{ $run['finished_at'] ?? '—' }}</td>
                        <td>
                            <a href="{{ route('book.show', $run['id']) }}">View</a>
                            <form method="POST" action="{{ route('book.destroy', $run['id']) }}"
                                  style="display:inline" onsubmit="return confirm('Delete this run?')">
                                @csrf
                                @method('DELETE')
                                <button class="secondary" type="submit" style="padding:0.2rem 0.5rem;font-size:0.8rem;">Delete</button>
                            </form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</div>
@endsection