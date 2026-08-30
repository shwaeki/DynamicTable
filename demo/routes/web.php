<?php

use App\Http\Controllers\DocController;
use App\Http\Controllers\ExampleController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dynamic-table/examples');

Route::prefix('dynamic-table/examples')->group(function (): void {
    Route::get('/', [ExampleController::class, 'index'])->name('examples.index');
    Route::get('/locale/{locale}', [ExampleController::class, 'locale'])->name('examples.locale');

    // Example URLs are stable between versions so documentation links keep working.
    Route::get('/{example}', [ExampleController::class, 'show'])->name('examples.show');
});

Route::prefix('dynamic-table/docs')->group(function (): void {
    Route::get('/', [DocController::class, 'index'])->name('docs.index');

    // One route per docs/*.md file, so a documentation link is a real URL.
    Route::get('/{page}', [DocController::class, 'show'])->name('docs.show');
});
