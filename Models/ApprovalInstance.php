<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;
use MultiTenantSaas\Context\ExecutionIdentity;

/**
 * 审批实例（审批账本）
 *
 * 发起时冻结定义关键配置与主体快照；active_unique_key 在实例进行中非空，
 * 保证同租户同主体同定义最多一个进行中实例（数据库唯一 + 事务）。
 */
class ApprovalInstance extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    public const STATUS_PENDING = 'pending';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_WITHDRAWN = 'withdrawn';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_EXPIRED = 'expired';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_WITHDRAWN,
        self::STATUS_CANCELLED,
        self::STATUS_EXPIRED,
    ];

    /** 仍在进行中的状态（可决策 / 撤回 / 取消 / 到期） */
    public const ACTIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
    ];

    public const OUTCOME_APPROVED = 'approved';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_WITHDRAWN = 'withdrawn';

    public const OUTCOME_CANCELLED = 'cancelled';

    public const OUTCOME_EXPIRED = 'expired';

    public const OUTCOME_EXEMPT = 'exempt';

    /**
     * 发起人类型（C2O v0.4 §4.1）：与 ExecutionIdentity 身份空间对齐。
     * service 是受信服务上下文、不是「一种人」（C2O §3），不作发起人类型。
     */
    public const INITIATOR_TYPES = [
        ExecutionIdentity::TYPE_USER,
        ExecutionIdentity::TYPE_OPERATOR,
    ];

    /** 存量行安全默认（C2O v0.4 §4.1）：无显式类型的旧行一律按 operator 语义解释 */
    public const INITIATOR_TYPE_DEFAULT = ExecutionIdentity::TYPE_OPERATOR;

    protected $table = 'approval_instances';

    protected $primaryKey = 'instance_id';

    protected $fillable = [
        'tenant_id', 'definition_id', 'definition_key', 'definition_version',
        'subject_type', 'subject_id', 'project_id', 'subject_snapshot',
        'definition_snapshot', 'status', 'current_step_no', 'is_exempt',
        'exemption_reason', 'initiated_by', 'initiated_by_type', 'idempotency_key',
        'previous_instance_id', 'active_unique_key', 'outcome',
        'started_at', 'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'definition_version' => 'integer',
            'project_id' => 'integer',
            'subject_snapshot' => 'array',
            'definition_snapshot' => 'array',
            'current_step_no' => 'integer',
            'is_exempt' => 'boolean',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class, 'instance_id', 'instance_id')->orderBy('step_no');
    }

    public function decisions(): HasMany
    {
        return $this->hasMany(ApprovalDecision::class, 'instance_id', 'instance_id')->orderBy('decided_at')->orderBy('decision_id');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }

    /**
     * 发起人类型（C2O v0.4 §4.1）：存量行缺失类型列时按 operator 安全默认解释，
     * 禁止猜成 User。
     */
    public function initiatedByType(): string
    {
        $type = $this->initiated_by_type;

        return $type === null || $type === '' ? self::INITIATOR_TYPE_DEFAULT : (string) $type;
    }

    /**
     * 发起人判定（类型 + ID 双匹配）：同数值 ID 不跨身份空间成立。
     */
    public function wasInitiatedBy(ExecutionIdentity $actor): bool
    {
        return $this->initiatedByType() === $actor->type
            && (string) $this->initiated_by === (string) $actor->subjectId;
    }

    public function currentStep(): ?ApprovalStep
    {
        if ($this->current_step_no === null) {
            return null;
        }

        return $this->steps()->where('step_no', $this->current_step_no)->first();
    }

    public function definitionStepConfig(int $stepNo): array
    {
        $steps = (array) (($this->definition_snapshot ?? [])['steps'] ?? []);

        foreach ($steps as $step) {
            if (is_array($step) && (int) ($step['step_no'] ?? 0) === $stepNo) {
                return $step;
            }
        }

        return [];
    }

    public static function activeUniqueKey(string $subjectType, string $subjectId, string $definitionKey): string
    {
        return $subjectType . ':' . $subjectId . ':' . $definitionKey;
    }
}
