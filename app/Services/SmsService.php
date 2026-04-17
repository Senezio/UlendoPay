<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use App\Models\Recipient;
use Illuminate\Support\Facades\Log;
use AfricasTalking\SDK\AfricasTalking;

class SmsService
{
    private \AfricasTalking\SDK\SMS $sms;
    private string $from;

    public function __construct()
    {
        $username   = config("services.africastalking.username");
        $apiKey     = config("services.africastalking.api_key");
        $this->from = config("services.africastalking.from", "UlendoPay");

        if (empty($username) || empty($apiKey)) {
            throw new \RuntimeException(
                "Africa Talking credentials are not configured."
            );
        }

        $at        = new AfricasTalking($username, $apiKey);
        $this->sms = $at->sms();
    }

    public function send(array $payload): void
{
    $type = $payload["type"] ?? null;

    if (!$type) {
        Log::warning("SMS payload missing type", $payload);
        return;
    }

    match ($type) {
        "transfer_completed" => $this->sendTransferCompleted($payload),
        "transfer_refunded"  => $this->sendTransferRefunded($payload),
        "transfer_failed"    => $this->sendTransferFailed($payload),
        "otp"                => $this->sendOtp($payload),
        "kyc_approved"       => $this->sendKycApproved($payload),
        "kyc_rejected"       => $this->sendKycRejected($payload),
        "topup_completed"    => $this->sendTopupCompleted($payload),
        "topup_failed"       => $this->sendTopupFailed($payload),
        "transfer_sent"      => $this->sendTransferSent($payload),
        "transfer_received"  => $this->sendTransferReceived($payload),
        "transfer_held"      => $this->sendTransferHeld($payload),
        "pending_claim"      => $this->sendPendingClaim($payload),
        "claim_released"     => $this->sendClaimReleased($payload),
        "claim_expired_refund" => $this->sendClaimExpiredRefund($payload),

        //  add withdrawal handlers
        "withdrawal_completed" => $this->sendWithdrawalCompleted($payload),
        "withdrawal_failed"    => $this->sendWithdrawalFailed($payload),

        default => Log::warning("Unknown SMS type ignored", [
            "type" => $type,
            "payload" => $payload,
        ]),
    };
}

private function sendWithdrawalCompleted(array $payload): void
{
    $amount = number_format((float)($payload["amount"] ?? 0), 2);

    $this->dispatch(
        phone: $this->formatPhone($payload["phone"], $payload["country_code"] ?? "MWI"),
        message: "UlendoPay: Your withdrawal of {$amount} {$payload["currency"]} has been completed. Ref: {$payload["reference"]}",
        context: "withdrawal_completed:{$payload["reference"]}"
    );
}

private function sendWithdrawalFailed(array $payload): void
{
    $amount = number_format((float)($payload["amount"] ?? 0), 2);
    $reason = $payload["reason"] ?? "Transaction failed";

    $this->dispatch(
        phone: $this->formatPhone($payload["phone"], $payload["country_code"] ?? "MWI"),
        message: "UlendoPay: Withdrawal of {$amount} {$payload["currency"]} failed. Reason: {$reason}. Ref: {$payload["reference"]}",
        context: "withdrawal_failed:{$payload["reference"]}"
    );
}
    public function sendOtp(array $payload): void
    {
        if (empty($payload["phone"]) || empty($payload["otp"]) || empty($payload["country_code"])) {
            throw new \RuntimeException("OTP missing phone, otp, or country_code");
        }

        $formattedPhone = $this->formatPhone($payload["phone"], $payload["country_code"]);

        $this->dispatch(
            phone:   $formattedPhone,
            message: "Your UlendoPay verification code is: {$payload["otp"]}. Valid for 10 minutes. Do not share this code.",
            context: "otp:{$formattedPhone}"
        );
    }

