<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AuthCredentialController;
use App\Http\Controllers\Api\AuthRegistrationController;
use App\Http\Controllers\Api\AuthTwoFactorController;
use App\Http\Controllers\Api\AuthSessionController;
use App\Http\Controllers\Api\AuthLookupController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Controllers\Api\RecipientController;
use App\Http\Controllers\Api\RateLockController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\KycController;
use App\Http\Controllers\Api\TopUpController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\KycAdminController;
use App\Http\Controllers\Api\Admin\UserAdminController;
use App\Http\Controllers\Api\Admin\TransactionAdminController;
use App\Http\Controllers\Api\Admin\RateAdminController;
use App\Http\Controllers\Api\Admin\AccountAdminController;
use App\Http\Controllers\Api\Admin\PartnerAdminController;
use App\Http\Controllers\Api\Admin\FraudAdminController;
use App\Http\Controllers\Api\Admin\ComplianceAdminController;
use App\Http\Controllers\Api\Admin\TierAdminController;
use App\Http\Controllers\Api\Admin\StaffAdminController;
use App\Http\Controllers\Api\Admin\WebhookAdminController;
use App\Http\Controllers\Api\ReportingController;
use App\Http\Controllers\Api\PeriodController;

Route::prefix('v1')->group(function () {

    // ── Public auth routes ───────────────────────────────────────────────────
    Route::prefix('auth')->group(function () {
        Route::post('/register',        [AuthRegistrationController::class, 'register'])->middleware('throttle:otp');
        Route::post('/verify-phone',    [AuthRegistrationController::class, 'verifyPhone']);
        Route::post('/resend-otp',      [AuthRegistrationController::class, 'resendOtp']);
        Route::post('/login',           [AuthController::class, 'login']);
        Route::post('/verify-login',    [AuthController::class, 'verifyLogin']);
        Route::post('/verify-totp',     [AuthController::class, 'verifyTotp']);
        Route::post('/forgot-pin',      [AuthCredentialController::class, 'forgotPin'])->middleware('throttle:otp');
        Route::post('/reset-pin',       [AuthCredentialController::class, 'resetPin']);
        Route::post('/forgot-password', [AuthCredentialController::class, 'forgotPassword'])->middleware('throttle:otp');
        Route::post('/reset-password',  [AuthCredentialController::class, 'resetPassword']);
    });

    Route::post('/contact', [\App\Http\Controllers\Api\PublicController::class, 'contact'])->middleware('throttle:6,1');

    // ── Webhooks — public, secured via per-provider verification ────────────
    Route::post('/topup/webhook/pawapay',    [TopUpController::class, 'pawapayWebhook']);
    Route::post('/topup/webhook/mtn',        [TopUpController::class, 'mtnWebhook']);
    Route::post('/withdraw/webhook/pawapay', [WithdrawalController::class, 'pawapayWebhook']);
    Route::post('/withdraw/webhook/mtn',     [WithdrawalController::class, 'mtnWebhook']);

    // Legacy webhook routes
    Route::post('/topup/webhook',    [TopUpController::class, 'webhook']);
    Route::post('/withdraw/webhook', [WithdrawalController::class, 'webhook']);

    // KYC document serve
    Route::get('/kyc/document/{id}', [KycController::class, 'document'])->name('kyc.document');

    // Fee calculator
    Route::get('/calculator', [\App\Http\Controllers\Api\CalculatorController::class, 'calculate']);

    // ── Authenticated routes ─────────────────────────────────────────────────
    Route::middleware('auth:sanctum')->group(function () {

        // Session
        Route::post('/auth/logout',          [AuthSessionController::class, 'logout']);
        Route::get('/auth/me',               [AuthSessionController::class, 'me']);
        Route::post('/auth/verify-pin',      [AuthSessionController::class, 'verifyPin']);
        Route::post('/auth/verify-email',    [AuthSessionController::class, 'verifyEmail']);
        Route::get('/auth/sessions',         [AuthSessionController::class, 'sessions']);
        Route::delete('/auth/sessions/{id}', [AuthSessionController::class, 'revokeSession']);
        Route::delete('/auth/sessions',      [AuthSessionController::class, 'revokeAllSessions']);
        Route::delete('/auth/account',       [AuthSessionController::class, 'closeAccount']);
        Route::get('/auth/audit-log',        [AuthSessionController::class, 'auditLog']);

        // Two-Factor Authentication
        Route::get('/auth/2fa/setup',    [AuthTwoFactorController::class, 'setup']);
        Route::post('/auth/2fa/enable',  [AuthTwoFactorController::class, 'enable']);
        Route::post('/auth/2fa/disable', [AuthTwoFactorController::class, 'disable']);
        Route::get('/auth/2fa/status',   [AuthTwoFactorController::class, 'status']);

        // Lookup
        Route::get('/users/lookup',         [AuthLookupController::class, 'lookup'])->middleware('throttle:lookup');
        Route::get('/wallets/lookup',       [AuthLookupController::class, 'walletLookup']);
        Route::get('/auth/account-numbers', [AuthLookupController::class, 'accountNumbers']);

        // Transfer tiers
        Route::get('/tier',           [\App\Http\Controllers\Api\TierController::class, 'show']);
        Route::get('/tier/available', [\App\Http\Controllers\Api\TierController::class, 'availableTiers']);
        Route::get('/referral',       [\App\Http\Controllers\Api\TierController::class, 'referral']);

        // KYC
        Route::get('/kyc/status',  [KycController::class, 'status']);
        Route::post('/kyc/submit', [KycController::class, 'submit'])->middleware('throttle:kyc');

        // Wallets
        Route::get('/wallets',            [WalletController::class, 'index']);
        Route::get('/statement',          [\App\Http\Controllers\Api\StatementController::class, 'download']);
        Route::get('/wallets/{currency}', [WalletController::class, 'show']);

        // Top-up
        Route::get('/topup/operators',          [TopUpController::class, 'operators']);
        Route::post('/topup/initiate',          [TopUpController::class, 'initiate']);
        Route::get('/topup/status/{reference}', [TopUpController::class, 'status']);
        Route::get('/topup/history',            [TopUpController::class, 'history']);

        // Withdrawals
        Route::get('/withdraw/operators',          [WithdrawalController::class, 'operators']);
        Route::post('/withdraw/initiate',          [WithdrawalController::class, 'initiate']);
        Route::post('/withdraw/initiate/bank',     [WithdrawalController::class, 'initiateBank']);
        Route::get('/withdraw/status/{reference}', [WithdrawalController::class, 'status']);
        Route::get('/withdraw/history',            [WithdrawalController::class, 'history']);

        // Recipients
        Route::post('/recipients/predict-network', [RecipientController::class, 'predictNetwork']);
        Route::apiResource('/recipients', RecipientController::class);

        // Bank accounts
        Route::get('/bank-accounts',               [\App\Http\Controllers\Api\UserBankAccountController::class, 'index']);
        Route::post('/bank-accounts',              [\App\Http\Controllers\Api\UserBankAccountController::class, 'store']);
        Route::put('/bank-accounts/{id}',          [\App\Http\Controllers\Api\UserBankAccountController::class, 'update']);
        Route::delete('/bank-accounts/{id}',       [\App\Http\Controllers\Api\UserBankAccountController::class, 'destroy']);
        Route::post('/bank-accounts/{id}/default', [\App\Http\Controllers\Api\UserBankAccountController::class, 'setDefault']);

        // Rate locks
        Route::post('/rate-locks',     [RateLockController::class, 'store']);
        Route::get('/rate-locks/{id}', [RateLockController::class, 'show']);

        // Transactions
        Route::post('/transactions',            [TransactionController::class, 'store']);
        Route::get('/transactions',             [TransactionController::class, 'index']);
        Route::get('/transactions/{reference}', [TransactionController::class, 'show']);

        // ── Admin routes ─────────────────────────────────────────────────────
        Route::prefix('admin')->middleware('admin')->group(function () {

            Route::get('/stats',     [DashboardController::class, 'stats']);
            Route::get('/analytics', [DashboardController::class, 'analytics']);
            Route::get('/audit-log', [DashboardController::class, 'adminAuditLog']);
            Route::get('/settings',  [DashboardController::class, 'settings']);

            Route::get('/webhooks/logs',      [WebhookAdminController::class, 'webhookLogs']);
            Route::get('/webhooks/logs/{id}', [WebhookAdminController::class, 'webhookLogShow']);

            Route::get('/kyc/queue',         [KycAdminController::class, 'kycQueue']);
            Route::get('/kyc/verified',      [KycAdminController::class, 'kycVerified']);
            Route::get('/kyc/{id}',          [KycAdminController::class, 'kycShow']);
            Route::post('/kyc/{id}/approve', [KycAdminController::class, 'kycApprove'])
                ->middleware('admin:super_admin,kyc_reviewer');
            Route::post('/kyc/{id}/reject',  [KycAdminController::class, 'kycReject'])
                ->middleware('admin:super_admin,kyc_reviewer');

            Route::get('/users',               [UserAdminController::class, 'users']);
            Route::get('/users/{id}',          [UserAdminController::class, 'userShow']);
            Route::post('/users/{id}/suspend', [UserAdminController::class, 'userSuspend'])
                ->middleware('admin:super_admin,finance_officer');
            Route::post('/users/{id}/restore', [UserAdminController::class, 'userRestore'])
                ->middleware('admin:super_admin');
            Route::post('/users/{id}/upgrade-tier', [UserAdminController::class, 'userUpgradeTier'])
                ->middleware('admin:super_admin,kyc_reviewer');

            Route::get('/transactions',                    [TransactionAdminController::class, 'transactions']);
            Route::get('/transactions/export',             [TransactionAdminController::class, 'exportTransactions']);
            Route::get('/transactions/{reference}',        [TransactionAdminController::class, 'transactionShow']);
            Route::post('/transactions/{reference}/retry', [TransactionAdminController::class, 'retryTransaction']);

            Route::get('/rates',        [RateAdminController::class, 'rates']);
            Route::post('/rates/fetch', [RateAdminController::class, 'fetchRates'])
                ->middleware('admin:super_admin');

            Route::get('/accounts',              [AccountAdminController::class, 'accounts']);
            Route::post('/accounts',             [AccountAdminController::class, 'accountCreate'])
                ->middleware('admin:super_admin');
            Route::post('/accounts/{id}/toggle', [AccountAdminController::class, 'accountToggle'])
                ->middleware('admin:super_admin');
            Route::get('/accounts/{id}/ledger',  [AccountAdminController::class, 'accountLedger']);
            Route::post('/accounts/{id}/adjust', [AccountAdminController::class, 'accountAdjust'])
                ->middleware('admin:super_admin');

            Route::get('/partners',               [PartnerAdminController::class, 'partners']);
            Route::get('/partners/health',        [PartnerAdminController::class, 'partnerHealth']);
            Route::post('/partners/{id}/toggle',  [PartnerAdminController::class, 'partnerToggle'])
                ->middleware('admin:super_admin');
            Route::put('/corridors/{id}',         [PartnerAdminController::class, 'corridorUpdate'])
                ->middleware('admin:super_admin');
            Route::post('/corridors/{id}/toggle', [PartnerAdminController::class, 'corridorToggle'])
                ->middleware('admin:super_admin');

            Route::get('/fraud-alerts',               [FraudAdminController::class, 'fraudAlerts']);
            Route::post('/fraud-alerts/{id}/clear',   [FraudAdminController::class, 'fraudAlertClear'])
                ->middleware('admin:super_admin,finance_officer');
            Route::post('/fraud-alerts/{id}/confirm', [FraudAdminController::class, 'fraudAlertConfirm'])
                ->middleware('admin:super_admin,finance_officer');

            Route::get('/compliance/stats',                [ComplianceAdminController::class, 'complianceStats']);
            Route::get('/compliance/alerts',               [ComplianceAdminController::class, 'complianceAlerts']);
            Route::get('/compliance/alerts/{id}',          [ComplianceAdminController::class, 'complianceAlertShow']);
            Route::post('/compliance/alerts/{id}/review',  [ComplianceAdminController::class, 'complianceAlertReview'])
                ->middleware('admin:super_admin,kyc_reviewer');
            Route::post('/compliance/alerts/{id}/clear',   [ComplianceAdminController::class, 'complianceAlertClear'])
                ->middleware('admin:super_admin,kyc_reviewer');
            Route::post('/compliance/alerts/{id}/confirm', [ComplianceAdminController::class, 'complianceAlertConfirm'])
                ->middleware('admin:super_admin');

            Route::get('/tiers',      [TierAdminController::class, 'tierList']);
            Route::post('/tiers',     [TierAdminController::class, 'tierCreate'])
                ->middleware('admin:super_admin');
            Route::put('/tiers/{id}', [TierAdminController::class, 'tierUpdate'])
                ->middleware('admin:super_admin');

            Route::get('/staff',  [StaffAdminController::class, 'staffList'])
                ->middleware('admin:super_admin');
            Route::post('/staff', [StaffAdminController::class, 'staffCreate'])
                ->middleware('admin:super_admin');
        });
    });

    // ── Financial Reporting ──────────────────────────────────────────────────
    Route::prefix('reports')
        ->middleware(['auth:sanctum', 'admin:super_admin,finance_officer'])
        ->group(function () {
            Route::get('trial-balance', [ReportingController::class, 'trialBalance']);
            Route::get('balance-sheet', [ReportingController::class, 'balanceSheet']);
            Route::get('profit-loss',   [ReportingController::class, 'profitLoss']);
            Route::get('cash-flow',     [ReportingController::class, 'cashFlow']);
        });

    // ── Accounting Periods ───────────────────────────────────────────────────
    Route::prefix('periods')
        ->middleware(['auth:sanctum', 'admin:super_admin,finance_officer'])
        ->group(function () {
            Route::get('/',               [PeriodController::class, 'index']);
            Route::get('/{id}',           [PeriodController::class, 'show']);
            Route::post('/',              [PeriodController::class, 'open']);
            Route::post('/{id}/close',    [PeriodController::class, 'close']);
            Route::post('/{id}/reopen',   [PeriodController::class, 'reopen']);
            Route::post('/{id}/lock',     [PeriodController::class, 'lock']);
            Route::get('/{id}/snapshots', [PeriodController::class, 'snapshots']);
        });
});
