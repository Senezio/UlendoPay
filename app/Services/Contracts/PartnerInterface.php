<?php

namespace App\Services\Contracts;

use App\Models\Transaction;
use App\Services\PartnerResult;

interface PartnerInterface
{
    /**
     * Disburse funds to recipient via this partner.
     * Must return a PartnerResult — never throw for API failures.
     * Only throw for unrecoverable local errors (missing config etc).
     */
    public function disburse(Transaction $transaction): PartnerResult;

    /**
     * Check the status of a previously initiated disbursement.
     * Used by the outbox processor when a response was ambiguous.
     */
    public function checkStatus(string $partnerReference): PartnerResult;

    /**
     * Returns true if this partner supports the given corridor.
     */
    public function supports(string $fromCurrency, string $toCurrency): bool;
}
