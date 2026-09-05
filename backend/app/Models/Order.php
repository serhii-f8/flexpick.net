<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'uuid',
        'user_id',
        'status',
        'currency_id',
        'total_amount',
        'total_amount_after_discount',
        'total_discount_amount',
        'payment_provider_order_id',
        'payment_provider_id',
        'tenant_id',
        'is_local',
        'partner_tenant_id',
        'base_price_snapshot',
        'quota_snapshot',
        'subscription_id',
        'type',
        'comments',
        // Backdating a test fixture's created_at via a model update (not a
        // query-builder update) needs this listed, same as MetricData.
        'created_at',
    ];

    protected $casts = [
        'quota_snapshot' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function currency(): BelongsTo
    {
        return $this->belongsTo(Currency::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function discounts(): HasMany
    {
        return $this->hasMany(OrderDiscount::class);
    }

    public function getRouteKeyName(): string
    {
        // used to find a model by its uuid instead of its id
        return 'uuid';
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function paymentProvider(): BelongsTo
    {
        return $this->belongsTo(PaymentProvider::class);
    }

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function partnerTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'partner_tenant_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function approval(): HasOne
    {
        return $this->hasOne(OrderApproval::class);
    }
}
