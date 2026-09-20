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
        // organization_id drives which sms_templates row we're required to
        // use — $reg is null at the pre-registration stage (no
        // direct_registrations row exists yet), so fall back to the single
        // active organization, same resolution initiate() itself uses.
        $organizationId = $reg->organization_id ?? null;
        if (empty($organizationId)) {
            $org = DB::table('organizations')->where('is_active', true)->first();
            $organizationId = $org->id ?? null;
        }

        Log::info('[SMS OTP] otp sending started', [
            'mobile'          => $this->mask($mobile),
            'organization_id' => $organizationId,
        ]);

        $template = $this->otpTemplate((string) $otp, $organizationId);
        if (!$template) {
            // No hardcoded fallback — an OTP send with unregistered text/id
            // just gets silently dropped by the carrier's DLT scrubbing
            // (gateway still returns 200), which is worse than failing loud
            // here. Always source from sms_templates, never from .env/config,
            // even for a "just testing" send.
            Log::error('[SMS OTP] not sent — no active sms_templates row for event_trigger=otp', [
                'organization_id' => $organizationId,
            ]);
            return false;
        }
        [$text, $templateId, $senderId] = $template;

        return $this->send($mobile, $text, $templateId, [
            'organization_id' => $organizationId,
            'event_trigger'   => 'otp',
            'route'           => config('services.sms.otp_route', 10),
            'sender_id'       => $senderId,
        ]);
    }

    /**
     * Generic DLT SMS send via Infibrix http-api.php. Returns true on success.
     *
     * Debug trail (all under the "[SMS]" log prefix so `grep '\[SMS' storage/logs/laravel.log`
     * shows the full lifecycle of one send): config check → request built →
     * calling api → api response → interpreted result. Added because failures
     * here were previously invisible — the caller only saw a generic
     * true/false with nothing about *why* a send failed (bad credentials,
     * wrong route, gateway timeout, DLT template rejection, etc.).
     */
    public function send(string $mobile, string $message, ?string $templateId = null, array $meta = []): bool
    {
        $cfg = config('services.sms');

        if (empty($cfg['base_url']) || empty($cfg['auth_key'])) {
            Log::warning('[SMS] not sent — Infibrix token missing (SMS_BASE_URL / SMS_AUTH_KEY not configured).');
            return false;
        }

        // No template id (or, for a DLT route, no text at all) is a hard stop
        // — never send without one and hope the gateway/carrier sorts it out.
        // This is what previously produced "200 OK, msg-id returned, nothing
        // ever arrives": a send going out with a template id that either
        // wasn't registered or didn't match the text, silently dropped by
        // the carrier's DLT scrubbing after Infibrix's own gateway already
        // accepted it. Always require an explicit, table-sourced id.
        if (empty($templateId)) {
            Log::error('[SMS] not sent — no DLT template id resolved (must come from sms_templates, no .env fallback).');
            $this->log($this->normalize($mobile), $message, 'failed', null, 'no template id resolved', $meta);
            return false;
        }

        $number = $this->normalize($mobile);

        // Exact Infibrix token-API parameter names (note the hyphen in authentic-key).
        // senderid prefers the sms_templates row's own sender_id (passed via
        // $meta) over the generic .env-configured one — a template registered
        // under one sender ID sent from a different one is itself a DLT
        // mismatch, same class of bug as the template id itself.
        $params = array_filter([
            'authentic-key' => $cfg['auth_key'],
            'senderid'      => $meta['sender_id'] ?? ($cfg['sender_id'] ?? null),
            'route'         => $meta['route'] ?? ($cfg['route'] ?? 2),
            'number'        => $number,
            'message'       => $message,
            'templateid'    => $templateId,
            'unicode'       => $meta['unicode'] ?? null,   // set 2 for non-ASCII (Hindi) templates
        ], fn ($v) => $v !== null && $v !== '');

        // Same params, but with the auth key redacted — safe to log (the raw
        // $params array must NEVER be logged as-is; the key is a live credential).
        $loggableParams = $params;
        if (isset($loggableParams['authentic-key'])) {
            $loggableParams['authentic-key'] = '***' . substr((string) $loggableParams['authentic-key'], -4);
        }

        Log::info('[SMS] calling api', [
            'url'    => rtrim($cfg['base_url'], '/'),
            'number' => $number,
            'params' => $loggableParams,
        ]);

        $status       = 'failed';
        $providerId   = null;
        $providerResp = null;

        try {
            $resp = Http::timeout(15)->get(rtrim($cfg['base_url'], '/'), $params);

            Log::info('[SMS] api response', [
                'number'      => $number,
                'http_status' => $resp->status(),
                'successful'  => $resp->successful(),
                'body'        => trim($resp->body()),
            ]);

            $providerResp = trim($resp->body());
            [$status, $providerId] = $this->interpret($resp->successful(), $providerResp, $resp->json());

            Log::info('[SMS] interpreted result', [
                'number'      => $number,
                'status'      => $status,
                'provider_id' => $providerId,
            ]);

            if ($status !== 'sent') {
                Log::warning("[SMS] send failed for {$number}: {$providerResp}");
            }
        } catch (\Throwable $e) {
            $providerResp = $e->getMessage();
            Log::error("[SMS] send exception for {$number}: {$providerResp}", [
                'exception_class' => get_class($e),
            ]);
        }

        $this->log($number, $message, $status, $providerId, $providerResp, $meta);

        return $status === 'sent';
    }

    /** Mask a mobile number for logs — keep first 2 + last 2 digits. */
    private function mask(string $mobile): string
    {
        $d = preg_replace('/\D+/', '', $mobile);
        return strlen($d) >= 4 ? substr($d, 0, 2) . str_repeat('X', strlen($d) - 4) . substr($d, -2) : $d;
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
     * Resolve the OTP message + DLT template id + sender id — ALWAYS from
     * sms_templates (event_trigger = 'otp', is_active = true) for the given
     * organization. No hardcoded/.env fallback: a template ID or message
     * text that doesn't exactly match what's registered with the telecom
     * carrier's DLT system gets silently dropped after the gateway itself
     * already returned success, which is exactly the failure mode this was
     * built to rule out. Returns null if no such row exists — the caller
     * must fail the send rather than guess.
     *
     * @return array{0:string,1:?string,2:?string}|null [text, dlt_template_id, sender_id]
     */
    private function otpTemplate(string $otp, ?int $organizationId): ?array
    {
        if (empty($organizationId)) {
            return null;
        }

        $row = DB::table('sms_templates')
            ->where('organization_id', $organizationId)
            ->where('event_trigger', 'otp')
            ->where('is_active', true)
            ->first();

        if (!$row) {
            return null;
        }

        $text = str_replace(['{otp}', '{OTP}', '{#var#}'], $otp, $row->template);
        return [$text, $row->dlt_template_id, $row->sender_id];
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
