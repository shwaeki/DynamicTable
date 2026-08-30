<?php

use Illuminate\Support\Facades\Route;
use Shwaeki\DynamicTable\Http\Controllers\AssetController;

/*
 * The version is part of the path, not a query string.
 *
 * Feature modules import their neighbours with relative specifiers
 * ("./ui.js"), which resolve inside whatever directory the importing module
 * came from. Putting the version in the path means those imports inherit it
 * automatically, so every module on the page comes from one versioned
 * directory: a deploy can never leave a stale module cached beside a fresh
 * core, and everything stays safely immutable.
 */
Route::get('asset/{version}/{file}', AssetController::class)
    ->where('version', '[A-Za-z0-9._-]{1,40}')
    ->where('file', '[A-Za-z0-9\-\.]+')
    ->name('asset');

// Kept so pages rendered before the versioned URLs existed keep working.
Route::get('asset/{file}', AssetController::class)
    ->where('file', '[A-Za-z0-9\-\.]+')
    ->name('asset.legacy');
