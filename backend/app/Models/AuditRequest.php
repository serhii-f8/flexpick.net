<?php

namespace App\Models;

use App\Constants\AuditTier;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AuditRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'email', 'repo_url', 'message', 'status', 'failure_reason', 'meta', 'metrics',
        'email_verified_at', 'marketing_consent', 'consented_at', 'free_run', 'source', 'tier', 'user_id', 'prepaid',
        'admin_context', 'pipeline_log', 'analysis_started_at', 'analysis_completed_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'metrics' => 'array',
        'email_verified_at' => 'datetime',
        'marketing_consent' => 'boolean',
        'consented_at' => 'datetime',
        'free_run' => 'boolean',
        'prepaid' => 'boolean',
        'pipeline_log' => 'array',
        'analysis_started_at' => 'datetime',
        'analysis_completed_at' => 'datetime',
        'tier' => AuditTier::class,
    ];

    /**
     * Mirror the migration's column default at the PHP layer. Without it a
     * request created but never reloaded carries a null tier, and every
     * consumer that resolves a profile from it (AuditPipeline) fails.
     */
    protected $attributes = [
        'tier' => AuditTier::DIAGNOSTIC->value,
    ];

    /**
     * All audits owned by the given user: linked by id, or submitted with
     * their email before they registered.
     *
     * @param  Builder<AuditRequest>  $query
     * @return Builder<AuditRequest>
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where(function (Builder $query) use ($user): void {
            $query->where('user_id', $user->id)->orWhere('email', $user->email);
        });
    }

    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return HasOne<AuditReport, $this>
     */
    public function report(): HasOne
    {
        return $this->hasOne(AuditReport::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appendPipelineLog(string $step, string $message): void
    {
        $log = $this->pipeline_log ?? [];
        $log[] = ['step' => $step, 'message' => $message, 'at' => now()->toIso8601String()];

        $this->forceFill(['pipeline_log' => $log])->save();
    }
}
