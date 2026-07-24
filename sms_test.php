<?php
$r = \Illuminate\Support\Facades\Http::get(
    'http://login.infibrixtechnologies.com/http-tokenkeyapi.php',
    [
        'authentic-key' => env('SMS_AUTH_KEY'),
        'senderid'      => env('SMS_SENDER_ID'),
        'route'         => 2,
        'number'        => '917897636693',
        'message'       => 'Dear Student, Your OTP is 123456. Please do not share this OTP. Regards, Swami Devanand Post Graduate College',
        'templateid'    => '1207165588316490818',
    ]
);
dump($r->status());
dump($r->body());
dump('enabled=', config('services.sms.enabled'), 'auth=', env('SMS_AUTH_KEY'), 'sender=', env('SMS_SENDER_ID'));