<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */
    'sms' => [
        'base_url' => env('SMS_BASE_URL', 'http://login.infibrixtechnologies.com/http-tokenkeyapi.php'),
        'auth_key' => env('SMS_AUTH_KEY'),
        'sender_id' => env('SMS_SENDER_ID'),
        'route' => env('SMS_ROUTE', 2),
        'otp_route' => env('SMS_OTP_ROUTE', 2),
        'otp_template_id' => env('SMS_OTP_DLT_TEMPLATE_ID'),
        'dlr_url' => env('SMS_DLR_URL'),
        'balance_url' => env('SMS_BALANCE_URL'),
    ],

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'razorpay' => [
        'key' => env('RAZORPAY_KEY_ID'),
        'secret' => env('RAZORPAY_KEY_SECRET'),
    ],

];
