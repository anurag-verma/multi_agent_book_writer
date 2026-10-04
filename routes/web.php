<?php

use App\Http\Controllers\BookController;
use Illuminate\Support\Facades\Route;

/*
|-------------------------------------------------------------------------------
| Book generation routes
|-------------------------------------------------------------------------------
| GET  /                 Form plus recent runs
| POST /                 Start a generation run
| GET  /runs/{id}        Full result: chapters, fact-check verdicts, logs
| GET  /runs/{id}/status JSON progress for polling
| DELETE /runs/{id}      Remove a stored run
*/

Route::get('/', [BookController::class, 'index'])->name('book.index');
Route::post('/', [BookController::class, 'store'])->name('book.store');
Route::get('/runs/{id}', [BookController::class, 'show'])->name('book.show');
Route::get('/runs/{id}/status', [BookController::class, 'status'])->name('book.status');
Route::delete('/runs/{id}', [BookController::class, 'destroy'])->name('book.destroy');