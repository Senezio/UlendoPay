<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PruneUnverifiedUsers extends Command
{
    protected $signature   = 'users:prune-unverified';
    protected $description = 'Delete user registrations that have not verified their phone within 5 minutes, freeing up the phone/email for re-registration';

    public function handle(): int
    {
        $stale = User::whereNull('phone_verified_at')
            ->where('created_at', '<', now()->subMinutes(5))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stale unverified registrations found.');
            return self::SUCCESS;
        }

        $this->info("Found {$stale->count()} stale unverified registration(s)...");

        foreach ($stale as $user) {
            try {
                DB::transaction(function () use ($user) {
                    DB::table('otp_codes')->where('user_id', $user->id)->delete();
                    $user->delete();
                });

                $this->info("Pruned unverified user #{$user->id}");
                Log::info('[PruneUnverifiedUsers] Deleted unverified registration', [
                    'user_id'    => $user->id,
                    'created_at' => $user->created_at,
                ]);

            } catch (\Throwable $e) {
                $this->error("Failed to prune user #{$user->id}: {$e->getMessage()}");
                Log::error('[PruneUnverifiedUsers] Error', [
                    'user_id' => $user->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return self::SUCCESS;
    }
}
