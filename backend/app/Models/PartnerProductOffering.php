<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PartnerProductOffering extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'one_time_product_id',
        'price',
        'quota_overrides',
        'is_enabled',
    ];

    protected $casts = [
        'quota_overrides' => 'array',
        'is_enabled' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function oneTimeProduct(): BelongsTo
    {
        return $this->belongsTo(OneTimeProduct::class);
    }
}
