<?php
/**
 * Standalone Infibrix OTP diagnostic script — NOT part of the Laravel app.
 * Run it directly on the production server to see exactly what Infibrix
 * returns, with nothing else (queues, cache, routing, Log facade) in the way.
 *
 * Usage:
 *   php debug-sms-otp.php 9876543210
 *   php debug-sms-otp.php 9876543210 123456      (force a specific OTP)
 *
 * Reads the same SMS_* credentials Laravel uses, straight out of the .env
 * file sitting next to this script — nothing hardcoded, nothing to edit.
 *
 * Delete this file (or at least don't leave it web-reachable) once you're
 * done — it prints your sender ID and route to stdout, and while it masks
 * the auth key, it still touches .env directly.
 */

function loadEnv(string $path): array
{
    if (!is_file($path)) {
        fwrite(STDERR, "Could not find .env at {$path}\n");
        exit(1);
    }
    $vars = [];
    foreach (file($path) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $vars[trim($key)] = trim(trim($value), "\"'");
    }
    return $vars;
}

$env = loadEnv(__DIR__ . '/.env');

$mobileArg = $argv[1] ?? null;
if (!$mobileArg) {
    fwrite(STDERR, "Usage: php debug-sms-otp.php <10-digit-mobile> [otp]\n");
    exit(1);
}
$mobile = preg_replace('/\D+/', '', $mobileArg);
if (strlen($mobile) === 10) {
    $mobile = '91' . $mobile; // same normalize() rule as SmsService.php
}

$otp = $argv[2] ?? (string) random_int(100000, 999999);

$baseUrl    = $env['SMS_BASE_URL'] ?? '';
$authKey    = $env['SMS_AUTH_KEY'] ?? '';
$senderId   = 'SDPGCM';
$route      = 2;
$templateId = 1207165588316490818;

if (!$baseUrl || !$authKey) {
    fwrite(STDERR, "SMS_BASE_URL / SMS_AUTH_KEY missing from .env — nothing to test.\n");
    exit(1);
}

// Same fallback template text as SmsService::otpTemplate() when no
// organization-specific sms_templates row exists.
$message = "Dear Student, Your OTP is {$otp}. Please do not share this OTP. Regards,Swami Devanand Post Graduate College";

// Exact same param names/shape as app/Services/SmsService.php::send() —
// keep these two in sync if the gateway integration changes.
$params = array_filter([
    'authentic-key' => $authKey,
    'senderid'      => $senderId,
    'route'         => $route,
    'number'        => $mobile,
    'message'       => $message,
    'templateid'    => $templateId,
], fn ($v) => $v !== null && $v !== '');

echo "==================================================\n";
echo "otp sending started\n";
echo "  mobile : " . substr($mobile, 0, 4) . str_repeat('X', max(0, strlen($mobile) - 6)) . substr($mobile, -2) . "\n";
echo "  otp    : {$otp}\n";
echo "  route  : {$route}\n";
echo "==================================================\n";

$loggableParams = $params;
$loggableParams['authentic-key'] = '***' . substr($authKey, -4);
echo "calling api\n";
echo "  GET {$baseUrl}\n";
echo "  params: " . json_encode($loggableParams, JSON_PRETTY_PRINT) . "\n";
echo "--------------------------------------------------\n";

$url = rtrim($baseUrl, '/') . '?' . http_build_query($params);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
    CURLOPT_SSL_VERIFYPEER => true,
]);
$body     = curl_exec($ch);
$errno    = curl_errno($ch);
$error    = curl_error($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "api response\n";
if ($errno) {
    echo "  curl error ({$errno}): {$error}\n";
} else {
    echo "  http status : {$httpCode}\n";
    echo "  raw body    : " . trim((string) $body) . "\n";
    $json = json_decode((string) $body, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        echo "  parsed json : " . json_encode($json, JSON_PRETTY_PRINT) . "\n";
    }
}
echo "==================================================\n";

// Same loose "does this look like success" heuristic as
// SmsService::interpret() — kept in sync manually since this script has no
// Laravel bootstrap to reuse the real method from.
$lower = strtolower(trim((string) $body));
$looksFailed = false;
foreach (['invalid', 'error', 'fail', 'insufficient', 'denied', 'unauthor', 'expire', 'blocked', 'missing', 'not found'] as $w) {
    if (str_contains($lower, $w)) {
        $looksFailed = true;
        break;
    }
}

if ($errno || $httpCode >= 400 || $lower === '' || $looksFailed) {
    echo "RESULT: looks like a FAILURE — the raw body above is Infibrix's own\n";
    echo "        explanation for why (bad key, wrong route, no balance, sender id\n";
    echo "        not approved, template mismatch, etc.). That's the message to send\n";
    echo "        Infibrix support if it's unclear.\n";
} else {
    echo "RESULT: looks like SUCCESS per the response text — check the phone.\n";
    echo "        If nothing arrives within a minute despite this, the failure is on\n";
    echo "        Infibrix's delivery side (DLT template mismatch, sender ID not\n";
    echo "        approved for this route/number, telecom-operator block, etc.) —\n";
    echo "        send them this exact raw response and ask them to trace it.\n";
}
