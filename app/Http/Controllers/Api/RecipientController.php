<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Partners\PawapayPartner;
use App\Models\Recipient;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class RecipientController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $recipients = $request->user()
            ->recipients()
            ->where('is_active', true)
            ->latest()
            ->paginate(20);

        return response()->json($recipients);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name'           => 'required|string|max:255',
            'phone'               => 'nullable|string|max:20',
            'country_code'        => 'required|string|size:3',
            'payment_method'      => 'required|in:mobile_money,bank_transfer,cash_pickup',
            'mobile_network'      => 'required_if:payment_method,mobile_money|nullable|string',
            'mobile_number'       => 'required_if:payment_method,mobile_money|nullable|string',
            'bank_name'           => 'required_if:payment_method,bank_transfer|nullable|string',
            'bank_account_number' => 'required_if:payment_method,bank_transfer|nullable|string',
            'bank_branch_code'    => 'nullable|string',
        ]);

        // Sanitize text fields — strip any HTML/script tags
        if (isset($data['full_name'])) {
            $data['full_name'] = strip_tags($data['full_name']);
        }

        $recipient = $request->user()->recipients()->create($data);

        return response()->json([
            'message'   => 'Recipient created.',
            'recipient' => $recipient,
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $recipient = $request->user()
            ->recipients()
            ->where('is_active', true)
            ->findOrFail($id);

        return response()->json(['recipient' => $recipient]);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $recipient = $request->user()
            ->recipients()
            ->where('is_active', true)
            ->findOrFail($id);

        $data = $request->validate([
            'full_name'           => 'sometimes|string|max:255',
            'phone'               => 'sometimes|nullable|string|max:20',
            'mobile_network'      => 'sometimes|nullable|string',
            'mobile_number'       => 'sometimes|nullable|string',
            'bank_name'           => 'sometimes|nullable|string',
            'bank_account_number' => 'sometimes|nullable|string',
            'bank_branch_code'    => 'sometimes|nullable|string',
        ]);

        $recipient->update($data);

        return response()->json([
            'message'   => 'Recipient updated.',
            'recipient' => $recipient->fresh(),
        ]);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $recipient = $request->user()
            ->recipients()
            ->where('is_active', true)
            ->findOrFail($id);

        // Soft delete — never hard delete recipients
        $recipient->update(['is_active' => false]);

        return response()->json(['message' => 'Recipient removed.']);
    }


    /**
     * Predict mobile network for a given phone number via PawaPay.
     */
    public function predictNetwork(Request $request): JsonResponse
    {
        $request->validate(['phone' => 'required|string|max:20']);

        try {
            $pawapay       = new PawapayPartner();
            $correspondent = $pawapay->predictCorrespondent($request->phone);

            if (! $correspondent) {
                return response()->json(['found' => false], 404);
            }

            $operatorMap = [
                'AIRTEL_MWI'        => 'AIRTEL',
                'TNM_MWI'           => 'TNM',
                'AIRTEL_TZA'        => 'AIRTEL',
                'HALOTEL_TZA'       => 'HALOTEL',
                'TIGO_TZA'          => 'TIGO',
                'VODACOM_TZA'       => 'VODACOM',
                'AIRTEL_OAPI_ZMB'   => 'AIRTEL',
                'MTN_MOMO_ZMB'      => 'MTN',
                'ZAMTEL_ZMB'        => 'ZAMTEL',
                'MPESA_KEN'         => 'SAFARICOM',
                'AIRTEL_RWA'        => 'AIRTEL',
                'MTN_MOMO_RWA'      => 'MTN',
                'AIRTEL_OAPI_UGA'   => 'AIRTEL',
                'MTN_MOMO_UGA'      => 'MTN',
                'AIRTELTIGO_GHA'    => 'AIRTELTIGO',
                'MTN_MOMO_GHA'      => 'MTN',
                'VODAFONE_GHA'      => 'VODAFONE',
                'MTN_MOMO_CMR'      => 'MTN',
                'ORANGE_CMR'        => 'ORANGE_CMR',
                'AIRTEL_COD'        => 'AIRTEL',
                'ORANGE_COD'        => 'ORANGE',
                'VODACOM_MPESA_COD' => 'VODACOM',
                'MOOV_BEN'          => 'MOOV',
                'MTN_MOMO_BEN'      => 'MTN',
                'ORANGE_SEN'        => 'ORANGE_SEN',
                'FREE_SEN'          => 'FREE',
                'MTN_MOMO_CIV'      => 'MTN_CIV',
                'ORANGE_CIV'        => 'ORANGE_CIV',
                'MOOV_BFA'          => 'MOOV_BFA',
                'AIRTEL_COG'        => 'AIRTEL_COG',
                'MTN_MOMO_COG'      => 'MTN_COG',
                'AIRTEL_GAB'        => 'AIRTEL_GAB',
                'VODACOM_MOZ'       => 'VODACOM',
                'ORANGE_SLE'        => 'ORANGE',
            ];

            $operator = $operatorMap[$correspondent] ?? null;

            return response()->json([
                'found'         => true,
                'correspondent' => $correspondent,
                'operator'      => $operator,
            ]);

        } catch (\Throwable $e) {
            return response()->json(['found' => false, 'error' => 'Network detection unavailable'], 422);
        }
    }
}
