<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\ExchangeRate;
use App\Models\OutboxEvent;
use App\Models\Partner;
use App\Models\PartnerCorridor;
use App\Models\PendingClaim;
use App\Models\RateLock;
use App\Models\Recipient;
use App\Models\Transaction;
use App\Models\TransferTier;
use App\Models\User;
use App\Services\Outbox\OutboxProcessor;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OutboxProcessorTest extends TestCase
{
    use RefreshDatabase;

    private OutboxProcessor $processor;
    private TransactionService $transactions;
    private User $sender;
    private Account $senderAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor    = app(OutboxProcessor::class);
        $this->transactions = app(TransactionService::class);

        TransferTier::create([
            'name'                  => 'basic',
            'level'                 => 1,
            'label'                 => 'Basic',
            'daily_limit'           => 999999999,
            'monthly_limit'         => 999999999,
            'per_transaction_limit' => 999999999,
            'fee_discount_percent'  => 0,
            'limit_currency'        => 'MWK',
            'is_active'             => true,
        ]);

        $this->sender = User::create([
            'name'         => 'Outbox Sender',
            'email'        => 'outbox-sender@test.com',
            'password'     => bcrypt('password'),
            'country_code' => 'MWI',
            'kyc_status'   => 'verified',
            'status'       => 'active',
            'tier'         => 'basic',
        ]);
        $this->sender->phone = '+265991100001';
        $this->sender->pin   = '1234';
        $this->sender->save();

        $this->senderAccount = Account::create([
            'owner_id'       => $this->sender->id,
            'owner_type'     => User::class,
            'type'           => 'user_wallet',
            'currency_code'  => 'MWK',
            'code'           => 'OUTBOX-SENDER-MWK',
            'normal_balance' => 'credit',
            'is_active'      => true,
        ]);

        AccountBalance::create([
            'account_id'    => $this->senderAccount->id,
            'balance'       => 500000,
            'currency_code' => 'MWK',
        ]);
    }

    private function makeEscrowedTransaction(): Transaction
    {
        Account::firstOrCreate(['type' => 'escrow', 'currency_code' => 'MWK'], [
            'owner_id' => null, 'owner_type' => null,
            'code' => 'ESCROW-MWK', 'normal_balance' => 'credit', 'is_active' => true,
        ]);
        Account::firstOrCreate(['type' => 'fee', 'currency_code' => 'MWK'], [
            'owner_id' => null, 'owner_type' => null,
            'code' => 'FEE-MWK', 'normal_balance' => 'credit', 'is_active' => true,
        ]);
        Account::firstOrCreate(['type' => 'guarantee', 'currency_code' => 'MWK', 'corridor' => 'MWK-ZMW'], [
            'owner_id' => null, 'owner_type' => null,
            'code' => 'GUAR-MWK-ZMW', 'normal_balance' => 'credit', 'is_active' => true,
        ]);
        Account::firstOrCreate(['code' => 'MWK-POOL'], [
            'owner_id' => null, 'owner_type' => null,
            'type' => 'system', 'currency_code' => 'MWK',
            'normal_balance' => 'credit', 'is_active' => true,
        ]);

        $partner = Partner::create([
            'name' => 'Test', 'code' => 'TEST',
            'type' => 'mobile_money', 'country_code' => 'MWI', 'is_active' => true,
        ]);
        PartnerCorridor::create([
            'partner_id'        => $partner->id,
            'from_currency'     => 'MWK',
            'to_currency'       => 'ZMW',
            'fee_percent'       => 1.0,
            'fee_flat'          => 0,
            'guarantee_percent' => 0.005,
            'min_amount'        => 0,
            'max_amount'        => 9999999,
            'priority'          => 1,
            'is_active'         => true,
        ]);

        $rate = ExchangeRate::create([
            'from_currency' => 'MWK', 'to_currency' => 'ZMW',
            'rate' => 0.005, 'inverse_rate' => 200,
            'source' => 'SYSTEM', 'is_active' => true,
            'fetched_at' => now(), 'expires_at' => now()->addHours(24),
        ]);

        $rateLock = RateLock::create([
            'user_id'           => $this->sender->id,
            'exchange_rate_id'  => $rate->id,
            'from_currency'     => 'MWK',
            'to_currency'       => 'ZMW',
            'locked_rate'       => 0.005,
            'fee_percent'       => 1.0,
            'fee_flat'          => 0,
            'guarantee_percent' => 0.005,
            'status'            => 'active',
            'expires_at'        => now()->addMinutes(15),
        ]);

        $recipient = Recipient::create([
            'user_id'        => $this->sender->id,
            'full_name'      => 'ZMW Recipient',
            'mobile_number'  => '+260971200001',
            'country_code'   => 'ZMB',
            'payment_method' => 'mobile_money',
            'is_active'      => true,
        ]);

        return $this->transactions->initiate(
            'outbox-test-' . uniqid(),
            $this->sender,
            $recipient,
            $rateLock,
            100000
        );
    }

    #[Test]
    public function refund_handler_processes_refund_requested_event(): void
    {
        $transaction = $this->makeEscrowedTransaction();
        $transaction->update(['status' => 'failed']);

        $event = OutboxEvent::create([
            'event_type'     => 'refund_requested',
            'transaction_id' => $transaction->id,
            'payload'        => ['transaction_id' => $transaction->id],
            'status'         => 'pending',
            'max_attempts'   => 3,
        ]);

        $this->processor->process($event);

        $transaction->refresh();
        $this->assertEquals('refunded', $transaction->status);
        $this->assertNotNull($transaction->refunded_at);

        $balance = AccountBalance::where('account_id', $this->senderAccount->id)->value('balance');
        $this->assertEquals(500000, $balance);
    }

    #[Test]
    public function failed_event_increments_attempts_and_schedules_retry(): void
    {
        $event = OutboxEvent::create([
            'event_type'   => 'refund_requested',
            'payload'      => ['transaction_id' => 999999],
            'status'       => 'pending',
            'max_attempts' => 3,
            'attempts'     => 0,
        ]);

        $this->processor->process($event);

        $event->refresh();
        $this->assertEquals('pending', $event->status);
        $this->assertEquals(1, $event->attempts);
        $this->assertNotNull($event->next_attempt_at);
    }

    #[Test]
    public function event_marked_failed_after_max_attempts_exhausted(): void
    {
        $event = OutboxEvent::create([
            'event_type'   => 'refund_requested',
            'payload'      => ['transaction_id' => 999999],
            'status'       => 'pending',
            'max_attempts' => 1,
            'attempts'     => 0,
        ]);

        $this->processor->process($event);

        $event->refresh();
        $this->assertEquals('failed', $event->status);
        $this->assertEquals(1, $event->attempts);
    }

    #[Test]
    public function internal_settlement_completes_cross_currency_transaction(): void
    {
        $transaction = $this->makeEscrowedTransaction();
        $this->assertEquals('escrowed', $transaction->status);

        Account::firstOrCreate(['code' => 'ZMW-POOL'], [
            'owner_id' => null, 'owner_type' => null,
            'type' => 'system', 'currency_code' => 'ZMW',
            'normal_balance' => 'credit', 'is_active' => true,
        ]);
        AccountBalance::firstOrCreate(
            ['account_id' => Account::where('code', 'ZMW-POOL')->first()->id],
            ['balance' => 999999, 'currency_code' => 'ZMW']
        );

        $event = OutboxEvent::where('event_type', 'internal_settlement')
            ->where('transaction_id', $transaction->id)
            ->firstOrFail();

        $this->processor->process($event);

        $transaction->refresh();
        $this->assertEquals('completed', $transaction->status);
        $this->assertNotNull($transaction->completed_at);
    }

    #[Test]
    public function rate_fetch_handler_fetches_and_stores_exchange_rates(): void
    {
        $event = OutboxEvent::create([
            'event_type'   => 'rate_fetch_requested',
            'payload'      => [],
            'status'       => 'pending',
            'max_attempts' => 3,
        ]);

        $this->processor->process($event);

        $event->refresh();
        $this->assertEquals('completed', $event->status);
        $this->assertNotNull($event->processed_at);
        $this->assertGreaterThan(0, ExchangeRate::where('source', 'FOREXRATEAPI')->count());
    }
}
