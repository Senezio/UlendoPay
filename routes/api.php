<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\RecipientController;
use App\Http\Controllers\Api\RateLockController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\TopUpController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Controllers\Api\AdminController;

Route::prefix('v1')->group(function () {

    // ── Public auth routes ───────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register',        [AuthController::class, 'register'])->middleware('throttle:otp');
        Route::post('/verify-phone',    [AuthController::class, 'verifyPhone']);
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/verify-login', [AuthController::class, 'verifyLogin']);
        Route::post('/verify-totp',     [AuthController::class, 'verifyTotp']);
        Route::post('/forgot-pin', [AuthController::class, 'forgotPin'])->middleware('throttle:otp');
        Route::post('/reset-pin',       [AuthController::class, 'resetPin']);
        Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:otp');
        Route::post('/reset-password',  [AuthController::class, 'resetPassword']);
    });

    // ── Webhooks — public, secured via per-provider verification ────────────
    //
    // PawaPay: HMAC-SHA256 signature on raw body (X-Pawapay-Signature header)
    // MTN MoMo: verify-by-polling — status confirmed via MTN API callback
    //
    // Configure these URLs in each provider's dashboard:
    //   PawaPay deposits  → /api/v1/topup/webhook/pawapay
    //   PawaPay payouts   → /api/v1/withdraw/webhook/pawapay
    //   MTN collections   → /api/v1/topup/webhook/mtn
    //   MTN disbursements → /api/v1/withdraw/webhook/mtn
    //
    Route::post('/topup/webhook/pawapay',   [TopUpController::class, 'pawapayWebhook']);
    Route::post('/topup/webhook/mtn',       [TopUpController::class, 'mtnWebhook']);
    Route::post('/withdraw/webhook/pawapay',[WithdrawalController::class, 'pawapayWebhook']);
    Route::post('/withdraw/webhook/mtn',    [WithdrawalController::class, 'mtnWebhook']);

    // Legacy webhook routes — kept for simulator backward compatibility
    // TODO: Update ulendopay_sim.py to use /pawapay endpoints, then remove these
    Route::post('/topup/webhook',    [TopUpController::class, 'webhook']);
    Route::post('/withdraw/webhook', [WithdrawalController::class, 'webhook']);

    // KYC document serve — secured via signed token
    Route::get('/kyc/document/{id}', [KycController::class, 'document'])->name('kyc.document');

    // Fee calculator — public, no auth required
    Route::get('/calculator', [\App\Http\Controllers\Api\CalculatorController::class, 'calculate']);

    // ── Authenticated routes ─────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me',      [AuthController::class, 'me']);
        Route::get('/users/lookup',  [AuthController::class, 'lookup'])->middleware('throttle:lookup');

        // Transfer tiers
        Route::get('/tier', [\App\Http\Controllers\Api\TierController::class, 'show']);
        Route::get('/referral', [\App\Http\Controllers\Api\TierController::class, 'referral']);

        // Two-Factor Authentication
        Route::get('/auth/account-numbers', [AuthController::class, 'accountNumbers']);
        Route::get('/auth/2fa/setup',    [AuthController::class, 'twoFactorSetup']);
        Route::post('/auth/2fa/enable',  [AuthController::class, 'twoFactorEnable']);
        Route::post('/auth/2fa/disable', [AuthController::class, 'twoFactorDisable']);
        Route::get('/auth/2fa/status',   [AuthController::class, 'twoFactorStatus']);
        Route::post('/auth/verify-pin',      [AuthController::class, 'verifyPin']);
        Route::post('/auth/verify-email',    [AuthController::class, 'verifyEmail']);
        Route::get('/auth/sessions',         [AuthController::class, 'sessions']);
        Route::delete('/auth/sessions/{id}', [AuthController::class, 'revokeSession']);
        Route::delete('/auth/sessions',      [AuthController::class, 'revokeAllSessions']);
        Route::get('/auth/audit-log',        [AuthController::class, 'auditLog']);

        // KYC
        Route::get('/kyc/status',        [KycController::class, 'status']);
        Route::post('/kyc/submit',       [KycController::class, 'submit'])->middleware('throttle:kyc');

        // Wallets
        Route::get('/wallets',            [WalletController::class, 'index']);
        Route::get('/statement',           [\App\Http\Controllers\Api\StatementController::class, 'download']);
        Route::get('/wallets/{currency}', [WalletController::class, 'show']);

        // Top-up
        Route::get('/topup/operators',          [TopUpController::class, 'operators']);
        Route::post('/topup/initiate',          [TopUpController::class, 'initiate']);
        Route::get('/topup/status/{reference}', [TopUpController::class, 'status']);
        Route::get('/topup/history',            [TopUpController::class, 'history']);

        // Withdrawals
        Route::get('/withdraw/operators',          [WithdrawalController::class, 'operators']);
        Route::post('/withdraw/initiate',          [WithdrawalController::class, 'initiate']);
        Route::get('/withdraw/status/{reference}', [WithdrawalController::class, 'status']);
        Route::get('/withdraw/history',            [WithdrawalController::class, 'history']);

        // Recipients
        Route::post('/recipients/predict-network', [RecipientController::class, 'predictNetwork']);
        Route::apiResource('/recipients', RecipientController::class);

        // Rate locks
        Route::post('/rate-locks',     [RateLockController::class, 'store']);
        Route::get('/rate-locks/{id}', [RateLockController::class, 'show']);

        // Transactions
        Route::post('/transactions',            [TransactionController::class, 'store']);
        Route::get('/transactions',             [TransactionController::class, 'index']);
        Route::get('/transactions/{reference}', [TransactionController::class, 'show']);

        // ── Admin routes ─────────────────────────────────────────────────────
        Route::prefix('admin')->middleware('admin')->group(function () {

            Route::get('/stats',     [AdminController::class, 'stats']);
            Route::get('/analytics', [AdminController::class, 'analytics']);
            Route::get('/accounts',  [AdminController::class, 'accounts']);

            // KYC
            Route::get('/settings',          [AdminController::class, 'settings']);
            Route::get('/kyc/queue',         [AdminController::class, 'kycQueue']);
            Route::get('/kyc/verified',      [AdminController::class, 'kycVerified']);
            Route::get('/kyc/{id}',          [AdminController::class, 'kycShow']);
            Route::post('/kyc/{id}/approve', [AdminController::class, 'kycApprove'])
                ->middleware('admin:super_admin,kyc_reviewer');
            Route::post('/kyc/{id}/reject',  [AdminController::class, 'kycReject'])
                ->middleware('admin:super_admin,kyc_reviewer');

            // Users
            Route::get('/users',               [AdminController::class, 'users']);
            Route::get('/users/{id}',          [AdminController::class, 'userShow']);
            Route::post('/users/{id}/suspend', [AdminController::class, 'userSuspend'])
                ->middleware('admin:super_admin,finance_officer');
            Route::post('/users/{id}/restore', [AdminController::class, 'userRestore'])
                ->middleware('admin:super_admin');

            // Transactions
            Route::get('/transactions',                    [AdminController::class, 'transactions']);
            Route::get('/transactions/export',             [AdminController::class, 'exportTransactions']);
            Route::get('/transactions/{reference}',        [AdminController::class, 'transactionShow']);
            Route::post('/transactions/{reference}/retry', [AdminController::class, 'retryTransaction']);
            Route::get('/partners/health',                 [AdminController::class, 'partnerHealth']);

            // Exchange rates
            Route::get('/rates',        [AdminController::class, 'rates']);
            Route::post('/rates/fetch', [AdminController::class, 'fetchRates'])
                ->middleware('admin:super_admin');

            // Account management
            Route::get('/accounts',              [AdminController::class, 'accounts']);
            Route::post('/accounts',             [AdminController::class, 'accountCreate'])
                ->middleware('admin:super_admin');
            Route::post('/accounts/{id}/toggle', [AdminController::class, 'accountToggle'])
                ->middleware('admin:super_admin');
            Route::get('/accounts/{id}/ledger',  [AdminController::class, 'accountLedger']);
            Route::post('/accounts/{id}/adjust', [AdminController::class, 'accountAdjust'])
                ->middleware('admin:super_admin');

            // Partner management
            Route::get('/partners',                [AdminController::class, 'partners']);
            Route::post('/partners/{id}/toggle',   [AdminController::class, 'partnerToggle'])
                ->middleware('admin:super_admin');
            Route::put('/corridors/{id}',          [AdminController::class, 'corridorUpdate'])
                ->middleware('admin:super_admin');
            Route::post('/corridors/{id}/toggle',  [AdminController::class, 'corridorToggle'])
                ->middleware('admin:super_admin');

            // Fraud alerts
            Route::get('/fraud-alerts',               [AdminController::class, 'fraudAlerts']);
            Route::post('/fraud-alerts/{id}/clear',   [AdminController::class, 'fraudAlertClear'])
                ->middleware('admin:super_admin,finance_officer');
            Route::post('/fraud-alerts/{id}/confirm', [AdminController::class, 'fraudAlertConfirm'])
                ->middleware('admin:super_admin,finance_officer');

            // Tier management
            Route::get('/tiers',                        [AdminController::class, 'tierList']);
            Route::post('/tiers',                       [AdminController::class, 'tierCreate'])
                ->middleware('admin:super_admin');
            Route::put('/tiers/{id}',                   [AdminController::class, 'tierUpdate'])
                ->middleware('admin:super_admin');
            Route::post('/users/{id}/upgrade-tier',     [AdminController::class, 'userUpgradeTier'])
                ->middleware('admin:super_admin,kyc_reviewer');

            // Staff management
            Route::get('/staff',  [AdminController::class, 'staffList'])
                ->middleware('admin:super_admin');
            Route::post('/staff', [AdminController::class, 'staffCreate'])
                ->middleware('admin:super_admin');
        });
    });
});
