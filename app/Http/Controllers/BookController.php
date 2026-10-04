<?php

namespace App\Http\Controllers;

use App\AI\Services\BookGenerator;
use App\Jobs\GenerateBookJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookController extends Controller
{
    public function __construct(private readonly BookGenerator $generator) {}

    public function index(): View
    {
        return view('book.index', [
            'runs' => $this->generator->recent(),
            'defaults' => [
                'title' => 'Pay Me on UPI: How Digital Payments Changed Small Business in India',
                'audience' => 'First-time small-business owners in India',
                'tone' => 'Friendly, clear and encouraging, as if a mentor is explaining things to a new shop owner',
            ],
        ]);
    }

    /**
     * Kick off a generation run.
     *
     * The pipeline makes live HTTP calls to a search API and an LLM, so it is
     * dispatched to a job rather than run inside the request. The UI polls the
     * status endpoint below for progress.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'audience' => ['required', 'string', 'max:200'],
            'tone' => ['nullable', 'string', 'max:200'],
        ]);

        GenerateBookJob::dispatch($data);

        return redirect()
            ->route('book.index')
            ->with('status', 'Generation started. This runs several live research and writing steps, so it can take a few minutes.');
    }

    public function show(string $id): View|RedirectResponse
    {
        $run = $this->generator->find($id);

        if ($run === null) {
            return redirect()
                ->route('book.index')
                ->with('error', 'That run no longer exists.');
        }

        return view('book.show', [
            'run' => $run,
            'factChecks' => collect($run['fact_checks'] ?? [])->keyBy('chapter')->all(),
        ]);
    }

    /**
     * JSON progress endpoint used by the polling script on the show page.
     */
    public function status(string $id)
    {
        $run = $this->generator->find($id);

        if ($run === null) {
            return response()->json(['error' => 'Run not found.'], 404);
        }

        return response()->json([
            'status' => $run['status'] ?? 'running',
            'success' => $run['success'] ?? null,
            'elapsed' => $run['elapsed_seconds'] ?? null,
            'events' => $run['events'] ?? [],
            'passed' => $run['validation']['passed'] ?? null,
        ]);
    }

    public function destroy(string $id): RedirectResponse
    {
        $this->generator->forget($id);

        return redirect()->route('book.index')->with('status', 'Run deleted.');
    }
}