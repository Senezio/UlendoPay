<?php

namespace App\Services;

use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Carbon;

class IdempotencyService
{
    const LOCK_TTL = 30;

    public function acquire(string $key, string $requestHash, int $userId, string $endpoint): array
    {
        return DB::transaction(function () use ($key, $requestHash, $userId, $endpoint) {
            $record = IdempotencyKey::lockForUpdate()->where('key', $key)->first();

            if ($record) {
                if ($record->request_hash !== $requestHash) {
                    return ['status' => 'conflict'];
                }

                if ($record->status === 'completed') {
                    return [
                        'status'   => 'completed',
                        'response' => $record->response_body,
                        'http'     => $record->response_status,
                    ];
                }

                if ($record->status === 'processing' && $record->locked_until && $record->locked_until->isFuture()) {
                    return ['status' => 'locked'];
                }

                $record->update([
                    'locked_until' => Carbon::now()->addSeconds(self::LOCK_TTL),
                    'status'       => 'processing',
                ]);

                return ['status' => 'acquired', 'record' => $record];
            }

            $record = IdempotencyKey::create([
                'key'          => $key,
                'request_hash' => $requestHash,
                'user_id'      => $userId,
                'endpoint'     => $endpoint,
                'status'       => 'processing',
                'locked_until' => Carbon::now()->addSeconds(self::LOCK_TTL),
                'expires_at'   => Carbon::now()->addDays(7),
            ]);

            return ['status' => 'acquired', 'record' => $record];
        });
    }

    public function complete(IdempotencyKey $record, array $response, int $httpStatus): void
    {
        $record->update([
            'status'          => 'completed',  
            'response_body'   => $response,  
            'response_status' => $httpStatus,  
            'locked_until'    => null,
        ]);
    }

    public function release(IdempotencyKey $record): void
    {
        $record->update([
            'status'       => 'failed',  
            'locked_until' => null,
        ]);
    }

    public static function hash(string $key, array $payload): string
    {
        $sorted = $payload;
        $recursiveSort = function (&$array) use (&$recursiveSort) {
            foreach ($array as &$value) {
                if (is_array($value)) {
                    $recursiveSort($value);
                }
            }
            ksort($array);
        };
        $recursiveSort($sorted);

        return hash('sha256', $key . json_encode($sorted));
    }
}
