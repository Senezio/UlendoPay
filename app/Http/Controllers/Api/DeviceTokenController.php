<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token'    => 'required|string|max:512',
            'platform' => 'required|string|in:android,ios',
        ]);

        DeviceToken::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'token'   => $data['token'],
            ],
            [
                'platform'  => $data['platform'],
                'is_active' => true,
            ]
        );

        return response()->json(['message' => 'Device token registered successfully.']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string|max:512',
        ]);

        DeviceToken::where('user_id', $request->user()->id)
            ->where('token', $data['token'])
            ->update(['is_active' => false]);

        return response()->json(['message' => 'Device token deactivated successfully.']);
    }
}
