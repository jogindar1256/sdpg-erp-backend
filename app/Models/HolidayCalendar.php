<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HolidayCalendar extends Model
{
    protected $fillable = [
        'session_year', 'name', 'type', 'leave_from', 'leave_days', 'leave_till',
        'leave_for', 'sms_alert', 'sms_days_before', 'is_active',
    ];

    protected $casts = [
        'leave_from' => 'date',
        'leave_till' => 'date',
        'is_active' => 'boolean',
    ];
}
