<?php

namespace App\Services\Transfers\Contracts;

use App\Models\Transaction;
use App\Services\Transfers\TransactionContext;

/**
 * Every transfer type implements this interface.
 * The handler owns its ledger entries, outbox events,
 * and status transitions — nothing else.
 */
interface TransferHandlerInterface
{
    /**
     * Execute the transfer flow for this type.
     * Must be called inside a DB::transaction().
     * Returns the created Transaction model.
     */
    public function handle(TransactionContext $ctx): Transaction;

    /**
     * Returns true if this handler can process the given context.
     * TransactionService uses this to resolve the correct handler.
     */
    public function supports(TransactionContext $ctx): bool;
}
