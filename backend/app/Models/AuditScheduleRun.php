<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditScheduleRun extends Model
{
    use HasFactory;

    protected $fillable = ['audit_schedule_id', 'scheduled_for', 'status', 'reason', 'audit_request_id', 'commit_sha'];

    protected $casts = ['scheduled_for' => 'date'];

    public function auditSchedule(): BelongsTo
    {
        return $this->belongsTo(AuditSchedule::class);
    }

    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class);
    }
}
