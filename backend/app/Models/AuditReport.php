<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditReport extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = ['audit_request_id', 'user_id', 'payload', 'pdf_path', 'unlocked_at', 'unlock_order_id'];

    protected $casts = [
        'payload' => 'array',
        'unlocked_at' => 'datetime',
    ];

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
