<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TransferTier;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserAdminController extends Controller
{
    public function users(Request $request): JsonResponse
    {
        $query = User::where('is_staff', false)->with('wallets');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(fn($q) => $q->where('name', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%"));
        }

        if ($request->filled('status'))     $query->where('status', $request->status);
        if ($request->filled('kyc_status')) $query->where('kyc_status', $request->kyc_status);
        if ($request->filled('tier'))       $query->where('tier', $request->tier);

        return response()->json($query->latest()->paginate(25));
    }

    public function userShow(int $id): JsonResponse
    {
        $user = User::with([
            'wallets.account.balance',
            'kycRecords',
            'transactions' => fn($q) => $q->latest()->limit(10),
        ])->findOrFail($id);

        return response()->json([
            'user' => [
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'phone'         => $user->phone,
                'country_code'  => $user->country_code,
                'kyc_status'    => $user->kyc_status,
                'status'        => $user->status,
                'created_at'    => $user->created_at,
                'last_login_at' => $user->last_login_at,
            ],
            'wallets'      => $user->wallets,
            'kyc_records'  => $user->kycRecords,
            'transactions' => $user->transactions,
        ]);
    }

    public function userSuspend(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $user = User::where('is_staff', false)->findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot suspend your own account.'], 422);
        }

        $user->update(['status' => 'suspended']);
        $user->tokens()->delete();

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.user.suspended',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'old_values'  => ['status' => 'active'],
            'new_values'  => ['status' => 'suspended', 'reason' => $data['reason']],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'User suspended successfully.']);
    }

    public function userRestore(Request $request, int $id): JsonResponse
    {
        $user = User::where('is_staff', false)->findOrFail($id);
        $user->update(['status' => 'active']);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.user.restored',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'old_values'  => ['status' => 'suspended'],
            'new_values'  => ['status' => 'active'],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'User restored successfully.']);
    }

    public function userUpgradeTier(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'tier'   => 'required|in:' . TransferTier::where('is_active', true)->pluck('name')->implode(','),
            'reason' => 'nullable|string',
        ]);

        $user    = User::findOrFail($id);
        $oldTier = $user->tier;
        $tiers   = TransferTier::where('is_active', true)->pluck('level', 'name');

        if (($tiers[$data['tier']] ?? -1) <= ($tiers[$oldTier] ?? -1)) {
            return response()->json(['message' => 'Can only upgrade to a higher tier.'], 422);
        }

        $user->update(['tier' => $data['tier']]);

        $maxLevel = TransferTier::where('is_active', true)->max('level');
        $newLevel  = $tiers[$data['tier']] ?? -1;
        $user->update(['kyc_status' => $newLevel >= $maxLevel ? 'verified' : 'pending']);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.user.tier_upgraded',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'new_values'  => ['from' => $oldTier, 'to' => $data['tier'], 'reason' => $data['reason'] ?? null],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => "User upgraded to {$data['tier']} tier successfully."]);
    }
}
