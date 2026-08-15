<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StateSecurityDeposit extends Model
{
    protected $fillable = ['state_name', 'deposit_required', 'amount'];

    protected $casts = [
        'deposit_required' => 'boolean',
    ];
}