    private function sendTransferCompleted(array $payload): void
    {
        $transaction = Transaction::with(["sender", "recipient"])->findOrFail($payload["transaction_id"]);
        
        $this->dispatch(
            phone:   $this->resolveSenderPhone($transaction->sender),
            message: $this->formatSenderCompletedMessage($transaction),
            context: "transfer_completed:sender:{$transaction->reference_number}"
        );

        $recipient = $transaction->recipient;
        $recipientPhone = $recipient->mobile_number ?? $recipient->phone ?? null;

        if ($recipientPhone) {
            $this->dispatch(
                phone:   $this->formatPhone($recipientPhone, $recipient->country_code),
                message: $this->formatRecipientCompletedMessage($transaction),
                context: "transfer_completed:recipient:{$transaction->reference_number}"
            );
        }
    }

    private function sendTransferRefunded(array $payload): void
    {
        $transaction = Transaction::with("sender")->findOrFail($payload["transaction_id"]);
        $this->dispatch(
            phone:   $this->resolveSenderPhone($transaction->sender),
            message: $this->formatRefundedMessage($transaction),
            context: "transfer_refunded:{$transaction->reference_number}"
        );
    }

    private function sendTransferFailed(array $payload): void
    {
        $transaction = Transaction::with("sender")->findOrFail($payload["transaction_id"]);
        $this->dispatch(
            phone:   $this->resolveSenderPhone($transaction->sender),
            message: $this->formatFailedMessage($transaction),
            context: "transfer_failed:{$transaction->reference_number}"
        );
    }

    private function sendKycApproved(array $payload): void
    {
        $this->dispatch(
            phone:   $this->formatPhone($payload["phone"], $payload["country_code"] ?? "MWI"),
            message: "UlendoPay: Your identity verification has been approved.",
            context: "kyc_approved:{$payload["user_id"]}"
        );
    }

    private function sendKycRejected(array $payload): void
    {
        $reason = $payload["reason"] ?? "Document unclear.";
        $this->dispatch(
            phone:   $this->formatPhone($payload["phone"], $payload["country_code"] ?? "MWI"),
            message: "UlendoPay: KYC unsuccessful. Reason: {$reason}",
            context: "kyc_rejected:{$payload["user_id"]}"
        );
    }

    private function sendTopupCompleted(array $payload): void
    {
        $amount = number_format((float)($payload["amount"] ?? 0), 2);
        $this->dispatch(
            phone:   $this->formatPhone($payload["phone"], $payload["country_code"] ?? "MWI"),
            message: "UlendoPay: Wallet topped up with {$amount} {$payload["currency"]}. Ref: {$payload["reference"]}",
            context: "topup_completed:{$payload["reference"]}"
        );
    }

    private function sendTopupFailed(array $payload): void
    {
        $amount = number_format((float)($payload["amount"] ?? 0), 2);
        $this->dispatch(
            phone:   $this->formatPhone($payload["phone"], $payload["country_code"] ?? "MWI"),
            message: "UlendoPay: Topup of {$amount} failed. Ref: {$payload["reference"]}",
            context: "topup_failed:{$payload["reference"]}"
        );
    }

    private function sendTransferSent(array $payload): void
    {
        $amount = number_format((float)($payload["amount"] ?? 0), 2);
        $this->dispatch(
            phone:   $this->formatPhone($payload["phone"], null),
            message: "UlendoPay: You sent {$amount} {$payload["currency"]}. Ref: {$payload["reference"]}",
            context: "transfer_sent:{$payload["reference"]}"
        );
    }

    private function sendTransferReceived(array $payload): void
    {
        $amount = number_format((float)($payload["amount"] ?? 0), 2);
        $this->dispatch(
            phone:   $this->formatPhone($payload["phone"], null),
            message: "UlendoPay: You received {$amount} {$payload["currency"]}. Ref: {$payload["reference"]}",
            context: "transfer_received:{$payload["reference"]}"
        );
    }

    private function sendTransferHeld(array $payload): void
    {
        $amount = number_format((float)($payload["amount"] ?? 0), 2);
        $this->dispatch(
            phone:   $this->formatPhone($payload["phone"], null),
            message: "UlendoPay: {$amount} {$payload["currency"]} sent and held for unclaimed recipient. Ref: {$payload["reference"]}. Will refund in 48hrs if unclaimed.",
            context: "transfer_held:{$payload["reference"]}"
        );
    }

