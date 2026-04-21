<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KycRecord;
use App\Services\KycService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;

class KycController extends Controller
{
    public function __construct(private readonly KycService $kycService) {}

    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
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

    public function submit(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_type'   => 'required|in:passport,national_id,drivers_license,utility_bill,voters_card,bank_statement',
            'document_number' => 'nullable|string|max:50',
            'document'        => 'required|file|mimes:jpeg,png,webp,pdf|max:15360',
            'requested_tier'  => 'nullable|in:basic,verified',
        ]);

        try {
            $record = $this->kycService->submit(
                user:           $request->user(),
                documentType:   $data['document_type'],
                documentNumber: $data['document_number'] ?? null,
                file:           $request->file('document'),
                ipAddress:      $request->ip(),
                requestedTier:  $data['requested_tier'] ?? null,
            );

            return response()->json([
                'message' => 'KYC document submitted successfully.',
                'record'  => $this->formatRecord($record),
            ], 201);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'KYC_SUBMISSION_FAILED',
            ], 422);
        }
    }

    public function document(Request $request, int $id): mixed
    {
        $token = $request->query('token');

        if (!$token) {
            return response()->json(['message' => 'Access denied.'], 403);
        }

        try {
            $payload = decrypt($token);
        } catch (\Throwable) {
            return response()->json(['message' => 'Invalid token.'], 403);
        }

        if ($payload['record_id'] !== $id || now()->timestamp > $payload['expires_at']) {
            return response()->json(['message' => 'Token invalid or expired.'], 403);
        }

        $record = KycRecord::findOrFail($id);

        if (!Storage::disk('kyc')->exists($record->file_path)) {
            return response()->json(['message' => 'Document not found.'], 404);
        }

        $mimeType = Storage::disk('kyc')->mimeType($record->file_path);
        
        // Detect the frontend origin dynamically
        $origin = $request->headers->get('origin') 
               ?? (parse_url($request->headers->get('referer'), PHP_URL_SCHEME) . '://' . parse_url($request->headers->get('referer'), PHP_URL_HOST))
               ?? '*';

        return Storage::disk('kyc')->response($record->file_path, null, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => 'inline; filename="' . basename($record->file_path) . '"',
            'X-Frame-Options' => "ALLOW-FROM $origin",
            'Content-Security-Policy' => "frame-ancestors 'self' $origin"
        ]);
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
            requested_tier  => ->requested_tier,
        ];
    }
}
