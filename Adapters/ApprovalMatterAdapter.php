<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Adapters;

use InvalidArgumentException;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;
use MultiTenantSaas\Modules\Matter\Contracts\MatterAdapterContract;
use MultiTenantSaas\Modules\Matter\Dto\DomainReference;
use MultiTenantSaas\Modules\Matter\Dto\MatterHandler;
use MultiTenantSaas\Modules\Matter\Dto\MatterParticipantSpec;
use MultiTenantSaas\Modules\Matter\Dto\MatterProjection;

/**
 * ApprovalInstance → Matter 投影适配器（只读；不写审批表）
 *
 * 映射规则：
 *   - matters.matter_type = 'approval'，domain_ref_type = 'approval_instance'
 *   - title            ← '审批 ' + definition_key（definition_snapshot 属内部材料，不读）
 *   - status_display   ← approval_instances.status 的展示映射
 *   - result_code      ← 终态 outcome（approved/rejected/withdrawn/cancelled/expired/exempt）
 *   - result_summary   ← 固定为空：审批意见与主体快照属领域私有，不投影
 *   - 参与关系          ← initiated_by 登记为 role=initiator 的 **operator**
 *                        （S0 §7.9：initiated_by_type 尚未增量迁移，现行语义为 Operator）
 *   - 当前责任          ← 进行中 = 待审批队列（kind=queue，不指向具体审批人）。
 *                        approvers_snapshot 是领域私有的审批人快照，**不复制**：它随时可能被
 *                        下一步覆盖，且复制后 C 端会误以为「某人是固定处理人」。
 *
 * 未决语义：豁免（is_exempt）实例的展示仍按 status 投影；outcome=exempt 时 result_code
 * 取 'exempt'，public summary 留空。审批意见的公开/内部可见性分权属 WP7。
 */
final class ApprovalMatterAdapter implements MatterAdapterContract
{
    public const DOMAIN_REF_TYPE = 'approval_instance';

    public const MATTER_TYPE = 'approval';

    /** 审批实例状态 → 展示文案（投影，不是第二套状态机） */
    public const STATUS_DISPLAY = [
        'pending' => '待审批',
        'in_progress' => '审批中',
        'approved' => '已通过',
        'rejected' => '已驳回',
        'withdrawn' => '已撤回',
        'cancelled' => '已取消',
        'expired' => '已过期',
    ];

    public function domainRefType(): string
    {
        return self::DOMAIN_REF_TYPE;
    }

    public function project(DomainReference $reference): ?MatterProjection
    {
        if ($reference->type !== self::DOMAIN_REF_TYPE) {
            throw new InvalidArgumentException('approval_matter_adapter: 不支持的领域引用类型 ' . $reference->type);
        }

        if (! ctype_digit($reference->id)) {
            return null; // instance_id 为数值主键
        }

        $instance = ApprovalInstance::query()->find((int) $reference->id);

        if ($instance === null || (int) $instance->tenant_id !== $reference->tenantId) {
            return null;
        }

        $participants = [
            new MatterParticipantSpec(
                MatterParticipantSpec::ROLE_INITIATOR,
                MatterParticipantSpec::ACTOR_OPERATOR,
                (int) $instance->initiated_by,
            ),
        ];

        return new MatterProjection(
            matterType: self::MATTER_TYPE,
            title: '审批 ' . $instance->definition_key,
            statusDisplay: self::STATUS_DISPLAY[$instance->status] ?? (string) $instance->status,
            resultCode: $instance->isActive() ? null : (string) ($instance->outcome ?? $instance->status),
            resultSummary: null,
            currentHandler: $instance->isActive() ? MatterHandler::queue() : null,
            participants: $participants,
        );
    }
}
