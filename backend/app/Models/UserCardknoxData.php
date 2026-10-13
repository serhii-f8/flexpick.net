<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCardknoxData extends Model
{
    protected $table = 'user_cardknox_data';

    protected $fillable = [
        'tenant_id',
        'user_id',
        'card_token',
        'card_brand',
        'card_last4',
        'card_exp',
    ];

    protected $hidden = [
        'card_token',
    ];

    protected $casts = [
        'card_token' => 'encrypted',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
