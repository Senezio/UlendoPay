<?php

namespace App\Services\Withdrawals;

use App\Models\PartnerOperator;
use Illuminate\Support\Facades\Log;

class CorrespondentResolver
{
    public function resolve(string $currency, string $mobileOperator): ?string
    {
        $operator = PartnerOperator::active()
            ->forPayout()
            ->forCurrency($currency)
            ->whereRaw('UPPER(correspondent) LIKE ?', ["%{$mobileOperator}%"])
            ->first();

        if (!$operator) {
            Log::warning('[CorrespondentResolver] No correspondent found', [
                'currency' => $currency,
                'operator' => $mobileOperator,
            ]);
            return null;
        }

        return $operator->correspondent;
    }

    public function getSupportedOperators(string $currency): array
    {
        return PartnerOperator::active()
            ->forPayout()
            ->forCurrency($currency)
            ->pluck('correspondent')
            ->map(fn($c) => $this->extractOperatorName($c))
            ->unique()
            ->values()
            ->toArray();
    }

    public function getLimit(string $currency, string $correspondent, string $operationType = 'PAYOUT'): ?array
    {
        $operator = PartnerOperator::active()
            ->forCurrency($currency)
            ->where('correspondent', $correspondent)
            ->where('operation_type', $operationType)
            ->first();

        if (!$operator) return null;

        return [
            'min' => $operator->min_amount,
            'max' => $operator->max_amount,
        ];
    }

    private function extractOperatorName(string $correspondent): string
    {
        $parts = explode('_', $correspondent);
        return $parts[0];
    }
}
