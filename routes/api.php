<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\RecipientController;
use App\Http\Controllers\Api\RateLockController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\TopUpController;

Route::prefix('v1')->group(function () {

    // ── Public auth routes ───────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register',        [AuthController::class, 'register']);
        Route::post('/verify-phone',    [AuthController::class, 'verifyPhone']);
        Route::post('/login',           [AuthController::class, 'login']);
        Route::post('/verify-login',    [AuthController::class, 'verifyLogin']);
        Route::post('/forgot-pin',      [AuthController::class, 'forgotPin']);
        Route::post('/reset-pin',       [AuthController::class, 'resetPin']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword']);
        Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
    });

    // ── Pawapay webhook — public, secured via signature verification ─────────
    Route::post('/topup/webhook', [TopUpController::class, 'webhook']);

    // ── Authenticated routes ─────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me',      [AuthController::class, 'me']);

        // KYC
        Route::get('/kyc/status',        [KycController::class, 'status']);
        Route::post('/kyc/submit',       [KycController::class, 'submit']);
        Route::get('/kyc/document/{id}', [KycController::class, 'document'])
            ->name('kyc.document');

        // Wallets
        Route::get('/wallets',            [WalletController::class, 'index']);
        Route::get('/wallets/{currency}', [WalletController::class, 'show']);

        // Top-up
        Route::get('/topup/operators',         [TopUpController::class, 'operators']);
        Route::post('/topup/initiate',         [TopUpController::class, 'initiate']);
        Route::get('/topup/status/{reference}',[TopUpController::class, 'status']);
        Route::get('/topup/history',           [TopUpController::class, 'history']);

        // Recipients
        Route::apiResource('/recipients', RecipientController::class);

        // Rate locks
        Route::post('/rate-locks',     [RateLockController::class, 'store']);
        Route::get('/rate-locks/{id}', [RateLockController::class, 'show']);

        // Transactions
        Route::post('/transactions',            [TransactionController::class, 'store']);
        Route::get('/transactions',             [TransactionController::class, 'index']);
        Route::get('/transactions/{reference}', [TransactionController::class, 'show']);
    });
});
