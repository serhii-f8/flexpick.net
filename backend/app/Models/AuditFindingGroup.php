<?php

namespace App\Models;

use App\Services\AuditReport\Findings\FindingGroup;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditFindingGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'audit_request_id', 'rule_family', 'directory', 'severity', 'dimension',
        'count', 'score', 'examples', 'tools',
    ];

    protected $casts = [
        'examples' => 'array',
        'tools' => 'array',
        'count' => 'integer',
        'score' => 'integer',
    ];

    /** @return array<string, mixed> */
    public static function fromValueObject(AuditRequest $request, FindingGroup $group): array
    {
        return [
            'audit_request_id' => $request->id,
            'rule_family' => $group->ruleFamily,
            'directory' => $group->directory,
            'severity' => $group->severity->value,
            'dimension' => $group->dimension,
            'count' => $group->count,
            'score' => $group->score,
            'examples' => $group->examples,
            'tools' => $group->tools,
        ];
    }

    /** @return BelongsTo<AuditRequest, $this> */
    public function auditRequest(): BelongsTo
    {
        return $this->belongsTo(AuditRequest::class);
    }
}
