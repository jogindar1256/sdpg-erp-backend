<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Organization extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name', 'short_name', 'code', 'type', 'affiliation_no',
        'address', 'city', 'district', 'state', 'pin_code',
        'phone', 'mobile', 'email', 'website', 'logo_path',
        'principal_name', 'university_name', 'university_code', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function programs(): HasMany
    {
        return $this->hasMany(Program::class);
    }

    public function students(): HasMany
    {
        return $this->hasMany(Student::class);
    }

    public function feeHeads(): HasMany
    {
        return $this->hasMany(FeeHead::class);
    }

    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class);
    }

    public function smsTemplates(): HasMany
    {
        return $this->hasMany(SmsTemplate::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
