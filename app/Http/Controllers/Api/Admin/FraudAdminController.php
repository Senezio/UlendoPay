<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\FraudAlert;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FraudAdminController extends Controller
{
    public function fraudAlerts(Request $request): JsonResponse
    {
        $query = FraudAlert::with([
            'user:id,name,email',
            'transaction:id,reference_number,send_amount,send_currency,status',
        ]);

        if ($request->filled('status')) $query->where('status', $request->status);

        return response()->json($query->orderByDesc('risk_score')->paginate(25));
    }

    public function fraudAlertClear(Request $request, int $id): JsonResponse
    {
        FraudAlert::findOrFail($id)->update([
            'status'           => 'cleared',
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'resolution_notes' => $request->input('notes'),
        ]);

        return response()->json(['message' => 'Alert cleared.']);
    }

    public function fraudAlertConfirm(Request $request, int $id): JsonResponse
    {
        $alert = FraudAlert::findOrFail($id);
        $alert->update([
            'status'           => 'confirmed',
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'resolution_notes' => $request->input('notes'),
        ]);

        if ($alert->user_id) {
            User::find($alert->user_id)?->update(['status' => 'suspended']);
        }

        return response()->json(['message' => 'Fraud confirmed. User suspended.']);
    }
}
