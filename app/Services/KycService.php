<?php

namespace App\Services;

use App\Models\KycRecord;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class KycService
{
    private array $allowedMimeTypes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    private int $maxFileSizeKb = 15360; // 15MB

    /**
     * Submit a KYC document for review.
     * Stores file securely and creates a pending KYC record.
     */
    public function submit(
        User         $user,
        string       $documentType,
        ?string      $documentNumber,
        UploadedFile $file,
        string       $ipAddress = '',
        ?string      $requestedTier = null
    ): KycRecord {

        // Validate file
        $this->validateFile($file);

        // Check for existing pending or approved KYC
        $existing = KycRecord::where('user_id', $user->id)
            ->whereIn('status', ['pending', 'approved'])
            ->first();

        if ($existing?->status === 'approved') {
            $tierRank      = ['unverified' => 0, 'basic' => 1, 'verified' => 2];
            $currentRank   = $tierRank[$user->tier] ?? 0;
            $requestedRank = $tierRank[$requestedTier ?? 'verified'] ?? 0;
            
            if ($requestedRank <= $currentRank) {
                throw new \RuntimeException(
                    "Your KYC is already approved at {$user->tier} tier. Submit documents for a higher tier to upgrade."
                );
            }
        }

        if ($existing?->status === 'pending') {
            throw new \RuntimeException(
                'You already have a KYC submission under review. Please wait for the outcome.'
            );
        }

        // Store file securely
        $path = $this->storeFile($user, $documentType, $file);

        // Create KYC record
        $record = KycRecord::create([
            'user_id'         => $user->id,
            'document_type'   => $documentType,
            'document_number' => $documentNumber,
            'file_path'       => $path,
            'status'          => 'pending',
            'requested_tier'  => $requestedTier,
        ]);

        AuditLog::create([
            'user_id'     => $user->id,
            'action'      => 'kyc.submitted',
            'entity_type' => 'KycRecord',
            'entity_id'   => $record->id,
            'new_values'  => [
                'document_type' => $documentType,
                'status'        => 'pending',
            ],
            'ip_address'  => $ipAddress,
        ]);

        return $record;
    }

    /**
     * Approve a KYC submission.
     * Updates user kyc_status and tier.
     */
    public function approve(KycRecord $record, User $reviewer): KycRecord
    {
        if ($record->status !== 'pending') {
            throw new \RuntimeException(
                "Cannot approve KYC record with status: {$record->status}"
            );
        }

        $record->update([
            'status'      => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
        ]);

        $requestedTier = $record->requested_tier ?? 'verified';
        $user = $record->user;
        $user->kyc_status = 'verified';
        $user->tier       = $requestedTier;
        $user->save();

        // Sync tier with fresh instance to avoid stale cache
        app(\App\Services\TierService::class)->syncTier(\App\Models\User::find($user->id), $requestedTier);

        // Notify user via SMS
        app(SmsService::class)->send([
            'type'           => 'kyc_approved',
            'transaction_id' => null,
            'user_id'        => $record->user_id,
            'phone'          => $record->user->phone,
        ]);

        AuditLog::create([
            'user_id'     => $reviewer->id,
            'action'      => 'kyc.approved',
            'entity_type' => 'KycRecord',
            'entity_id'   => $record->id,
            'old_values'  => ['status' => 'pending'],
            'new_values'  => ['status' => 'approved'],
        ]);

        return $record->fresh();
    }

    /**
     * Reject a KYC submission with a reason.
     */
    public function reject(
        KycRecord $record,
        User      $reviewer,
        string    $reason
    ): KycRecord {

        if ($record->status !== 'pending') {
            throw new \RuntimeException(
                "Cannot reject KYC record with status: {$record->status}"
            );
        }

        $record->update([
            'status'           => 'rejected',
            'rejection_reason' => $reason,
            'reviewed_by'      => $reviewer->id,
            'reviewed_at'      => now(),
        ]);

        $record->user->update(['kyc_status' => 'rejected']);

        // Sync user tier
        app(\App\Services\TierService::class)->syncTier($record->user->fresh());

        // Notify user via SMS
        app(SmsService::class)->send([
            'type'           => 'kyc_rejected',
            'transaction_id' => null,
            'user_id'        => $record->user_id,
            'phone'          => $record->user->phone,
            'reason'         => $reason,
        ]);

        AuditLog::create([
            'user_id'     => $reviewer->id,
            'action'      => 'kyc.rejected',
            'entity_type' => 'KycRecord',
            'entity_id'   => $record->id,
            'old_values'  => ['status' => 'pending'],
            'new_values'  => ['status' => 'rejected', 'reason' => $reason],
        ]);

        return $record->fresh();
    }

    /**
     * Generate a temporary signed URL for viewing a KYC document.
     */
    public function getSecureUrl(KycRecord $record): string
    {
        if (!Storage::disk('kyc')->exists($record->file_path)) {
            throw new \RuntimeException(
                "KYC document not found for record {$record->id}"
            );
        }

        return route('kyc.document', [
            'id'    => $record->id,
            'token' => encrypt([
                'record_id'  => $record->id,
                'expires_at' => now()->addMinutes(5)->timestamp,
            ]),
        ]);
    }

    /**
     * Store file securely with a safe path.
     */
    private function storeFile(
        User         $user,
        string       $documentType,
        UploadedFile $file
    ): string {

        $extension = $file->getClientOriginalExtension();
        $filename  = Str::uuid() . '.' . $extension;
        $directory = "{$user->id}/" . now()->format('Y/m');

        $path = Storage::disk('kyc')->putFileAs(
            $directory,
            $file,
            $filename
        );

        if (!$path) {
            throw new \RuntimeException('Failed to store KYC document.');
        }

        return $path;
    }

    /**
     * Validate uploaded file strictly.
     */
    private function validateFile(UploadedFile $file): void
    {
        if (!$file->isValid()) {
            throw new \RuntimeException('File upload failed or was corrupted.');
        }

        if ($file->getSize() > $this->maxFileSizeKb * 1024) {
            throw new \RuntimeException(
                "File too large. Maximum size is {$this->maxFileSizeKb}KB."
            );
        }

        if (!in_array($file->getMimeType(), $this->allowedMimeTypes)) {
            throw new \RuntimeException(
                'Invalid file type. Accepted: JPEG, PNG, WebP, PDF.'
            );
        }
    }
}
