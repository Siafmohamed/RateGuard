<?php

namespace App\Domain\Claims\Models;

use App\Domain\Audit\Models\AuditFinding;
use App\Support\Tenancy\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recovery claim filed with the carrier for an audit finding.
 */
class Claim extends Model
{
    use BelongsToOrganization;

    protected $fillable = [
        'organization_id',
        'audit_finding_id',
        'status',
        'amount_cents',
        'submitted_at',
        'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'submitted_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function auditFinding(): BelongsTo
    {
        return $this->belongsTo(AuditFinding::class);
    }
}
