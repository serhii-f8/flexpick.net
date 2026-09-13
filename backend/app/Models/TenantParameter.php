<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Workspace-scoped key/value, the tenant counterpart of UserParameter.
 * Holds what every member of a workspace shares: purchased audit credits,
 * bonus free runs and the pending checkout intent.
 */
class TenantParameter extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'name',
        'value',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
