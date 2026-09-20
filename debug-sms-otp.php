<?php
/**
 * Standalone Infibrix OTP diagnostic script — NOT part of the Laravel app
 * (no bootstrap/framework), but it DOES connect to the real database now,
 * because message text + DLT template id + sender id must ALWAYS come from
 * sms_templates — never hardcoded, not even for a "just testing" send. A
 * template id or text that doesn't exactly match what's registered with the
 * telecom carrier's DLT system gets silently dropped after Infibrix's own
 * gateway already returned 200 + a msg-id — that mismatch, not a gateway
 * failure, is what "200 OK but nothing arrives on the phone" almost always
 * means. This script exists to rule the gateway itself in or out using the
 * exact same source of truth app/Services/SmsService.php uses.
 *
 * Usage:
 *   php debug-sms-otp.php 9876543210
 *   php debug-sms-otp.php 9876543210 123456      (force a specific OTP)
 *
 * Reads DB_* and SMS_BASE_URL/SMS_AUTH_KEY straight out of the .env file
 * sitting next to this script. Delete this file (or keep it off the web)
 * once you're done — it prints your sender id and DLT template id to
 * stdout, and it touches .env and the live database directly.
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

$baseUrl = $env['SMS_BASE_URL'] ?? '';
$authKey = $env['SMS_AUTH_KEY'] ?? '';
if (!$baseUrl || !$authKey) {
    fwrite(STDERR, "SMS_BASE_URL / SMS_AUTH_KEY missing from .env — nothing to test.\n");
    exit(1);
}

// ── DB connection — same source of truth as SmsService::otpTemplate() ──────
$dbHost = $env['DB_HOST'] ?? null;
$dbPort = $env['DB_PORT'] ?? '5432';
$dbName = $env['DB_DATABASE'] ?? null;
$dbUser = $env['DB_USERNAME'] ?? null;
$dbPass = $env['DB_PASSWORD'] ?? null;
if (!$dbHost || !$dbName || !$dbUser) {
    fwrite(STDERR, "DB_HOST / DB_DATABASE / DB_USERNAME missing from .env — cannot look up sms_templates.\n");
    exit(1);
}

try {
    $pdo = new PDO(
        "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (\Throwable $e) {
    fwrite(STDERR, "Could not connect to the database: " . $e->getMessage() . "\n");
    exit(1);
}

// Same resolution SmsService::sendOtp() uses when no registration context
// exists yet: the single active organization.
$org = $pdo->query("SELECT id FROM organizations WHERE is_active = true LIMIT 1")->fetch(PDO::FETCH_OBJ);
if (!$org) {
    fwrite(STDERR, "No active row in organizations — cannot resolve which sms_templates row to use.\n");
    exit(1);
}

$stmt = $pdo->prepare(
    "SELECT template, dlt_template_id, sender_id
     FROM sms_templates
     WHERE organization_id = :org_id AND event_trigger = 'otp' AND is_active = true
     LIMIT 1"
);
$stmt->execute(['org_id' => $org->id]);
$row = $stmt->fetch(PDO::FETCH_OBJ);

if (!$row) {
    fwrite(STDERR, "No active sms_templates row for organization_id={$org->id}, event_trigger='otp'.\n");
    fwrite(STDERR, "This is exactly the case SmsService::sendOtp() now refuses to send for — add/activate\n");
    fwrite(STDERR, "that row (with the DLT-approved template text + dlt_template_id + sender_id) first.\n");
    exit(1);
}
if (!$row->dlt_template_id) {
    fwrite(STDERR, "sms_templates row found but dlt_template_id is empty — cannot send without one.\n");
    exit(1);
}

$senderId   = $row->sender_id ?: ($env['SMS_SENDER_ID'] ?? null);
$templateId = $row->dlt_template_id;
$message    = str_replace(['{otp}', '{OTP}', '{#var#}'], $otp, $row->template);
$route      = $env['SMS_OTP_ROUTE'] ?? 10;

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
echo "  mobile           : " . substr($mobile, 0, 4) . str_repeat('X', max(0, strlen($mobile) - 6)) . substr($mobile, -2) . "\n";
echo "  otp              : {$otp}\n";
echo "  organization_id  : {$org->id}\n";
echo "  dlt_template_id  : {$templateId}  (from sms_templates — not .env)\n";
echo "  sender_id        : " . ($senderId ?? '(none)') . "\n";
echo "  route            : {$route}\n";
echo "  message          : {$message}\n";
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
    echo "RESULT: gateway accepted the request (200 + msg-id) — this does NOT prove\n";
    echo "        the phone will receive anything. Check the phone now. If nothing\n";
    echo "        arrives within a minute despite this \"success\" response, the most\n";
    echo "        likely cause is that dlt_template_id/message text above isn't the\n";
    echo "        exact template registered with your DLT entity for this sender id —\n";
    echo "        the carrier drops it silently at the final hop. Confirm the template\n";
    echo "        text/id/sender-id triple against your DLT portal (or ask Infibrix to\n";
    echo "        trace this exact msg-id), don't just retry.\n";
}
