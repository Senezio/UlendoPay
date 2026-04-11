<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class WalletController extends Controller
{
    public function __construct(private LedgerService $ledger) {}

    public function index(Request $request): JsonResponse
    {
        $wallets = $request->user()
            ->wallets()
            ->with('account.balance')
            ->where('status', 'active')
            ->get()
            ->map(fn($w) => $this->formatWallet($w));

        return response()->json(['wallets' => $wallets]);
    }

    public function show(Request $request, string $currency): JsonResponse
    {
        $wallet = $request->user()
            ->wallets()
            ->with('account.balance')
            ->where('currency_code', strtoupper($currency))
            ->where('status', 'active')
            ->first();

        if (! $wallet) {
            return response()->json(['message' => 'Wallet not found.'], 404);
        }

        return response()->json(['wallet' => $this->formatWallet($wallet)]);
    }

    private function formatWallet($wallet): array
    {
        return [
            'currency' => $wallet->currency_code,
            'balance'  => $wallet->account?->balance?->balance ?? '0.000000',
            'status'   => $wallet->status,
        ];
    }
}
