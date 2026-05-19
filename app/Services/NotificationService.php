<?php

namespace App\Services;

use App\Models\DeviceToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class NotificationService
{
    private string $appId;
    private string $apiKey;
    private string $url;

    public function __construct()
    {
        $this->appId  = config('onesignal.app_id');
        $this->apiKey = config('onesignal.api_key');
        $this->url    = config('onesignal.url');
    }

    public function sendToUser(int $userId, string $title, string $message, array $data = []): void
    {
        $tokens = DeviceToken::where('user_id', $userId)
            ->where('is_active', true)
            ->pluck('token')
            ->toArray();

        if (empty($tokens)) {
            return;
        }

        $this->dispatch($tokens, $title, $message, $data);
    }

    public function sendToUsers(array $userIds, string $title, string $message, array $data = []): void
    {
        $tokens = DeviceToken::whereIn('user_id', $userIds)
            ->where('is_active', true)
            ->pluck('token')
            ->toArray();

        if (empty($tokens)) {
            return;
        }

        $this->dispatch($tokens, $title, $message, $data);
    }

    private function dispatch(array $tokens, string $title, string $message, array $data): void
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Key ' . $this->apiKey,
                'Content-Type'  => 'application/json',
            ])->post($this->url, [
                'app_id'             => $this->appId,
                'include_player_ids' => $tokens,
                'headings'           => ['en' => $title],
                'contents'           => ['en' => $message],
                'data'               => $data,
            ]);

            $success = $response->successful();
            if ($success === false) {
                Log::warning('OneSignal notification failed', [
                    'status'   => $response->status(),
                    'response' => $response->json(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('OneSignal notification exception', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }
}
