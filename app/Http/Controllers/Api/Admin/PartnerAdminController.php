<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DisbursementAttempt;
use App\Models\Partner;
use App\Models\PartnerCorridor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartnerAdminController extends Controller
{
    public function partners(): JsonResponse
    {
        $partners = Partner::with('corridors')->get()->map(fn($partner) => [
            'id'                   => $partner->id,
            'name'                 => $partner->name,
            'code'                 => $partner->code,
            'type'                 => $partner->type,
            'country_code'         => $partner->country_code,
            'is_active'            => $partner->is_active,
            'success_rate'         => $partner->success_rate,
            'avg_response_time_ms' => $partner->avg_response_time_ms,
            'timeout_seconds'      => $partner->timeout_seconds,
            'max_retries'          => $partner->max_retries,
            'corridors'            => $partner->corridors->map(fn($c) => [
                'id'            => $c->id,
                'from_currency' => $c->from_currency,
                'to_currency'   => $c->to_currency,
                'min_amount'    => $c->min_amount,
                'max_amount'    => $c->max_amount,
                'fee_percent'   => $c->fee_percent,
                'fee_flat'      => $c->fee_flat,
                'priority'      => $c->priority,
                'is_active'     => $c->is_active,
            ]),
        ]);

        return response()->json(['partners' => $partners]);
    }

    public function partnerHealth(): JsonResponse
    {
        $stats = Partner::with('corridors')->get()->map(function ($partner) {
            $attempts = DisbursementAttempt::where('partner_id', $partner->id);

            $total   = (clone $attempts)->count();
            $success = (clone $attempts)->where('status', 'success')->count();
            $failed  = (clone $attempts)->where('status', 'failed')->count();
            $pending = (clone $attempts)->where('status', 'pending')->count();
            $avgMs   = (clone $attempts)->whereNotNull('response_time_ms')->avg('response_time_ms');

            $recent = (clone $attempts)->with('transaction:id,reference_number,status')
                ->latest('attempted_at')->limit(5)->get()
                ->map(fn($a) => [
                    'reference'        => $a->transaction?->reference_number,
                    'status'           => $a->status,
                    'response_time_ms' => $a->response_time_ms,
                    'failure_reason'   => $a->failure_reason,
                    'attempted_at'     => $a->attempted_at,
                ]);

            return [
                'id'           => $partner->id,
                'name'         => $partner->name,
                'code'         => $partner->code,
                'is_active'    => $partner->is_active,
                'total'        => $total,
                'success'      => $success,
                'failed'       => $failed,
                'pending'      => $pending,
                'success_rate' => $total > 0 ? round(($success / $total) * 100, 1) : null,
                'avg_ms'       => $avgMs ? round($avgMs) : null,
                'recent'       => $recent,
            ];
        });

        return response()->json(['partners' => $stats]);
    }

    public function partnerToggle(Request $request, int $id): JsonResponse
    {
        $partner = Partner::findOrFail($id);
        $partner->update(['is_active' => ! $partner->is_active]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => $partner->is_active ? 'partner.enabled' : 'partner.disabled',
            'entity_type' => 'Partner',
            'entity_id'   => $partner->id,
            'new_values'  => ['is_active' => $partner->is_active],
        ]);

        return response()->json(['message' => 'Partner updated.', 'is_active' => $partner->is_active]);
    }

    public function corridorUpdate(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'fee_percent' => 'sometimes|numeric|min:0|max:100',
            'fee_flat'    => 'sometimes|numeric|min:0',
            'min_amount'  => 'sometimes|numeric|min:0',
            'max_amount'  => 'sometimes|numeric|min:0',
            'is_active'   => 'sometimes|boolean',
            'priority'    => 'sometimes|integer|min:1',
        ]);

        $corridor = PartnerCorridor::findOrFail($id);
        $corridor->update($data);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'corridor.updated',
            'entity_type' => 'PartnerCorridor',
            'entity_id'   => $corridor->id,
            'new_values'  => $data,
        ]);

        return response()->json(['message' => 'Corridor updated.', 'corridor' => $corridor->fresh()]);
    }

    public function corridorToggle(Request $request, int $id): JsonResponse
    {
        $corridor = PartnerCorridor::with('partner')->findOrFail($id);
        $corridor->update(['is_active' => ! $corridor->is_active]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => $corridor->is_active ? 'corridor.enabled' : 'corridor.disabled',
            'entity_type' => 'PartnerCorridor',
            'entity_id'   => $corridor->id,
            'new_values'  => ['is_active' => $corridor->is_active],
        ]);

        return response()->json(['message' => 'Corridor updated.', 'is_active' => $corridor->is_active]);
    }
}
