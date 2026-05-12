<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthLookupController extends Controller
{
    public function walletLookup(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string']);

        $code    = preg_replace('/\s+/', '', $request->code);
        $account = Account::where('code', $code)
            ->where('type', 'user_wallet')
            ->where('is_active', true)
            ->first();

        if (!$account) {
            return response()->json(['found' => false], 404);
        }

        return response()->json([
            'found'    => true,
            'currency' => $account->currency_code,
        ]);
    }

    public function accountNumbers(Request $request): JsonResponse
    {
        $accounts = Account::where('owner_id', $request->user()->id)
            ->where('owner_type', User::class)
            ->where('type', 'user_wallet')
            ->where('is_active', true)
            ->get(['code', 'currency_code']);

        return response()->json([
            'accounts' => $accounts->map(fn($a) => [
                'account_number' => $a->code,
                'currency'       => $a->currency_code,
            ]),
        ]);
    }

    public function lookup(Request $request): JsonResponse
    {
        $request->validate(['phone' => 'required|string|max:20']);

        $phoneHash = hash('sha256', $request->phone);
        $user      = User::where('phone_hash', $phoneHash)
            ->where('status', 'active')
            ->first();

        if (!$user) {
            return response()->json(['found' => false], 404);
        }

        return response()->json([
            'found' => true,
            'user'  => [
                'name'         => $user->name,
                'kyc_verified' => $user->isKycVerified(),
                'country_code' => $user->country_code,
            ],
        ]);
    }
}
