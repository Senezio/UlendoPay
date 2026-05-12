<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\KycRecord;
use App\Services\KycService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class KycAdminController extends Controller
{
    public function __construct(private KycService $kycService) {}

    public function kycQueue(): JsonResponse
    {
        $records = KycRecord::with('user:id,name,email,phone_encrypted,phone_hash,country_code,tier,kyc_status,created_at')
            ->where('status', 'pending')
            ->latest()
            ->paginate(20);

        return response()->json($records);
    }

    public function kycVerified(): JsonResponse
    {
        $records = KycRecord::with('user:id,name,email,phone_encrypted,phone_hash,country_code,tier,kyc_status,created_at')
            ->whereIn('status', ['approved', 'verified'])
            ->latest('updated_at')
            ->paginate(50);

        return response()->json($records);
    }

    public function kycShow(Request $request, int $id): JsonResponse
    {
        $record = KycRecord::with('user')->findOrFail($id);

        try {
            $documentUrl = $this->kycService->getSecureUrl($record, $request->user()->id);
        } catch (\Throwable) {
            $documentUrl = null;
        }

        return response()->json([
            'record' => array_merge($record->toArray(), ['document_url' => $documentUrl]),
            'user'   => [
                'id'           => $record->user->id,
                'name'         => $record->user->name,
                'email'        => $record->user->email,
                'phone'        => $record->user->phone,
                'country_code' => $record->user->country_code,
                'kyc_status'   => $record->user->kyc_status,
                'tier'         => $record->user->tier,
                'created_at'   => $record->user->created_at,
            ],
        ]);
    }

    public function kycApprove(Request $request, int $id): JsonResponse
    {
        $record = KycRecord::findOrFail($id);

        try {
            $this->kycService->approve($record, $request->user());

            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'admin.kyc.approved',
                'entity_type' => 'KycRecord',
                'entity_id'   => $record->id,
                'ip_address'  => $request->ip(),
            ]);

            return response()->json(['message' => 'KYC approved successfully.', 'record' => $record->fresh()]);

        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function kycReject(Request $request, int $id): JsonResponse
    {
        $data   = $request->validate(['reason' => 'required|string|max:500']);
        $record = KycRecord::findOrFail($id);

        try {
            $this->kycService->reject($record, $request->user(), $data['reason']);

            AuditLog::create([
                'user_id'     => $request->user()->id,
                'action'      => 'admin.kyc.rejected',
                'entity_type' => 'KycRecord',
                'entity_id'   => $record->id,
                'new_values'  => ['reason' => $data['reason']],
                'ip_address'  => $request->ip(),
            ]);

            return response()->json(['message' => 'KYC rejected.', 'record' => $record->fresh()]);

        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
