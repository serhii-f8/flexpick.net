<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AuditRequest extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'name', 'email', 'repo_url', 'message', 'status', 'failure_reason', 'meta', 'metrics',
    ];

    protected $casts = [
        'meta' => 'array',
        'metrics' => 'array',
    ];

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
}
