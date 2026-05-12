<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\ComplianceAlert;
use App\Models\PepEntry;
use App\Models\SanctionsEntry;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ComplianceAdminController extends Controller
{
    public function complianceStats(): JsonResponse
    {
        return response()->json([
            'alerts' => [
                'new'       => ComplianceAlert::where('status', 'new')->count(),
                'reviewing' => ComplianceAlert::where('status', 'reviewing')->count(),
                'confirmed' => ComplianceAlert::where('status', 'confirmed')->count(),
                'cleared'   => ComplianceAlert::where('status', 'cleared')->count(),
            ],
            'by_type' => [
                'sanctions' => ComplianceAlert::where('alert_type', 'sanctions_match')->where('status', 'new')->count(),
                'pep'       => ComplianceAlert::where('alert_type', 'pep_match')->where('status', 'new')->count(),
            ],
            'sanctions_entries' => SanctionsEntry::where('active', true)->count(),
            'pep_entries'       => PepEntry::where('active', true)->count(),
            'last_synced'       => SanctionsEntry::max('last_synced_at'),
        ]);
    }

    public function complianceAlerts(Request $request): JsonResponse
    {
        $query = ComplianceAlert::with([
            'user:id,name,email,country_code,kyc_status,status',
            'screen:id,screen_type,input_name,match_score,match_details,triggered_by,screened_at',
        ]);

        if ($request->filled('status'))     $query->where('status', $request->status);
        if ($request->filled('alert_type')) $query->where('alert_type', $request->alert_type);
        if ($request->filled('severity'))   $query->where('severity', $request->severity);

        return response()->json(
            $query->orderByRaw("FIELD(severity, 'critical', 'high', 'medium', 'low')")
                ->orderByDesc('created_at')
                ->paginate(25)
        );
    }

    public function complianceAlertShow(int $id): JsonResponse
    {
        $alert = ComplianceAlert::with([
            'user:id,name,email,country_code,kyc_status,tier,status,created_at',
            'screen',
        ])->findOrFail($id);

        $matchedEntry = null;
        if ($alert->screen?->sanctions_entry_id) {
            $matchedEntry = SanctionsEntry::find(
                $alert->screen->sanctions_entry_id,
                ['id', 'name', 'aliases', 'country_codes', 'date_of_birth', 'source', 'list_reference']
            );
        } elseif ($alert->screen?->pep_entry_id) {
            $matchedEntry = PepEntry::find(
                $alert->screen->pep_entry_id,
                ['id', 'name', 'aliases', 'country_code', 'position', 'risk_level', 'source']
            );
        }

        return response()->json(['alert' => $alert, 'matched_entry' => $matchedEntry]);
    }

    public function complianceAlertReview(Request $request, int $id): JsonResponse
    {
        $alert = ComplianceAlert::findOrFail($id);

        if ($alert->status !== 'new') {
            return response()->json(['message' => 'Alert is not in new status.'], 422);
        }

        $alert->update(['status' => 'reviewing', 'reviewed_by' => $request->user()->id]);

        return response()->json(['message' => 'Alert marked as under review.']);
    }

    public function complianceAlertClear(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:1000']);
        $alert = ComplianceAlert::findOrFail($id);

        if (! in_array($alert->status, ['new', 'reviewing'])) {
            return response()->json(['message' => 'Alert is already resolved.'], 422);
        }

        $alert->update([
            'status'           => 'cleared',
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'resolution_notes' => $request->notes,
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.compliance.cleared',
            'entity_type' => 'ComplianceAlert',
            'entity_id'   => $alert->id,
            'new_values'  => ['notes' => $request->notes],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Alert cleared.']);
    }

    public function complianceAlertConfirm(Request $request, int $id): JsonResponse
    {
        $request->validate(['notes' => 'required|string|max:1000']);
        $alert = ComplianceAlert::findOrFail($id);

        if (! in_array($alert->status, ['new', 'reviewing'])) {
            return response()->json(['message' => 'Alert is already resolved.'], 422);
        }

        $alert->update([
            'status'           => 'confirmed',
            'reviewed_by'      => $request->user()->id,
            'reviewed_at'      => now(),
            'resolution_notes' => $request->notes,
        ]);

        $user = User::find($alert->user_id);
        if ($user && $user->status !== 'suspended') {
            $user->update(['status' => 'suspended']);
        }

        Wallet::where('user_id', $alert->user_id)->where('status', 'active')->update(['status' => 'frozen']);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.compliance.confirmed',
            'entity_type' => 'ComplianceAlert',
            'entity_id'   => $alert->id,
            'new_values'  => ['notes' => $request->notes, 'user_suspended' => true],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json(['message' => 'Match confirmed. User suspended and wallets frozen.']);
    }
}
