<?php

namespace App\Services;

use App\Models\FraudAlert;
use App\Models\Transaction;
use App\Models\Recipient;
use App\Models\User;
use Illuminate\Support\Carbon;

class FraudDetectionService
{
    // Score threshold above which transaction is flagged
    const FLAG_THRESHOLD = 70;

    // Rule scores
    const SCORE_VELOCITY        = 40;
    const SCORE_DAILY_LIMIT     = 70;
    const SCORE_UNUSUAL_HOURS   = 20;
    const SCORE_RECIPIENT_MULTI = 30;

    // Thresholds
    const VELOCITY_MAX_TXN      = 3;
    const VELOCITY_WINDOW_MINS  = 10;
    const DAILY_LIMIT           = 1000000;
    const UNUSUAL_HOUR_START    = 0;
    const UNUSUAL_HOUR_END      = 5;
    const RECIPIENT_SENDER_MAX  = 5;

    /**
     * Analyse a pending transaction for fraud signals.
     * Returns array of triggered rules and total risk score.
     * Does NOT block — caller decides what to do with score.
     */
    public function analyse(
        User      $sender,
        Recipient $recipient,
        float     $sendAmount,
        string    $sendCurrency
    ): array {

        $triggeredRules = [];
        $totalScore     = 0;
        $now            = Carbon::now('Africa/Blantyre');

        // Rule 1: Velocity
        $recentCount = Transaction::where('sender_id', $sender->id)
            ->where('created_at', '>=', $now->copy()->subMinutes(self::VELOCITY_WINDOW_MINS))
            ->whereNotIn('status', ['refunded', 'failed'])
            ->count();

        if ($recentCount >= self::VELOCITY_MAX_TXN) {
            $triggeredRules[] = [
                'rule'    => 'velocity',
                'detail'  => "{$recentCount} transactions in last " . self::VELOCITY_WINDOW_MINS . " minutes",
                'score'   => self::SCORE_VELOCITY,
            ];
            $totalScore += self::SCORE_VELOCITY;
        }

        // Rule 2: Daily limit
        $dailyTotal = Transaction::where('sender_id', $sender->id)
            ->where('send_currency', $sendCurrency)
            ->whereDate('created_at', $now->toDateString())
            ->whereNotIn('status', ['refunded', 'failed'])
            ->sum('send_amount');

        if (($dailyTotal + $sendAmount) > self::DAILY_LIMIT) {
            $triggeredRules[] = [
                'rule'    => 'daily_limit',
                'detail'  => "Daily total would reach " . ($dailyTotal + $sendAmount) . " {$sendCurrency}, limit is " . self::DAILY_LIMIT,
                'score'   => self::SCORE_DAILY_LIMIT,
            ];
            $totalScore += self::SCORE_DAILY_LIMIT;
        }

        // Rule 3: Unusual hours
        $hour = (int) $now->format('G');
        if ($hour >= self::UNUSUAL_HOUR_START && $hour < self::UNUSUAL_HOUR_END) {
            $triggeredRules[] = [
                'rule'    => 'unusual_hours',
                'detail'  => "Transaction initiated at {$now->format('H:i')} Blantyre time",
                'score'   => self::SCORE_UNUSUAL_HOURS,
            ];
            $totalScore += self::SCORE_UNUSUAL_HOURS;
        }

        // Rule 4: Recipient receiving from multiple senders
        $senderCount = Transaction::where('recipient_id', $recipient->id)
            ->where('created_at', '>=', $now->copy()->subHours(24))
            ->whereNotIn('status', ['refunded', 'failed'])
            ->distinct('sender_id')
            ->count('sender_id');

        if ($senderCount >= self::RECIPIENT_SENDER_MAX) {
            $triggeredRules[] = [
                'rule'    => 'recipient_multi_sender',
                'detail'  => "Recipient received from {$senderCount} different senders in last 24 hours",
                'score'   => self::SCORE_RECIPIENT_MULTI,
            ];
            $totalScore += self::SCORE_RECIPIENT_MULTI;
        }

        return [
            'score'          => $totalScore,
            'flagged'        => $totalScore >= self::FLAG_THRESHOLD,
            'triggered_rules'=> $triggeredRules,
        ];
    }

    /**
     * Persist a FraudAlert record for admin review.
     */
    public function createAlert(
        Transaction $transaction,
        array       $analysis
    ): FraudAlert {

        return FraudAlert::create([
            'transaction_id'  => $transaction->id,
            'user_id'         => $transaction->sender_id,
            'rule_triggered'  => implode(', ', array_column($analysis['triggered_rules'], 'rule')),
            'risk_score'      => $analysis['score'],
            'context'         => $analysis['triggered_rules'],
            'status'          => 'new',
        ]);
    }
}
