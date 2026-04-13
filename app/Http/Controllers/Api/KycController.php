<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycRecord;
use App\Services\KycService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class KycController extends Controller
{
    public function __construct(private readonly KycService $kycService) {}

    /**
     * Get current KYC status and submission history.
     */
    public function status(Request $request): JsonResponse
    {
        $user    = $request->user();
        $records = KycRecord::where('user_id', $user->id)
            ->latest()
            ->get()
            ->map(fn($r) => $this->formatRecord($r));

        return response()->json([
            'kyc_status' => $user->kyc_status,
            'is_verified' => $user->isKycVerified(),
            'records'    => $records,
        ]);
    }

    /**
     * Submit a KYC document for review.
     */
    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type'   => 'required|in:passport,national_id,drivers_license,utility_bill',
            'document_number' => 'nullable|string|max:50',
            'document'        => 'required|file|mimes:jpeg,png,webp,pdf|max:15360',
        ]);

        try {
            $record = $this->kycService->submit(
                user:           $request->user(),
                documentType:   $data['document_type'],
                documentNumber: $data['document_number'] ?? null,
                file:           $request->file('document'),
                ipAddress:      $request->ip(),
            );

            return response()->json([
                'message' => 'KYC document submitted successfully. Review typically takes 1-2 business days.',
                'record'  => $this->formatRecord($record),
            ], 201);

        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'KYC_SUBMISSION_FAILED',
            ], 422);
        }
    }

    /**
     * Serve a KYC document securely via signed token.
     * Only accessible to the document owner or admins.
     */
    public function document(Request $request, int $id): mixed
    {
        $token  = $request->query('token');

        if (!$token) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        try {
            $payload = decrypt($token);
        } catch (\Throwable) {
            return response()->json(['message' => 'Invalid or expired token.'], 403);
        }

        if ($payload['record_id'] !== $id) {
            return response()->json(['message' => 'Token mismatch.'], 403);
        }

        if (now()->timestamp > $payload['expires_at']) {
            return response()->json(['message' => 'Token expired.'], 403);
        }

        $record = KycRecord::findOrFail($id);

        // Owner or staff can view the document
        $viewer = $request->user();
        if ($record->user_id !== $viewer->id && !$viewer->is_staff) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        if (!\Illuminate\Support\Facades\Storage::disk('kyc')->exists($record->file_path)) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        return \Illuminate\Support\Facades\Storage::disk('kyc')
            ->response($record->file_path);
    }

    private function formatRecord(KycRecord $record): array
    {
        return [
            'id'              => $record->id,
            'document_type'   => $record->document_type,
            'document_number' => $record->document_number,
            'status'          => $record->status,
            'rejection_reason'=> $record->rejection_reason,
            'submitted_at'    => $record->created_at,
            'reviewed_at'     => $record->reviewed_at,
        ];
    }
}
