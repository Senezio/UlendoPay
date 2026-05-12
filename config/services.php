<?php

return [

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key'    => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel'              => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'pawapay' => [
        'base_url'   => env('PAWAPAY_BASE_URL', 'https://api.pawapay.io'),
        'api_token'  => env('PAWAPAY_API_TOKEN', ''),
        'public_key' => str_replace('\n', "\n", env('PAWAPAY_PUBLIC_KEY', '')),
        'timeout'    => env('PAWAPAY_TIMEOUT', 30),
        'correspondents' => [
            'MWK:AIRTEL' => 'AIRTEL_MWI', 'MWK:TNM' => 'TNM_MWI', 'MWK:TNM_MPAMBA' => 'TNM_MPAMBA_MWI',
            'TZS:VODACOM' => 'VODACOM_TZA', 'TZS:AIRTEL' => 'AIRTEL_TZA', 'TZS:TIGO' => 'TIGO_TZA', 'TZS:HALOTEL' => 'HALOTEL_TZA',
            'KES:MPESA' => 'MPESA_KEN', 'KES:MPESA_V2' => 'MPESA_V2_KEN', 'KES:AIRTEL' => 'AIRTEL_KEN',
            'ZMW:AIRTEL' => 'AIRTEL_ZMB', 'ZMW:MTN' => 'MTN_MOMO_ZMB', 'ZMW:ZAMTEL' => 'ZAMTEL_ZMB',
            'GHS:MTN' => 'MTN_MOMO_GHA', 'GHS:VODAFONE' => 'VODAFONE_GHA', 'GHS:AIRTELTIGO' => 'AIRTELTIGO_GHA',
            'UGX:MTN' => 'MTN_MOMO_UGA', 'UGX:AIRTEL' => 'AIRTEL_UGA',
            'RWF:MTN' => 'MTN_MOMO_RWA', 'RWF:AIRTEL' => 'AIRTEL_RWA',
            'MZN:VODACOM' => 'VODACOM_MOZ', 'MZN:MOVITEL' => 'MOVITEL_MOZ',
            'ETB:TELEBIRR' => 'TELEBIRR_ETH', 'ETB:MPESA' => 'MPESA_ETH',
            'XOF:ORANGE' => 'ORANGE_SEN', 'XOF:FREE' => 'FREE_SEN', 'XOF:WAVE' => 'WAVE_SEN',
            'NGN:MTN' => 'MTN_MOMO_NGA', 'NGN:AIRTEL' => 'AIRTEL_NGA',
            'XAF:MTN' => 'MTN_MOMO_CMR', 'XAF:ORANGE' => 'ORANGE_CMR',
            'CDF:VODACOM' => 'VODACOM_COD', 'CDF:AIRTEL' => 'AIRTEL_COD', 'CDF:ORANGE' => 'ORANGE_COD',
            'ZAR:MTN' => 'MTN_MOMO_ZAF',
        ],
    ],

    'mtn_momo' => [
        'base_url'     => env('MTN_MOMO_BASE_URL', 'https://sandbox.momodeveloper.mtn.com'),
        'environment'  => env('MTN_MOMO_ENVIRONMENT', 'sandbox'),
        'callback_url' => env('MTN_MOMO_CALLBACK_URL', ''),
        'collection'   => [
            'subscription_key' => env('MTN_MOMO_COLLECTION_SUBSCRIPTION_KEY', ''),
            'user_id'          => env('MTN_MOMO_COLLECTION_USER_ID', ''),
            'api_key'          => env('MTN_MOMO_COLLECTION_API_KEY', ''),
        ],
        'disbursement' => [
            'subscription_key' => env('MTN_MOMO_DISBURSEMENT_SUBSCRIPTION_KEY', ''),
            'user_id'          => env('MTN_MOMO_DISBURSEMENT_USER_ID', ''),
            'api_key'          => env('MTN_MOMO_DISBURSEMENT_API_KEY', ''),
        ],
    ],

    'africastalking' => [
        'username' => env('AT_USERNAME', ''),
        'api_key'  => env('AT_API_KEY', ''),
        'from'     => env('AT_FROM', 'UlendoPay'),
    ],

    'terrapay' => [
        'base_url' => env('TERRAPAY_BASE_URL', 'https://uat-connect.terrapay.com:21211'),
        'username' => env('TERRAPAY_USERNAME', ''),
        'password' => env('TERRAPAY_PASSWORD', ''),
        'timeout'  => env('TERRAPAY_TIMEOUT', 30),
    ],

    'forexrateapi' => [
        'base_url'      => env('FOREXRATEAPI_BASE_URL', 'https://api.forexrateapi.com/v1'),
        'api_key'       => env('FOREXRATEAPI_API_KEY', ''),
        'base_currency' => env('FOREXRATEAPI_BASE_CURRENCY', 'USD'),
        'expiry_hours'  => env('FOREXRATEAPI_EXPIRY_HOURS', 14),
    ],

    'currency_country_map' => [
        'MWK' => 'MWI', 'TZS' => 'TZA', 'KES' => 'KEN',
        'ZMW' => 'ZMB', 'GHS' => 'GHA', 'UGX' => 'UGA',
        'RWF' => 'RWA', 'MZN' => 'MOZ', 'ETB' => 'ETH',
        'XOF' => 'SEN', 'NGN' => 'NGA', 'XAF' => 'CMR',
        'ZAR' => 'ZAF', 'CDF' => 'COD',
    ],

];
