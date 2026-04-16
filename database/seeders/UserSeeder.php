<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name'              => 'Mphatso Senezio',
            'email'             => 'admin@ulendopay.com',
            'phone'             => '+265882481441',
            'country_code'      => 'MWI',
            'password'          => 'Admin@2026', 
            'pin'               => '1234',
            'status'            => 'active',
            'kyc_status'        => 'verified',
            'is_staff'          => true,
            'role'              => 'super_admin',
            'email_verified_at' => now(),
            'phone_verified_at' => now(),
            'kyc_verified_at'   => now(),
        ]);
    }
}
