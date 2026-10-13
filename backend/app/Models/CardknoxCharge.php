<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CardknoxCharge extends Model
{
    protected $fillable = [
        'transaction_id',
        'invoice_base',
        'invoice',
        'ref_num',
        'purpose',
        'period_end',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'period_end' => 'datetime',
    ];

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }
}
