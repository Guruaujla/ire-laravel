<?php

use App\Http\Controllers\AccountingController;
use Illuminate\Support\Facades\Route;

// Include from routes/web.php: require __DIR__.'/accounting.php';
Route::middleware(['web', 'auth'])->prefix('accounting')->name('accounting.')->group(function () {
    Route::get('connect', [AccountingController::class, 'connect'])->name('connect');
    Route::get('return', [AccountingController::class, 'return'])->name('return');
    Route::post('records/{entity}/match', [AccountingController::class, 'match'])->whereIn('entity', ['customer', 'item', 'tax_code'])->name('records.match');
    Route::post('records/{entity}/bind', [AccountingController::class, 'bind'])->whereIn('entity', ['customer', 'item', 'tax_code'])->name('records.bind');
    Route::post('invoices/{invoice}', [AccountingController::class, 'pushInvoice'])->name('invoices.push');
    Route::get('invoices/{invoice}', [AccountingController::class, 'document'])->name('invoices.show');
    Route::get('syncs/{sync}', [AccountingController::class, 'show'])->name('syncs.show');
});
