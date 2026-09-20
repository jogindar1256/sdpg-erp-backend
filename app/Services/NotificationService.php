<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Generic "send this event_trigger's template over every channel it has"
 * sender. sms_templates is the single source of truth for wording — this
 * never hand-writes message text (same rule SmsService::otpTemplate()
 * already follows for OTP).
 *
 * SMS goes out only when the template row has a dlt_template_id (DLT
 * registration) — a template with no id is email-only by design (e.g.
 * application_hold, which has no registered DLT text yet). Email always
 * goes out, with the SAME text as the SMS, whenever the student has an
 * address on file. "Same template for both" per spec — this is the one
 * place that substitution happens, so SMS and email can never drift.
 */
class NotificationService
{
    /**
     * @param object $student      Row with at least id/mobile/email/organization_id (students table shape).
     * @param string $eventTrigger sms_templates.event_trigger
     * @param array  $vars         Values substituted into {#var#} placeholders IN ORDER.
     * @param string|null $subject Email subject override (defaults to the template's own name).
     */
    public function sendByTrigger(object $student, string $eventTrigger, array $vars = [], ?string $subject = null): void
    {
        $organizationId = $student->organization_id ?? null;
        if (empty($organizationId)) {
            Log::warning("[Notify] not sent — no organization_id resolvable for event_trigger={$eventTrigger}");
            return;
        }

        $template = DB::table('sms_templates')
            ->where('organization_id', $organizationId)
            ->where('event_trigger', $eventTrigger)
            ->where('is_active', true)
            ->first();

        if (!$template) {
            Log::error("[Notify] not sent — no active sms_templates row for event_trigger={$eventTrigger}", [
                'organization_id' => $organizationId,
            ]);
            return;
        }

        $text = $this->substitute($template->template, $vars);

        if (!empty($template->dlt_template_id) && !empty($student->mobile)) {
            app(SmsService::class)->send((string) $student->mobile, $text, $template->dlt_template_id, [
                'organization_id' => $organizationId,
                'student_id'      => $student->id ?? null,
                'template_id'     => $template->id,
                'event_trigger'   => $eventTrigger,
                'sender_id'       => $template->sender_id,
            ]);
        }

        if (!empty($student->email)) {
            try {
                Mail::raw($text, fn ($m) => $m->to($student->email)->subject($subject ?? ($template->name ?: 'SDPG College Notification')));
            } catch (\Throwable $e) {
                Log::error("[Notify] email send failed for event_trigger={$eventTrigger}: " . $e->getMessage());
            }
        }
    }

    /** Fill {#var#} placeholders left-to-right from $vars, in order. */
    private function substitute(string $template, array $vars): string
    {
        foreach ($vars as $v) {
            $template = preg_replace('/\{#var#\}/', (string) $v, $template, 1);
        }
        return $template;
    }
}
