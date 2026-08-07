<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditEmailLog extends Model
{
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_BOUNCED = 'bounced';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'audit_request_id', 'mailable', 'recipient', 'subject', 'body',
        'status', 'attempts', 'last_error', 'sent_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<AuditRequest, $this>
     */
    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class);
    }

    /**
     * Messages that were attempted and did not land, within the window.
     *
     * @param  Builder<AuditEmailLog>  $query
     * @return Builder<AuditEmailLog>
     */
    public function scopeFailedWithin(Builder $query, ?int $hours = null): Builder
    {
        return $query
            ->whereIn('status', [self::STATUS_FAILED, self::STATUS_BOUNCED])
            ->attemptedWithin($hours);
    }

    /**
     * The delivery-rate denominator: everything actually sent in the window.
     * A row with a null sent_at has not been attempted, so it belongs to
     * neither the numerator nor the denominator.
     *
     * @param  Builder<AuditEmailLog>  $query
     * @return Builder<AuditEmailLog>
     */
    public function scopeAttemptedWithin(Builder $query, ?int $hours = null): Builder
    {
        $hours ??= (int) config('health.flexpick.mail_failure.window_hours');

        return $query
            ->whereNotNull('sent_at')
            ->where('sent_at', '>=', now()->subHours($hours));
    }
}
