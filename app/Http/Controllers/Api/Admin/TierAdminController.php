<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\TransferTier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TierAdminController extends Controller
{
    public function tierList(): JsonResponse
    {
        return response()->json(['tiers' => TransferTier::orderBy('id')->get()]);
    }

    public function tierCreate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'                  => 'required|string|unique:transfer_tiers,name',
            'label'                 => 'required|string',
            'daily_limit'           => 'required|numeric|min:0',
            'monthly_limit'         => 'required|numeric|min:0',
            'per_transaction_limit' => 'required|numeric|min:0',
            'fee_discount_percent'  => 'required|numeric|min:0|max:100',
        ]);

        $tier = TransferTier::create($data);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.tier.created',
            'entity_type' => 'TransferTier',
            'entity_id'   => $tier->id,
            'new_values'  => $data,
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Tier created successfully.', 'tier' => $tier], 201);
    }

    public function tierUpdate(Request $request, int $id): JsonResponse
    {
        $tier = TransferTier::findOrFail($id);

        $data = $request->validate([
            'label'                 => 'sometimes|string',
            'daily_limit'           => 'sometimes|numeric|min:0',
            'monthly_limit'         => 'sometimes|numeric|min:0',
            'per_transaction_limit' => 'sometimes|numeric|min:0',
            'fee_discount_percent'  => 'sometimes|numeric|min:0|max:100',
            'is_active'             => 'sometimes|boolean',
        ]);

        $tier->update($data);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.tier.updated',
            'entity_type' => 'TransferTier',
            'entity_id'   => $tier->id,
            'new_values'  => $data,
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Tier updated successfully.', 'tier' => $tier]);
    }
}
