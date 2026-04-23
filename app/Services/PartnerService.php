<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Partner;
use App\Models\DisbursementAttempt;
use App\Services\Contracts\PartnerInterface;
use App\Services\Partners\PawapayPartner;
use App\Services\Partners\MtnMomoPartner;
use Illuminate\Support\Facades\Log;

class PartnerService
{
    /**
     * Resolve the best available partner for this transaction's corridor
     * then execute the disbursement.
     *
     * Routing priority:
     *   1. PawaPay  — preferred for all corridors it supports
     *   2. MTN MoMo — fallback for currencies PawaPay does not cover
     *
     * Never throws for partner API failures.
     * Only throws for unrecoverable configuration errors.
     */
    public function disburse(Transaction $transaction): PartnerResult
    {
        $partner = $this->resolvePartner(
            $transaction->send_currency,
            $transaction->receive_currency
        );

        if (!$partner) {
            throw new \RuntimeException(
                "No active partner available for corridor: " .
                "{$transaction->send_currency}-{$transaction->receive_currency}"
            );
        }

        // Update transaction with resolved partner
        $transaction->update(['partner_id' => $this->getPartnerModel($partner)->id]);

        Log::info('Disbursing via partner', [
            'reference' => $transaction->reference_number,
            'partner'   => get_class($partner),
            'corridor'  => "{$transaction->send_currency}-{$transaction->receive_currency}",
        ]);

        $result = $partner->disburse($transaction);

        // Record the attempt regardless of outcome
        DisbursementAttempt::create([
            'transaction_id'   => $transaction->id,
            'partner_id'       => $transaction->partner_id,
            'attempt_number'   => $transaction->disbursement_attempts,
            'request_payload'  => [
                'reference'        => $transaction->reference_number,
                'receive_amount'   => $transaction->receive_amount,
                'receive_currency' => $transaction->receive_currency,
                'recipient_id'     => $transaction->recipient_id,
            ],
            'response_payload' => $result->rawResponse,
            'status'           => $result->success ? 'success' : 'failed',
            'response_time_ms' => $result->responseTimeMs,
            'failure_reason'   => $result->failureReason,
            'attempted_at'     => now(),
            'responded_at'     => now(),
        ]);

        // Update partner success rate
        $this->updatePartnerMetrics(
            $this->getPartnerModel($partner),
            $result->success,
            $result->responseTimeMs
        );

        return $result;
    }

    /**
     * Check status of an ambiguous disbursement.
     * Called when a previous attempt timed out and we don't know if it succeeded.
     */
    public function checkStatus(Transaction $transaction): PartnerResult
    {
        if (empty($transaction->partner_reference)) {
            throw new \RuntimeException(
                "Cannot check status — no partner reference on transaction: " .
                $transaction->reference_number
            );
        }

        $partner = $this->resolvePartner(
            $transaction->send_currency,
            $transaction->receive_currency
        );

        if (!$partner) {
            throw new \RuntimeException("No partner available for status check.");
        }

        return $partner->checkStatus($transaction->partner_reference);
    }

    /**
     * Resolve the highest-priority active partner for a corridor.
     *
     * The partner_corridors.priority column controls preference:
     *   priority 1 = PawaPay (preferred)
     *   priority 2 = MTN MoMo (fallback)
     *
     * Lower number = higher priority (ordered ASC).
     */
    private function resolvePartner(
        string $fromCurrency,
        string $toCurrency
    ): ?PartnerInterface {
        $partners = Partner::whereHas('corridors', function ($q) use ($fromCurrency, $toCurrency) {
                $q->where('from_currency', $fromCurrency)
                  ->where('to_currency', $toCurrency)
                  ->where('is_active', true);
            })
            ->where('is_active', true)
            ->orderByRaw("
                (SELECT MIN(priority) FROM partner_corridors
                 WHERE partner_corridors.partner_id = partners.id
                 AND from_currency = ?
                 AND to_currency = ?
                 AND is_active = 1) ASC
            ", [$fromCurrency, $toCurrency])
            ->get();

        foreach ($partners as $partnerModel) {
            $instance = $this->instantiatePartner($partnerModel->code);

            if ($instance && $instance->supports($fromCurrency, $toCurrency)) {
                return $instance;
            }
        }

        return null;
    }

    private function instantiatePartner(string $code): ?PartnerInterface
    {
        return match($code) {
            'PAWAPAY' => new PawapayPartner(),
            'MTNMOMO' => new MtnMomoPartner(),
            default   => null,
        };
    }

    private function getAllActivePartners(): array
    {
        $instances = [];

        $partners = Partner::where('is_active', true)->get();

        foreach ($partners as $partner) {
            $instance = $this->instantiatePartner($partner->code);
            if ($instance) {
                $instances[] = $instance;
            }
        }

        return $instances;
    }

    private function getPartnerModel(PartnerInterface $partner): Partner
    {
        $code = match(true) {
            $partner instanceof PawapayPartner => 'PAWAPAY',
            $partner instanceof MtnMomoPartner => 'MTNMOMO',
            default => throw new \RuntimeException('Unknown partner instance'),
        };

        return Partner::where('code', $code)->firstOrFail();
    }

    private function updatePartnerMetrics(
        Partner $partner,
        bool    $success,
        int     $responseTimeMs
    ): void {
        $currentRate = $partner->success_rate;
        $newRate     = $success
            ? min(100, $currentRate + 0.1)
            : max(0,   $currentRate - 2.0);

        $currentAvg = $partner->avg_response_time_ms;
        $newAvg     = (int) (($currentAvg * 0.9) + ($responseTimeMs * 0.1));

        $partner->update([
            'success_rate'         => round($newRate, 2),
            'avg_response_time_ms' => $newAvg,
        ]);
    }
}
