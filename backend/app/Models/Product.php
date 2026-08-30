<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'slug',
        'features',
        'is_popular',
        'is_default',
        'metadata',
        'reseller_quota_keys',
    ];

    protected $casts = [
        'features' => 'array',
        'metadata' => 'array',
        'reseller_quota_keys' => 'array',
    ];

    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class);
    }
}
