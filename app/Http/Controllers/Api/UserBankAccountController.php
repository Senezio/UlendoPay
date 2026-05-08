<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserBankAccount;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Crypt;

class UserBankAccountController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $accounts = $request->user()->bankAccounts()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderByDesc('created_at')
            ->get();
        return response()->json(['bank_accounts' => $accounts]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'bank_name'      => 'required|string|max:255',
            'bank_code'      => 'nullable|string|max:20',
            'account_number' => 'required|string|max:50',
            'account_name'   => 'required|string|max:255',
            'branch_code'    => 'nullable|string|max:20',
            'currency_code'  => 'required|string|size:3',
            'country_code'   => 'required|string|size:3',
            'label'          => 'nullable|string|max:50',
            'is_default'     => 'boolean',
        ]);
        if ($data['is_default'] ?? false) {
            $request->user()->bankAccounts()->update(['is_default' => false]);
        }
        $account = $request->user()->bankAccounts()->create([
            'bank_name'                => $data['bank_name'],
            'bank_code'                => $data['bank_code'] ?? null,
            'account_number_encrypted' => Crypt::encryptString($data['account_number']),
            'account_number_masked'    => UserBankAccount::maskAccountNumber($data['account_number']),
            'account_name'             => $data['account_name'],
            'branch_code'              => $data['branch_code'] ?? null,
            'currency_code'            => strtoupper($data['currency_code']),
            'country_code'             => strtoupper($data['country_code']),
            'label'                    => $data['label'] ?? null,
            'is_default'               => $data['is_default'] ?? false,
            'is_active'                => true,
        ]);
        return response()->json(['message' => 'Bank account saved.', 'bank_account' => $account], 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $account = $request->user()->bankAccounts()->where('is_active', true)->findOrFail($id);
        $data = $request->validate([
            'bank_name'    => 'sometimes|string|max:255',
            'bank_code'    => 'nullable|string|max:20',
            'account_name' => 'sometimes|string|max:255',
            'branch_code'  => 'nullable|string|max:20',
            'label'        => 'nullable|string|max:50',
            'is_default'   => 'boolean',
        ]);
        if ($data['is_default'] ?? false) {
            $request->user()->bankAccounts()->update(['is_default' => false]);
        }
        $account->update($data);
        return response()->json(['message' => 'Bank account updated.', 'bank_account' => $account->fresh()]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $account = $request->user()->bankAccounts()->where('is_active', true)->findOrFail($id);
        $account->update(['is_active' => false]);
        return response()->json(['message' => 'Bank account removed.']);
    }

    public function setDefault(Request $request, string $id): JsonResponse
    {
        $request->user()->bankAccounts()->update(['is_default' => false]);
        $account = $request->user()->bankAccounts()->where('is_active', true)->findOrFail($id);
        $account->update(['is_default' => true]);
        return response()->json(['message' => 'Default bank account updated.']);
    }
}
