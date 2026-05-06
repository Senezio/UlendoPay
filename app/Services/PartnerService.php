<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\Partner;
use App\Models\DisbursementAttempt;
use App\Services\Contracts\PartnerInterface;
use App\Services\Partners\PawapayPartner;
use App\Services\Partners\MtnMomoPartner;
use App\Services\Partners\TerraPayPartner;
use Illuminate\Support\Facades\Log;

class PartnerService
{
    /**
     * @param  Transaction  $transaction
     * @return PartnerResult
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

        $transaction->update(['partner_id' => $this->getPartnerModel($partner)->id]);

        Log::info('Disbursing via partner', [
            'reference' => $transaction->reference_number,
            'partner'   => get_class($partner),
            'corridor'  => "{$transaction->send_currency}-{$transaction->receive_currency}",
        ]);

        $result = $partner->disburse($transaction);

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

        $this->updatePartnerMetrics(
            $this->getPartnerModel($partner),
            $result->success,
            $result->responseTimeMs
        );

        return $result;
    }

    /**
     * @param  Transaction  $transaction
     * @return PartnerResult
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
     * @param  string  $fromCurrency
     * @param  string  $toCurrency
     * @return PartnerInterface|null
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
            'TERRAPAY' => new TerraPayPartner(),
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
            $partner instanceof TerraPayPartner => 'TERRAPAY',
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
