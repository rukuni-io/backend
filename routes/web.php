<?php

use App\Http\Controllers\BillingWebhookController;
use Illuminate\Support\Facades\Route;
use Laravel\Cashier\Http\Controllers\PaymentController;

Route::get('/', function () {
    // return view('index');
});

Route::prefix(config('cashier.path'))->name('cashier.')->group(function () {
    Route::get('payment/{id}', [PaymentController::class, 'show'])->name('payment');
    Route::post('webhook', [BillingWebhookController::class, 'handleWebhook'])->name('webhook');
});
