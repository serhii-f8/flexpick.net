<?php

namespace App\Models;

use App\Constants\AuditTier;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AuditSchedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'tenant_id', 'repo_url', 'frequency', 'tier', 'last_run_at',
        'branch', 'day_of_week', 'day_of_month', 'last_commit_sha',
    ];

    protected $casts = [
        'last_run_at' => 'datetime',
        'tier' => AuditTier::class,
        'day_of_week' => 'integer',
        'day_of_month' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return HasMany<AuditScheduleRun, $this> */
    public function scheduleRuns(): HasMany
    {
        return $this->hasMany(AuditScheduleRun::class);
    }
}
