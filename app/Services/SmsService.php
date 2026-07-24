<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SMS gateway integration — Infibrix Technologies (Token-Key HTTP API).
 *
 * Endpoint : http://login.infibrixtechnologies.com/http-tokenkeyapi.php
 * Params   : authentic-key, senderid, route, number, message, templateid
 *            (optional: unicode=2 for non-ASCII, time=YYYY-MM-DD hh:mma to schedule)
 * Routes   : Transactional=2, TransOTP=10, PremiumTrans=6, Promotional=1 … (OTP uses 10)
 *
 * Every attempt is recorded in sms_logs.
 *
 * ⚠️ SECURITY NOTE: the endpoint is plain http:// with the token in the query
 * string, so it still lands in server/proxy logs. A token is at least revocable
 * (unlike the account password). Prefer the https:// host if Infibrix offers one,
 * and rotate SMS_AUTH_KEY if it leaks.
 */
class SmsService
{
    /**
     * Send an OTP SMS (uses the TransOTP route). Returns true on success.
     */
    public function sendOtp(string $mobile, string|int $otp, ?object $reg = null): bool
    {
        [$text, $templateId] = $this->otpTemplate((string) $otp, $reg);

        return $this->send($mobile, $text, $templateId, [
            'organization_id' => $reg->organization_id ?? null,
            'event_trigger'   => 'otp',
            'route'           => config('services.sms.otp_route', 10),
        ]);
    }

    /**
     * Generic DLT SMS send via Infibrix http-api.php. Returns true on success.
     */
    public function send(string $mobile, string $message, ?string $templateId = null, array $meta = []): bool
    {
        $cfg = config('services.sms');

        if (empty($cfg['base_url']) || empty($cfg['auth_key'])) {
            Log::warning('SMS not sent — Infibrix token missing (SMS_BASE_URL / SMS_AUTH_KEY).');
            return false;
        }

        $number = $this->normalize($mobile);

        // Exact Infibrix token-API parameter names (note the hyphen in authentic-key).
        $params = array_filter([
            'authentic-key' => $cfg['auth_key'],
            'senderid'      => $cfg['sender_id'] ?? null,
            'route'         => $meta['route'] ?? ($cfg['route'] ?? 2),
            'number'        => $number,
            'message'       => $message,
            'templateid'    => $templateId ?: ($cfg['otp_template_id'] ?? null),
            'unicode'       => $meta['unicode'] ?? null,   // set 2 for non-ASCII (Hindi) templates
        ], fn ($v) => $v !== null && $v !== '');

        $status       = 'failed';
        $providerId   = null;
        $providerResp = null;

        try {
            $resp = Http::timeout(15)->get(rtrim($cfg['base_url'], '/'), $params);
            $providerResp = trim($resp->body());
            [$status, $providerId] = $this->interpret($resp->successful(), $providerResp, $resp->json());
            if ($status !== 'sent') {
                Log::warning("SMS send failed for {$number}: {$providerResp}");
            }
        } catch (\Throwable $e) {
            $providerResp = $e->getMessage();
            Log::error("SMS send exception for {$number}: {$providerResp}");
        }

        $this->log($number, $message, $status, $providerId, $providerResp, $meta);

        return $status === 'sent';
    }

    /**
     * Interpret Infibrix's response. The panel may return plain text
     * ("<msgid>|<number>" or an error phrase) or JSON — handle both.
     * VERIFY against a real response and tighten if needed.
     *
     * @return array{0:string,1:?string}  [status, provider_message_id]
     */
    private function interpret(bool $httpOk, string $body, ?array $json): array
    {
        if (!$httpOk) {
            return ['failed', null];
        }

        if (is_array($json) && $json !== []) {
            $ok = ($json['ErrorCode'] ?? null) === '000'
                || in_array(strtolower((string) ($json['status'] ?? $json['Status'] ?? '')), ['success', 'sent', 'ok'], true);
            $id = $json['MessageId'] ?? $json['message_id'] ?? $json['JobId']
                ?? ($json['data'][0]['MessageId'] ?? null);
            return [$ok ? 'sent' : 'failed', $id ? (string) $id : null];
        }

        // Plain-text: treat as failure if it contains a known error phrase.
        $lower = strtolower($body);
        foreach (['invalid', 'error', 'fail', 'insufficient', 'denied', 'unauthor', 'expire', 'blocked', 'missing', 'not found'] as $w) {
            if (str_contains($lower, $w)) {
                return ['failed', null];
            }
        }
        if ($body === '') {
            return ['failed', null];
        }

        // Success — capture the Infibrix message id.
        // Infibrix returns e.g. "msg-id : Nzk0MjUyNw==" (base64) on success.
        $id = null;
        if (preg_match('/msg-?id\s*[:=]\s*(\S+)/i', $body, $m)) {
            $id = $m[1];
        } elseif (preg_match('/\b(\d{5,})\b/', $body, $m)) {
            $id = $m[1];
        }
        return ['sent', $id];
    }

    /**
     * Resolve the OTP message + DLT template id. Prefers a DLT-registered row
     * in sms_templates (event_trigger = 'otp') so the text matches TRAI exactly.
     */
    private function otpTemplate(string $otp, ?object $reg): array
    {
        if ($reg && !empty($reg->organization_id)) {
            $row = DB::table('sms_templates')
                ->where('organization_id', $reg->organization_id)
                ->where('event_trigger', 'otp')
                ->where('is_active', true)
                ->first();

            if ($row) {
                $text = str_replace(['{otp}', '{OTP}', '{#var#}'], $otp, $row->template);
                return [$text, $row->dlt_template_id];
            }
        }

        // Fallback matches the registered DLT template "Otp SMS Swami Devanand Post G":
        //   "Dear Student, Your OTP is {#var#}. Please do not share this OTP.
        //    Regards, Swami Devanand Post Graduate College"
        $text = "Dear Student, Your OTP is {$otp}. Please do not share this OTP. Regards, Swami Devanand Post Graduate College";
        return [$text, config('services.sms.otp_template_id')];
    }

    /** Normalise an Indian mobile to 91XXXXXXXXXX. */
    private function normalize(string $mobile): string
    {
        $d = preg_replace('/\D+/', '', $mobile);
        if (strlen($d) === 10) {
            $d = '91' . $d;
        }
        return $d;
    }

    private function log(string $mobile, string $message, string $status, ?string $providerId, ?string $providerResp, array $meta): void
    {
        // sms_logs.organization_id is NOT NULL — skip the DB row if absent.
        if (empty($meta['organization_id'])) {
            return;
        }

        try {
            DB::table('sms_logs')->insert([
                'organization_id'     => $meta['organization_id'],
                'student_id'          => $meta['student_id'] ?? null,
                'template_id'         => $meta['template_id'] ?? null,
                'mobile'              => $mobile,
                'message'             => $message,
                'event_trigger'       => $meta['event_trigger'] ?? null,
                'status'              => $status,
                'provider_message_id' => $providerId,
                'provider_response'   => $providerResp,
                'sent_at'             => $status === 'sent' ? now() : null,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('sms_logs insert failed: ' . $e->getMessage());
        }
    }
}
