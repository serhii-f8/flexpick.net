<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditFunnelEvent extends Model
{
    const UPDATED_AT = null;

    protected $fillable = ['audit_request_id', 'stage', 'meta'];

    protected $casts = ['meta' => 'array'];

    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class);
    }
}
