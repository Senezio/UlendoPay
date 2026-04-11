<?php

namespace App\Services;

/**
 * Normalized result from any partner API call.
 * The outbox processor only ever sees this — never raw partner responses.
 */
final class PartnerResult
{
    private function __construct(
        public readonly bool   $success,
        public readonly string $partnerReference,
        public readonly string $status,
        public readonly ?string $failureReason,
        public readonly array  $rawResponse,
        public readonly int    $responseTimeMs,
    ) {}

    public static function success(
        string $partnerReference,
        string $status,
        array  $rawResponse,
        int    $responseTimeMs,
    ): self {
        return new self(
            success: true,
            partnerReference: $partnerReference,
            status: $status,
            failureReason: null,
            rawResponse: $rawResponse,
            responseTimeMs: $responseTimeMs,
        );
    }

    public static function failure(
        string $failureReason,
        array  $rawResponse,
        int    $responseTimeMs,
        string $partnerReference = '',
    ): self {
        return new self(
            success: false,
            partnerReference: $partnerReference,
            status: 'failed',
            failureReason: $failureReason,
            rawResponse: $rawResponse,
            responseTimeMs: $responseTimeMs,
        );
    }
}
