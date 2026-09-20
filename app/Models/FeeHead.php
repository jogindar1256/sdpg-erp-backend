<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeeHead extends Model
{
    protected $fillable = [
        'organization_id', 'name', 'code', 'category', 'in_favor_of',
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

    // No feeStructures() hasMany — fee_structures has no fee_head_id column
    // any more (every particular lives inside a config row's amount_json,
    // keyed by this model's id). See FeeHeadController::destroy() for how
    // "is this fee head in use" is checked instead.
}