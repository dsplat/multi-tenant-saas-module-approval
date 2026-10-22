<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 审批步骤
 *
 * 审批人只在步骤激活时解析并快照；解析为空 → blocked，实例保持进行中。
 */
class ApprovalStep extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    public const MODE_ANY = 'any';

    public const MODE_ALL = 'all';

    public const MODES = [self::MODE_ANY, self::MODE_ALL];

    public const STATUS_PENDING = 'pending';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_SKIPPED = 'skipped';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_ACTIVE,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_SKIPPED,
        self::STATUS_BLOCKED,
        self::STATUS_EXPIRED,
    ];

    protected $table = 'approval_steps';

    protected $primaryKey = 'step_id';

    protected $fillable = [
        'tenant_id', 'instance_id', 'step_no', 'name', 'mode', 'status',
        'approvers_snapshot', 'activated_at', 'due_at', 'closed_at', 'blocked_reason',
    ];

    protected function casts(): array
    {
        return [
            'step_no' => 'integer',
            'approvers_snapshot' => 'array',
            'activated_at' => 'datetime',
            'due_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ApprovalInstance::class, 'instance_id', 'instance_id');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'step_id', 'step_id')->orderBy('decided_at')->orderBy('decision_id');
    }

    /**
     * @return array<int, int>
     */
    public function approverIds(): array
    {
        return array_map(static fn ($id): int => (int) $id, (array) $this->approvers_snapshot);
    }

    public function hasApprover(int $operatorId): bool
    {
        return in_array($operatorId, $this->approverIds(), true);
    }
}
