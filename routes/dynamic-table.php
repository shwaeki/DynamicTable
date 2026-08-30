<?php

use Illuminate\Support\Facades\Route;
use Shwaeki\DynamicTable\Http\Controllers\ActionController;
use Shwaeki\DynamicTable\Http\Controllers\BulkEditController;
use Shwaeki\DynamicTable\Http\Controllers\CreateController;
use Shwaeki\DynamicTable\Http\Controllers\EditController;
use Shwaeki\DynamicTable\Http\Controllers\RowActionController;
use Shwaeki\DynamicTable\Http\Controllers\RowDetailController;
use Shwaeki\DynamicTable\Http\Controllers\TableController;
use Shwaeki\DynamicTable\Http\Controllers\ToolbarActionController;
use Shwaeki\DynamicTable\Http\Controllers\TransferController;
use Shwaeki\DynamicTable\Http\Controllers\ViewController;

Route::post('data', [TableController::class, 'data'])->name('data');
Route::post('fields', [TableController::class, 'fields'])->name('fields');
Route::post('options', [TableController::class, 'options'])->name('options');

Route::post('edit', [EditController::class, 'update'])->name('edit');
Route::post('action', ActionController::class)->name('action');
Route::post('row-action', RowActionController::class)->name('row-action');
Route::post('toolbar-action', ToolbarActionController::class)->name('toolbar-action');
Route::post('create', CreateController::class)->name('create');
Route::post('bulk-edit', BulkEditController::class)->name('bulk-edit');
Route::post('row-detail', RowDetailController::class)->name('row-detail');

Route::post('views', [ViewController::class, 'index'])->name('views.index');
Route::post('views/create', [ViewController::class, 'store'])->name('views.store');
Route::post('views/{view}/update', [ViewController::class, 'update'])->name('views.update');
Route::post('views/{view}/delete', [ViewController::class, 'destroy'])->name('views.destroy');
Route::post('views/{view}/default', [ViewController::class, 'setDefault'])->name('views.default');
Route::post('views/{view}/shares', [ViewController::class, 'shares'])->name('views.shares');
Route::post('views/{view}/share', [ViewController::class, 'share'])->name('views.share');

Route::post('export', [TransferController::class, 'export'])->name('export');
Route::post('template', [TransferController::class, 'template'])->name('template');
Route::post('import/analyze', [TransferController::class, 'analyze'])->name('import.analyze');
Route::post('import', [TransferController::class, 'import'])->name('import');
Route::post('progress', [TransferController::class, 'progress'])->name('progress');
Route::post('download', [TransferController::class, 'download'])->name('download');
