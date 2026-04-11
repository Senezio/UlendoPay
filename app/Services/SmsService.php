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
        $username   = config('services.africastalking.username');
        $apiKey     = config('services.africastalking.api_key');
        $this->from = config('services.africastalking.from', 'UlendoPay');

        if (empty($username) || empty($apiKey)) {
            throw new \RuntimeException(
                'Africa\'s Talking credentials are not configured. Set AT_USERNAME and AT_API_KEY in .env'
            );
        }

        $at        = new AfricasTalking($username, $apiKey);
        $this->sms = $at->sms();
    }

    public function send(array $payload): void
    {
        $type = $payload['type'] ?? null;

        if (!$type) {
            throw new \RuntimeException('SMS payload missing required field: type');
        }

        match ($type) {
            'transfer_completed' => $this->sendTransferCompleted($payload),
            'transfer_refunded'  => $this->sendTransferRefunded($payload),
            'transfer_failed'    => $this->sendTransferFailed($payload),
            'otp'                => $this->sendOtp($payload),
            'kyc_approved'       => $this->sendKycApproved($payload),
            'kyc_rejected'       => $this->sendKycRejected($payload),
            'topup_completed'    => $this->sendTopupCompleted($payload),
            'topup_failed'       => $this->sendTopupFailed($payload),
            default => throw new \RuntimeException("Unknown SMS type: {$type}"),
        };
    }

    private function sendTransferCompleted(array $payload): void
    {
        $transaction = Transaction::with(['sender', 'recipient'])
            ->findOrFail($payload['transaction_id']);

        $sender    = $transaction->sender;
        $recipient = $transaction->recipient;

        $this->dispatch(
            phone:   $this->resolveSenderPhone($sender),
            message: $this->formatSenderCompletedMessage($transaction),
            context: "transfer_completed:sender:{$transaction->reference_number}"
        );

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
        $transaction = Transaction::with('sender')->findOrFail($payload['transaction_id']);

        $this->dispatch(
            phone:   $this->resolveSenderPhone($transaction->sender),
            message: $this->formatRefundedMessage($transaction),
            context: "transfer_refunded:{$transaction->reference_number}"
        );
    }

    private function sendTransferFailed(array $payload): void
    {
        $transaction = Transaction::with('sender')->findOrFail($payload['transaction_id']);

        $this->dispatch(
            phone:   $this->resolveSenderPhone($transaction->sender),
            message: $this->formatFailedMessage($transaction),
            context: "transfer_failed:{$transaction->reference_number}"
        );
    }

    public function sendOtp(array $payload): void
    {
        if (empty($payload['phone']) || empty($payload['otp'])) {
            throw new \RuntimeException('OTP payload missing required fields: phone, otp');
        }

        $this->dispatch(
            phone:   $payload['phone'],
            message: "Your UlendoPay verification code is: {$payload['otp']}. Valid for 10 minutes. Do not share this code.",
            context: "otp:{$payload['phone']}"
        );
    }

    private function sendKycApproved(array $payload): void
    {
        $phone = $payload['phone'] ?? null;

        if (empty($phone)) {
            throw new \RuntimeException('KYC approved SMS missing phone number.');
        }

        $this->dispatch(
            phone:   $phone,
            message: "UlendoPay: Your identity verification has been approved. You can now send and receive money across Africa.",
            context: "kyc_approved:{$payload['user_id']}"
        );
    }

    private function sendKycRejected(array $payload): void
    {
        $phone  = $payload['phone'] ?? null;
        $reason = $payload['reason'] ?? 'Document unclear or invalid.';

        if (empty($phone)) {
            throw new \RuntimeException('KYC rejected SMS missing phone number.');
        }

        $this->dispatch(
            phone:   $phone,
            message: "UlendoPay: Your identity verification was unsuccessful. Reason: {$reason} Please resubmit via the app.",
            context: "kyc_rejected:{$payload['user_id']}"
        );
    }

    private function sendTopupCompleted(array $payload): void
    {
        $phone    = $payload['phone'] ?? null;
        $amount   = number_format((float)($payload['amount'] ?? 0), 2);
        $currency = $payload['currency'] ?? '';
        $ref      = $payload['reference'] ?? '';

        if (empty($phone)) {
            throw new \RuntimeException('Top-up completed SMS missing phone number.');
        }

        $this->dispatch(
            phone:   $phone,
            message: "UlendoPay: Your wallet has been topped up with {$amount} {$currency}. Ref: {$ref}. Your balance is now updated.",
            context: "topup_completed:{$ref}"
        );
    }

    private function sendTopupFailed(array $payload): void
    {
        $phone    = $payload['phone'] ?? null;
        $amount   = number_format((float)($payload['amount'] ?? 0), 2);
        $currency = $payload['currency'] ?? '';
        $ref      = $payload['reference'] ?? '';

        if (empty($phone)) {
            throw new \RuntimeException('Top-up failed SMS missing phone number.');
        }

        $this->dispatch(
            phone:   $phone,
            message: "UlendoPay: Your top-up of {$amount} {$currency} could not be processed. Ref: {$ref}. No money was deducted. Please try again.",
            context: "topup_failed:{$ref}"
        );
    }

    private function dispatch(string $phone, string $message, string $context): void
    {
        try {
            $result = $this->sms->send([
                'to'      => $phone,
                'message' => $message,
                'from'    => $this->from,
            ]);

            $data        = is_array($result['data']) ? (object)$result['data'] : $result['data'];
            $messageData = is_array($data->SMSMessageData) ? (object)$data->SMSMessageData : $data->SMSMessageData;
            $recipients  = $messageData->Recipients ?? [];
            $first       = is_array($recipients[0] ?? null) ? (object)$recipients[0] : ($recipients[0] ?? null);
            $status      = $first->status ?? 'Unknown';
            $messageId   = $first->messageId ?? 'Unknown';

            if ($status !== 'Success') {
                throw new \RuntimeException(
                    "Africa's Talking rejected SMS [{$context}]. Status: {$status}, MessageId: {$messageId}"
                );
            }

            Log::info("SMS sent [{$context}]", [
                'phone'      => $phone,
                'message_id' => $messageId,
                'status'     => $status,
            ]);

        } catch (\Throwable $e) {
            Log::error("SMS dispatch failed [{$context}]", [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    private function formatSenderCompletedMessage(Transaction $transaction): string
    {
        return sprintf(
            "UlendoPay: Transfer %s sent. %s %s will be received by %s. Ref: %s",
            number_format($transaction->send_amount, 2),
            number_format($transaction->receive_amount, 2),
            $transaction->receive_currency,
            $transaction->recipient->full_name,
            $transaction->reference_number
        );
    }

    private function formatRecipientCompletedMessage(Transaction $transaction): string
    {
        return sprintf(
            "UlendoPay: You have received %s %s from %s. Ref: %s",
            number_format($transaction->receive_amount, 2),
            $transaction->receive_currency,
            $transaction->sender->name ?? 'a sender',
            $transaction->reference_number
        );
    }

    private function formatRefundedMessage(Transaction $transaction): string
    {
        return sprintf(
            "UlendoPay: Transfer %s failed. %s %s has been refunded to your wallet. Ref: %s",
            $transaction->reference_number,
            number_format($transaction->send_amount, 2),
            $transaction->send_currency,
            $transaction->reference_number
        );
    }

    private function formatFailedMessage(Transaction $transaction): string
    {
        return sprintf(
            "UlendoPay: Transfer %s could not be completed. Your funds are safe. Ref: %s",
            number_format($transaction->send_amount, 2),
            $transaction->reference_number
        );
    }

    private function resolveSenderPhone(User $sender): string
    {
        $phone = $sender->phone ?? null;

        if (empty($phone)) {
            throw new \RuntimeException("Sender {$sender->id} has no phone number on record.");
        }

        return $this->formatPhone($phone, $sender->country_code);
    }

    private function formatPhone(string $phone, ?string $countryCode): string
    {
        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        $phone = ltrim($phone, '0');

        $dialCode = match(strtoupper($countryCode ?? '')) {
            'MWI', 'MW' => '+265',
            'TZA', 'TZ' => '+255',
            'KEN', 'KE' => '+254',
            'ZMB', 'ZM' => '+260',
            'ZAF', 'ZA' => '+27',
            'MOZ', 'MZ' => '+258',
            'BWA', 'BW' => '+267',
            'ETH', 'ET' => '+251',
            'MDG', 'MG' => '+261',
            'GHA', 'GH' => '+233',
            'UGA', 'UG' => '+256',
            'RWA', 'RW' => '+250',
            'SEN', 'SN' => '+221',
            default => throw new \RuntimeException(
                "Unknown country code for phone formatting: {$countryCode}"
            ),
        };

        return $dialCode . $phone;
    }
}
