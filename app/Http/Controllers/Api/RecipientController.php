<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
}
