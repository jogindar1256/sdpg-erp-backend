<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SmsTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SmsTemplateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(
            SmsTemplate::where('organization_id', $request->user()->organization_id)->get()
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'required|string|max:100',
            'event_trigger'   => 'required|string|unique:sms_templates,event_trigger',
            'template'        => 'required|string',
            'dlt_template_id' => 'nullable|string',
            'sender_id'       => 'nullable|string|max:10',
        ]);

        $validated['organization_id'] = $request->user()->organization_id;
        $validated['is_active']       = true;

        return response()->json(SmsTemplate::create($validated), 201);
    }

    public function update(Request $request, SmsTemplate $smsTemplate): JsonResponse
    {
        $validated = $request->validate([
            'name'            => 'sometimes|string|max:100',
            'template'        => 'sometimes|string',
            'dlt_template_id' => 'nullable|string',
            'sender_id'       => 'nullable|string|max:10',
            'is_active'       => 'sometimes|boolean',
        ]);

        $smsTemplate->update($validated);
        return response()->json($smsTemplate);
    }

    public function destroy(SmsTemplate $smsTemplate): JsonResponse
    {
        $smsTemplate->delete();
        return response()->json(['message' => 'Template deleted.']);
    }

    // Preview rendered template with sample data
    public function preview(Request $request, SmsTemplate $smsTemplate): JsonResponse
    {
        $sample = [
            'student_name'  => 'Rahul Kumar',
            'enrollment_no' => 'SDPG-2024-00123',
            'program'       => 'Bachelor of Arts',
            'semester'      => '1',
            'academic_year' => '2024-25',
            'receipt_no'    => 'FR-1-2024-000001',
            'amount'        => '5500',
            'app_no'        => 'APP-1-202425-000001',
            'exam_name'     => 'Semester I Examination',
            'form_no'       => 'EF-1-1-000001',
        ];
        return response()->json(['preview' => $smsTemplate->render($sample)]);
    }
}
