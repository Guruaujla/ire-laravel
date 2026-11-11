<?php

use App\Http\Controllers\AccountingController;
use Illuminate\Support\Facades\Route;

// Include from routes/web.php: require __DIR__.'/accounting.php';
Route::middleware(['web', 'auth'])->prefix('accounting')->name('accounting.')->group(function () {
    Route::get('connect', [AccountingController::class, 'connect'])->name('connect');
    Route::get('return', [AccountingController::class, 'return'])->name('return');
    Route::post('records/{entity}/match', [AccountingController::class, 'match'])->whereIn('entity', ['customer', 'item', 'tax_code'])->name('records.match');
    Route::post('records/{entity}/bind', [AccountingController::class, 'bind'])->whereIn('entity', ['customer', 'item', 'tax_code'])->name('records.bind');
    Route::post('records/{entity}/snapshots', [AccountingController::class, 'startSnapshot'])->whereIn('entity', ['customer', 'item'])->name('records.snapshots.start');
    Route::get('records/{entity}/snapshots/latest', [AccountingController::class, 'latestSnapshot'])->whereIn('entity', ['customer', 'item'])->name('records.snapshots.latest');
    Route::get('records/{entity}/search', [AccountingController::class, 'search'])->whereIn('entity', ['customer', 'item'])->name('records.search');
    Route::post('records/{entity}/bind-jobs', [AccountingController::class, 'startBindJob'])->whereIn('entity', ['customer', 'item'])->name('records.bind-jobs.start');
    Route::get('records/{entity}/bind-jobs/{job}/results', [AccountingController::class, 'bindJobResults'])->whereIn('entity', ['customer', 'item'])->name('records.bind-jobs.results');
    Route::post('invoices/{invoice}', [AccountingController::class, 'pushInvoice'])->name('invoices.push');
    Route::get('invoices/{invoice}', [AccountingController::class, 'document'])->name('invoices.show');
    Route::get('syncs/{sync}', [AccountingController::class, 'show'])->name('syncs.show');
});
