<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StaffAdminController extends Controller
{
    public function staffList(): JsonResponse
    {
        return response()->json([
            'staff' => User::where('is_staff', true)
                ->get(['id', 'name', 'email', 'role', 'status', 'last_login_at', 'created_at']),
        ]);
    }

    public function staffCreate(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|unique:users,email',
            'role'     => 'required|in:super_admin,kyc_reviewer,finance_officer,support_agent',
            'password' => 'required|string|min:8',
        ]);

        $user = User::create([
            'name'       => $data['name'],
            'email'      => $data['email'],
            'password'   => Hash::make($data['password']),
            'is_staff'   => true,
            'role'       => $data['role'],
            'status'     => 'active',
            'kyc_status' => 'verified',
        ]);

        AuditLog::create([
            'user_id'     => $request->user()->id,
            'action'      => 'admin.staff.created',
            'entity_type' => 'User',
            'entity_id'   => $user->id,
            'new_values'  => ['name' => $user->name, 'role' => $user->role],
            'ip_address'  => $request->ip(),
        ]);

        return response()->json([
            'message' => 'Staff account created.',
            'user'    => $user->only(['id', 'name', 'email', 'role']),
        ], 201);
    }
}
