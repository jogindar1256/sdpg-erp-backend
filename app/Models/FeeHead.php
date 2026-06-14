<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeeHead extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'code', 'category',
        'is_refundable', 'is_mandatory', 'description', 'is_active',
    ];

    protected $casts = [
        'is_refundable' => 'boolean',
        'is_mandatory'  => 'boolean',
        'is_active'     => 'boolean',
    ];

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function feeStructures(): HasMany
    {
        return $this->hasMany(FeeStructure::class);
    }
}