    private function sendPendingClaim(array $payload): void
    {
        $amount = number_format((float)($payload["amount"] ?? 0), 2);
        $this->dispatch(
            phone:   $this->formatPhone($payload["phone"], null),
            message: "UlendoPay: You have received {$amount} {$payload["currency"]}. Create an account at ulendopay.com to claim it. Expires: {$payload["expires_at"]}. Ref: {$payload["reference"]}",
            context: "pending_claim:{$payload["reference"]}"
        );
    }

    private function sendClaimReleased(array $payload): void
    {
        $amount = number_format((float)($payload["amount"] ?? 0), 2);
        $this->dispatch(
            phone:   $this->formatPhone($payload["phone"], null),
            message: "UlendoPay: {$amount} {$payload["currency"]} has been credited to your wallet. Ref: {$payload["reference"]}",
            context: "claim_released:{$payload["reference"]}"
        );
    }

    private function sendClaimExpiredRefund(array $payload): void
    {
        $amount = number_format((float)($payload["amount"] ?? 0), 2);
        $this->dispatch(
            phone:   $this->formatPhone($payload["phone"], null),
            message: "UlendoPay: Your transfer of {$amount} {$payload["currency"]} was unclaimed and has been refunded. Ref: {$payload["reference"]}",
            context: "claim_expired_refund:{$payload["reference"]}"
        );
    }

    private function dispatch(string $phone, string $message, string $context): void
    {
        try {
            $result = $this->sms->send(["to" => $phone, "message" => $message, "from" => $this->from]);
            Log::info("SMS sent [{$context}]", ["phone" => $phone]);
        } catch (\Throwable $e) {
            Log::error("SMS dispatch failed [{$context}]", ["error" => $e->getMessage()]);
            throw $e;
        }
    }

    private function formatSenderCompletedMessage(Transaction $t): string {
        return sprintf("UlendoPay: Transfer %s sent. %s %s to %s. Ref: %s", number_format($t->send_amount, 2), number_format($t->receive_amount, 2), $t->receive_currency, $t->recipient->full_name, $t->reference_number);
    }

    private function formatRecipientCompletedMessage(Transaction $t): string {
        return sprintf("UlendoPay: Received %s %s from %s. Ref: %s", number_format($t->receive_amount, 2), $t->receive_currency, $t->sender->name ?? "a sender", $t->reference_number);
    }

    private function formatRefundedMessage(Transaction $t): string {
        return sprintf("UlendoPay: Transfer %s failed. %s %s refunded. Ref: %s", $t->reference_number, number_format($t->send_amount, 2), $t->send_currency, $t->reference_number);
    }

    private function formatFailedMessage(Transaction $t): string {
        return sprintf("UlendoPay: Transfer %s failed. Funds safe. Ref: %s", number_format($t->send_amount, 2), $t->reference_number);
    }

    private function resolveSenderPhone(User $sender): string {
        return $this->formatPhone($sender->phone, $sender->country_code);
    }

    private function formatPhone(string $phone, ?string $countryCode): string
    {
        if (str_starts_with($phone, "+")) return $phone;
        if ($countryCode === null) return $phone;
        $phone = ltrim($phone, "0");
        $dialCode = match(strtoupper($countryCode ?? "")) {
            "MWI", "MW" => "+265",
            "TZA", "TZ" => "+255",
            "KEN", "KE" => "+254",
            "ZMB", "ZM" => "+260",
            "ZAF", "ZA" => "+27",
            "MOZ", "MZ" => "+258",
            "BWA", "BW" => "+267",
            "ETH", "ET" => "+251",
            "MDG", "MG" => "+261",
            "GHA", "GH" => "+233",
            "UGA", "UG" => "+256",
            "RWA", "RW" => "+250",
            "SEN", "SN" => "+221",
            default => throw new \RuntimeException("Unknown country code: {$countryCode}"),
        };
        return $dialCode . $phone;
    }
}
