<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AccountBalance;
use App\Models\OutboxEvent;
use App\Models\PartnerCorridor;
use App\Models\Partner;
use App\Models\RateLock;
use App\Models\Recipient;
use App\Models\Transaction;
use App\Models\TransferTier;
use App\Models\User;
use App\Models\Wallet;
use App\Models\ExchangeRate;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private TransactionService $service;
    private User $sender;
    private Account $senderAccount;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(TransactionService::class);

        // Create a tier
        TransferTier::create([
            'name'              => 'basic',
            'level'             => 1,
            'daily_limit'       => 1000000,
            'monthly_limit'     => 10000000,
            'per_tx_limit'      => 500000,
            'min_tx_amount'     => 100,
            'fee_discount_percent' => 0,
            'is_active'         => true,
        ]);

        // Create sender
        $this->sender = User::create([
            'name'         => 'Test Sender',
            'email'        => 'sender@test.com',
            'password'     => bcrypt('password'),
            'country_code' => 'MWI',
            'kyc_status'   => 'verified',
            'status'       => 'active',
            'tier'         => 'basic',
        ]);
        $this->sender->phone = '+265991000001';
        $this->sender->pin   = '1234';
        $this->sender->save();

        // Create sender MWK wallet account
        $this->senderAccount = Account::create([
            'owner_id'       => $this->sender->id,
            'owner_type'     => User::class,
            'type'           => 'user_wallet',
            'currency_code'  => 'MWK',
            'code'           => 'TEST-SENDER-MWK',
            'normal_balance' => 'credit',
            'is_active'      => true,
        ]);

        // Fund sender account
        AccountBalance::create([
            'account_id'   => $this->senderAccount->id,
            'balance'      => 500000,
            'currency_code'=> 'MWK',
        ]);
    }

    // ── Helper: create a rate lock ────────────────────────────────────────────

    private function makeMwkRateLock(float $feePercent = 1.0, float $feeFlat = 0): RateLock
    {
        $rate = ExchangeRate::create([
            'from_currency' => 'MWK',
            'to_currency'   => 'MWK',
            'rate'          => 1.0,
            'inverse_rate'  => 1.0,
            'source'        => 'SYSTEM',
            'is_active'     => true,
            'fetched_at'    => now(),
            'expires_at'    => now()->addHours(24),
        ]);

        return RateLock::create([
            'user_id'           => $this->sender->id,
            'exchange_rate_id'  => $rate->id,
            'from_currency'     => 'MWK',
            'to_currency'       => 'MWK',
            'locked_rate'       => 1.0,
            'fee_percent'       => $feePercent,
            'fee_flat'          => $feeFlat,
            'guarantee_percent' => 0.0,
            'status'            => 'active',
            'expires_at'        => now()->addMinutes(15),
        ]);
    }

    private function makeCrossRateLock(): RateLock
    {
        // Create escrow, fee, guarantee accounts
        Account::create(['owner_id' => null, 'owner_type' => null, 'type' => 'escrow', 'currency_code' => 'MWK', 'code' => 'ESCROW-MWK', 'normal_balance' => 'credit', 'is_active' => true]);
        Account::create(['owner_id' => null, 'owner_type' => null, 'type' => 'fee', 'currency_code' => 'MWK', 'code' => 'FEE-MWK', 'normal_balance' => 'credit', 'is_active' => true]);
        Account::create(['owner_id' => null, 'owner_type' => null, 'type' => 'guarantee', 'currency_code' => 'MWK', 'code' => 'GUAR-MWK-ZMW', 'corridor' => 'MWK-ZMW', 'normal_balance' => 'credit', 'is_active' => true]);
        Account::create(['owner_id' => null, 'owner_type' => null, 'type' => 'system', 'currency_code' => 'MWK', 'code' => 'MWK-POOL', 'normal_balance' => 'credit', 'is_active' => true]);

        $partner = Partner::create(['name' => 'Test', 'code' => 'TEST', 'type' => 'mobile_money', 'is_active' => true]);
        PartnerCorridor::create(['partner_id' => $partner->id, 'from_currency' => 'MWK', 'to_currency' => 'ZMW', 'fee_percent' => 1.0, 'fee_flat' => 0, 'guarantee_percent' => 0.005, 'min_amount' => 0, 'max_amount' => 9999999, 'priority' => 1, 'is_active' => true]);

        $rate = ExchangeRate::create(['from_currency' => 'MWK', 'to_currency' => 'ZMW', 'rate' => 0.005, 'inverse_rate' => 200, 'source' => 'SYSTEM', 'is_active' => true, 'fetched_at' => now(), 'expires_at' => now()->addHours(24)]);

        return RateLock::create([
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
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function same_currency_transfer_to_registered_user_completes_immediately(): void
    {
        // Create recipient user with MWK wallet
        $recipientUser = User::create([
            'name' => 'Recipient', 'email' => 'recipient@test.com',
            'password' => bcrypt('pass'), 'country_code' => 'MWI',
            'kyc_status' => 'verified', 'status' => 'active', 'tier' => 'basic',
        ]);
        $recipientUser->phone = '+265991000002';
        $recipientUser->pin   = '1234';
        $recipientUser->save();

        $recipientAccount = Account::create([
            'owner_id' => $recipientUser->id, 'owner_type' => User::class,
            'type' => 'user_wallet', 'currency_code' => 'MWK',
            'code' => 'TEST-RECIP-MWK', 'normal_balance' => 'credit', 'is_active' => true,
        ]);
        AccountBalance::create(['account_id' => $recipientAccount->id, 'balance' => 0, 'currency_code' => 'MWK']);

        $recipient = Recipient::create([
            'user_id'        => $this->sender->id,
            'full_name'      => 'Recipient',
            'mobile_number'  => '+265991000002',
            'country_code'   => 'MWI',
            'currency_code'  => 'MWK',
            'payment_method' => 'mobile_money',
            'is_active'      => true,
        ]);

        $rateLock = $this->makeMwkRateLock(0, 0);

        $transaction = $this->service->initiate(
            idempotencyKey: 'test-key-001',
            sender:         $this->sender,
            recipient:      $recipient,
            rateLock:       $rateLock,
            sendAmount:     10000
        );

        $this->assertEquals('completed', $transaction->status);
        $this->assertEquals(10000, $transaction->send_amount);

        // Sender balance decreased
        $senderBalance = AccountBalance::where('account_id', $this->senderAccount->id)->value('balance');
        $this->assertEquals(490000, $senderBalance);

        // Recipient balance increased
        $recipientBalance = AccountBalance::where('account_id', $recipientAccount->id)->value('balance');
        $this->assertEquals(10000, $recipientBalance);

        // SMS notifications queued
        $this->assertEquals(2, OutboxEvent::where('event_type', 'sms_notification')->count());
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function same_currency_transfer_to_unregistered_user_creates_pending_claim(): void
    {
        $recipient = Recipient::create([
            'user_id'        => $this->sender->id,
            'full_name'      => 'Unknown Person',
            'mobile_number'  => '+265991999999',
            'country_code'   => 'MWI',
            'currency_code'  => 'MWK',
            'payment_method' => 'mobile_money',
            'is_active'      => true,
        ]);

        Account::create(['owner_id' => null, 'owner_type' => null, 'type' => 'escrow', 'currency_code' => 'MWK', 'code' => 'ESCROW-MWK', 'normal_balance' => 'credit', 'is_active' => true]);

        $rateLock = $this->makeMwkRateLock(0, 0);

        $transaction = $this->service->initiate(
            idempotencyKey: 'test-key-002',
            sender:         $this->sender,
            recipient:      $recipient,
            rateLock:       $rateLock,
            sendAmount:     5000
        );

        $this->assertEquals('pending_claim', $transaction->status);
        $this->assertDatabaseHas('pending_claims', ['transaction_id' => $transaction->id]);

        // Sender balance decreased
        $senderBalance = AccountBalance::where('account_id', $this->senderAccount->id)->value('balance');
        $this->assertEquals(495000, $senderBalance);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cross_currency_transfer_goes_to_escrowed_status(): void
    {
        $recipient = Recipient::create([
            'user_id'             => $this->sender->id,
            'full_name'           => 'ZMW Recipient',
            'mobile_number'       => '+260971000001',
            'country_code'        => 'ZMB',
            'currency_code'       => 'ZMW',
            'payment_method'      => 'mobile_money',
            'bank_account_number' => null,
            'is_active'           => true,
        ]);

        $rateLock = $this->makeCrossRateLock();

        $transaction = $this->service->initiate(
            idempotencyKey: 'test-key-003',
            sender:         $this->sender,
            recipient:      $recipient,
            rateLock:       $rateLock,
            sendAmount:     100000
        );

        $this->assertEquals('escrowed', $transaction->status);
        $this->assertEquals('MWK', $transaction->send_currency);
        $this->assertEquals('ZMW', $transaction->receive_currency);

        // Disbursement queued in outbox
        $this->assertDatabaseHas('outbox_events', [
            'event_type'     => 'internal_settlement',
            'transaction_id' => $transaction->id,
        ]);

        // Sender balance decreased by full send amount
        $senderBalance = AccountBalance::where('account_id', $this->senderAccount->id)->value('balance');
        $this->assertEquals(400000, $senderBalance);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function insufficient_balance_throws_exception(): void
    {
        $recipient = Recipient::create([
            'user_id'        => $this->sender->id,
            'full_name'      => 'Test',
            'mobile_number'  => '+265991000003',
            'country_code'   => 'MWI',
            'currency_code'  => 'MWK',
            'payment_method' => 'mobile_money',
            'is_active'      => true,
        ]);

        $rateLock = $this->makeMwkRateLock(0, 0);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Insufficient balance/');

        $this->service->initiate(
            idempotencyKey: 'test-key-004',
            sender:         $this->sender,
            recipient:      $recipient,
            rateLock:       $rateLock,
            sendAmount:     999999999
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function idempotency_returns_same_transaction_on_retry(): void
    {
        $recipientUser = User::create([
            'name' => 'Recipient2', 'email' => 'r2@test.com',
            'password' => bcrypt('pass'), 'country_code' => 'MWI',
            'kyc_status' => 'verified', 'status' => 'active', 'tier' => 'basic',
        ]);
        $recipientUser->phone = '+265991000004';
        $recipientUser->pin   = '1234';
        $recipientUser->save();

        $recipientAccount = Account::create([
            'owner_id' => $recipientUser->id, 'owner_type' => User::class,
            'type' => 'user_wallet', 'currency_code' => 'MWK',
            'code' => 'TEST-RECIP2-MWK', 'normal_balance' => 'credit', 'is_active' => true,
        ]);
        AccountBalance::create(['account_id' => $recipientAccount->id, 'balance' => 0, 'currency_code' => 'MWK']);

        $recipient = Recipient::create([
            'user_id'        => $this->sender->id,
            'full_name'      => 'Recipient2',
            'mobile_number'  => '+265991000004',
            'country_code'   => 'MWI',
            'currency_code'  => 'MWK',
            'payment_method' => 'mobile_money',
            'is_active'      => true,
        ]);

        $rateLock = $this->makeMwkRateLock(0, 0);

        $tx1 = $this->service->initiate('idem-key-001', $this->sender, $recipient, $rateLock, 5000);
        $tx2 = $this->service->initiate('idem-key-001', $this->sender, $recipient, $rateLock, 5000);

        $this->assertEquals($tx1->id, $tx2->id);

        // Balance only deducted once
        $balance = AccountBalance::where('account_id', $this->senderAccount->id)->value('balance');
        $this->assertEquals(495000, $balance);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function complete_releases_escrow_to_pool(): void
    {
        $recipient = Recipient::create([
            'user_id'        => $this->sender->id,
            'full_name'      => 'ZMW Recipient2',
            'mobile_number'  => '+260971000002',
            'country_code'   => 'ZMB',
            'currency_code'  => 'ZMW',
            'payment_method' => 'mobile_money',
            'is_active'      => true,
        ]);

        $rateLock = $this->makeCrossRateLock();

        $transaction = $this->service->initiate('test-key-005', $this->sender, $recipient, $rateLock, 100000);
        $this->assertEquals('escrowed', $transaction->status);

        $this->service->complete($transaction, 'PARTNER-REF-001');

        $transaction->refresh();
        $this->assertEquals('completed', $transaction->status);
        $this->assertEquals('PARTNER-REF-001', $transaction->partner_reference);

        // Pool account funded
        $poolAccount = Account::where('code', 'MWK-POOL')->first();
        $poolBalance = AccountBalance::where('account_id', $poolAccount->id)->value('balance');
        $this->assertGreaterThan(0, $poolBalance);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function reverse_refunds_full_amount_to_sender(): void
    {
        $recipient = Recipient::create([
            'user_id'        => $this->sender->id,
            'full_name'      => 'ZMW Recipient3',
            'mobile_number'  => '+260971000003',
            'country_code'   => 'ZMB',
            'currency_code'  => 'ZMW',
            'payment_method' => 'mobile_money',
            'is_active'      => true,
        ]);

        $rateLock = $this->makeCrossRateLock();

        $transaction = $this->service->initiate('test-key-006', $this->sender, $recipient, $rateLock, 100000);
        $this->assertEquals('escrowed', $transaction->status);

        $balanceBefore = AccountBalance::where('account_id', $this->senderAccount->id)->value('balance');

        $this->service->reverse($transaction, 'Disbursement failed after max retries');

        $transaction->refresh();
        $this->assertEquals('refunded', $transaction->status);

        // Full amount returned to sender
        $balanceAfter = AccountBalance::where('account_id', $this->senderAccount->id)->value('balance');
        $this->assertEquals(500000, $balanceAfter);
    }
}
