<?php

namespace App\Services;

use App\Models\User;
use App\Models\Wallet;
use App\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AccountClosureService
{
    /**
     * Validate that the account is eligible for closure.
     * Returns an array of blocking reasons, or empty array if eligible.
     */
    public function validate(User $user): array
    {
        $reasons = [];

        // Check for non-zero wallet balances
        $wallets = Wallet::where('user_id', $user->id)->get();
        foreach ($wallets as $wallet) {
            if (bccomp((string) $wallet->balance, '0', 6) > 0) {
                $reasons[] = "Your {$wallet->currency_code} wallet has a balance of {$wallet->balance}. Please withdraw all funds before closing your account.";
            }
        }

        // Check for in-flight transactions
        $openTransactions = DB::table('transactions')
            ->where('sender_id', $user->id)
            ->whereIn('status', ['initiated', 'escrowed', 'processing', 'retrying', 'refund_pending', 'pending_claim'])
            ->count();

        if ($openTransactions > 0) {
            $reasons[] = "You have {$openTransactions} transaction(s) still in progress. Please wait for them to complete or be refunded before closing your account.";
        }

        // Check for pending KYC
        $pendingKyc = DB::table('kyc_records')
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        if ($pendingKyc > 0) {
            $reasons[] = "You have a KYC application under review. Please wait for the outcome before closing your account.";
        }

        // Check for pending top-ups
        $pendingTopUps = DB::table('top_ups')
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->count();

        if ($pendingTopUps > 0) {
            $reasons[] = "You have a pending top-up. Please wait for it to complete before closing your account.";
        }

        return $reasons;
    }

    /**
     * Close the account.
     * Anonymizes personal data, closes wallets, revokes tokens.
     * Financial history is preserved for regulatory compliance.
     */
    public function close(User $user, string $reason, string $ipAddress = ''): void
    {
        DB::transaction(function () use ($user, $reason, $ipAddress) {

            // 1. Final validation inside transaction
            $blockingReasons = $this->validate($user);
            if (!empty($blockingReasons)) {
                throw new \RuntimeException(implode(' ', $blockingReasons));
            }

            // 2. Capture original identity for audit before anonymizing
            $originalName  = $user->name;
            $originalEmail = $user->email;

            // 3. Anonymize personal data
            // We keep a hash of the email so we can prevent re-registration
            // Financial records (journal entries, transactions) are untouched
            $anonymizedId = Str::upper(Str::random(10));
            $user->name              = "CLOSED-{$anonymizedId}";
            $user->email             = "closed-{$anonymizedId}@deleted.ulendopay.com";
            $user->password          = bcrypt(Str::random(64));
            $user->pin               = bcrypt(Str::random(64));
            $user->phone_encrypted   = null;
            $user->phone_hash        = null;
            $user->status            = 'closed';
            $user->kyc_status        = 'none';
            $user->referral_code     = null;
            $user->save();

            // 4. Close all wallets
            Wallet::where('user_id', $user->id)
                ->whereIn('status', ['active', 'frozen'])
                ->update(['status' => 'closed']);

            // 5. Revoke all API tokens
            $user->tokens()->delete();

            // 6. Revoke all OTP codes
            DB::table('otp_codes')
                ->where('user_id', $user->id)
                ->delete();

            // 7. Revoke 2FA
            DB::table('two_factor_auth')
                ->where('user_id', $user->id)
                ->delete();

            // 8. Audit log - use original identity before anonymization
            AuditLog::create([
                'user_id'     => $user->id,
                'action'      => 'account.closed',
                'entity_type' => 'User',
                'entity_id'   => $user->id,
                'old_values'  => [
                    'name'   => $originalName,
                    'email'  => $originalEmail,
                    'status' => 'active',
                ],
                'new_values'  => [
                    'status' => 'closed',
                    'reason' => $reason,
                ],
                'ip_address' => $ipAddress,
            ]);

            Log::info('Account closed', [
                'user_id'      => $user->id,
                'anonymized_id' => $anonymizedId,
                'reason'       => $reason,
                'ip'           => $ipAddress,
            ]);
        });
    }
}
