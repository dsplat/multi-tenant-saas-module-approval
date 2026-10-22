<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * 审批决策（append-only）
 *
 * 一旦写入不可修改、不可删除；更正只能重新发起或管理员备注（另行审计）。
 * unique(step_id, operator_id) 保证同一审批人对同一步骤只有一条决策。
 */
class ApprovalDecision extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    public const DECISION_APPROVE = 'approve';

    public const DECISION_REJECT = 'reject';

    public const DECISIONS = [self::DECISION_APPROVE, self::DECISION_REJECT];

    public const SOURCE_CONSOLE = 'console';

    public const SOURCE_API = 'api';

    public const SOURCE_SYSTEM = 'system';

    public const SOURCES = [self::SOURCE_CONSOLE, self::SOURCE_API, self::SOURCE_SYSTEM];

    public const UPDATED_AT = null;

    protected $table = 'approval_decisions';

    protected $primaryKey = 'decision_id';

    protected $fillable = [
        'tenant_id', 'instance_id', 'step_id', 'operator_id', 'decision',
        'comment', 'source', 'ip', 'user_agent', 'request_id', 'decided_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'decided_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ApprovalInstance::class, 'instance_id', 'instance_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(ApprovalStep::class, 'step_id', 'step_id');
    }

    public function update(array $attributes = [], array $options = []): bool
    {
        throw new \LogicException('审批决策为 append-only，不允许修改');
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw new \LogicException('审批决策为 append-only，不允许修改');
        }

        return parent::save($options);
    }

    public function delete(): ?bool
    {
        throw new \LogicException('审批决策为 append-only，不允许删除');
    }

    public function forceDelete(): ?bool
    {
        throw new \LogicException('审批决策为 append-only，不允许删除');
    }
}
