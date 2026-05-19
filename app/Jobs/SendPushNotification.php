<?php

namespace App\Jobs;

use App\Services\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries  = 3;
    public int $backoff = 30;

    public function __construct(
        private readonly int    $userId,
        private readonly string $title,
        private readonly string $message,
        private readonly array  $data = [],
    ) {}

    public function handle(NotificationService $notificationService): void
    {
        $notificationService->sendToUser($this->userId, $this->title, $this->message, $this->data);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('SendPushNotification job failed', [
            'user_id' => $this->userId,
            'title'   => $this->title,
            'message' => $exception->getMessage(),
        ]);
    }
}
