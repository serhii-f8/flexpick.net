<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditReport extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'audit_request_id', 'user_id', 'payload', 'pdf_path',
        'unlocked_at', 'unlock_order_id',
        'scoring_version', 'payload_schema_version',
    ];

    protected $casts = [
        'payload' => 'array',
        'unlocked_at' => 'datetime',
        'scoring_version' => 'integer',
        'payload_schema_version' => 'integer',
    ];

    /**
     * Mirror the migration's column defaults at the PHP layer so an in-memory
     * model built via create() (without explicitly passing these columns) is
     * already hydrated with version 1, matching what the DB would return on
     * a fresh() reload. Without this, deltasFor()'s scoring_version predicate
     * would compare against null for any report created without an explicit
     * version, silently producing no match.
     */
    protected $attributes = [
        'scoring_version' => 1,
        'payload_schema_version' => 1,
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
