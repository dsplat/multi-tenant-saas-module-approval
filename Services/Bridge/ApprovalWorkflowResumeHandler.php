<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services\Bridge;

use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;
use MultiTenantSaas\Modules\Workflow\Contracts\WorkflowNodeActivation;
use MultiTenantSaas\Modules\Workflow\Contracts\WorkflowResumeHandlerContract;
use MultiTenantSaas\Modules\Workflow\Exceptions\WorkflowRejectedException;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowApprovalWaitLinkService;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * Approval → Workflow 恢复 handler（P6B / G5）。
 *
 * 这是 Workflow 等待契约在 Approval 侧的实现（handler key = approval）。引擎在完成
 * 租户/执行/等待态/激活/幂等校验后至多调用本方法一次；本方法再做一次来源核对：
 *
 * 1. 可信信号必须带 approval 实例引用与 approved 结果；
 * 2. 该审批实例在「激活所属租户」内必须存在一条 workflow 等待链接，且
 *    execution_id / node_key / activation 与引擎给的服务端激活引用完全一致
 *    （实例不匹配、跨执行冒用一律拒绝）；
 * 3. Approval 账本里的实例状态必须确为 approved（事件/信号不是审批证据，账本才是）；
 * 4. 通过后把链接标为 resumed（同一幂等键重复调用幂等，不同键拒绝）。
 *
 * 返回的 context 补丁只含业务字段，不写 `_workflow_*`，也不依赖 `_confirmed`
 * （引擎在调用 handler 前已剥离）。
 */
final class ApprovalWorkflowResumeHandler implements WorkflowResumeHandlerContract
{
    public const HANDLER_KEY = 'approval';

    public function __construct(
        private readonly WorkflowApprovalWaitLinkService $links,
    ) {}

    public function resume(
        string $executionRef,
        WorkflowNodeActivation $nodeActivation,
        array $trustedSignal,
        string $idempotencyKey,
    ): array {
        if (! is_numeric($executionRef)) {
            throw new WorkflowRejectedException(
                'workflow_resume_conflict',
                'execution_ref_invalid',
                'Approval resume handler received a non-numeric execution reference',
            );
        }

        $approvalInstanceRef = $this->approvalInstanceRef($trustedSignal);

        if ($approvalInstanceRef === null) {
            throw new WorkflowRejectedException(
                'workflow_resume_conflict',
                'approval_instance_ref_missing',
                'Approval resume signal carries no approval instance reference',
            );
        }

        $link = $this->links->find($nodeActivation->tenantId, $approvalInstanceRef);

        if ($link === null) {
            throw new WorkflowRejectedException(
                'workflow_resume_conflict',
                'approval_wait_link_missing',
                'No workflow waiting link exists for this approval instance in the activation tenant',
            );
        }

        if ((int) $link->execution_id !== (int) $executionRef
            || (string) $link->node_key !== $nodeActivation->nodeKey
            || (int) $link->activation !== $nodeActivation->activation) {
            throw new WorkflowRejectedException(
                'workflow_resume_conflict',
                'approval_wait_link_mismatch',
                'Approval waiting link does not match the engine activation reference',
            );
        }

        $result = $trustedSignal['approval_result'] ?? $trustedSignal['result'] ?? null;

        if ($result !== ApprovalInstance::OUTCOME_APPROVED) {
            throw new WorkflowRejectedException(
                'workflow_resume_conflict',
                'approval_result_not_approved',
                'Approval resume signal does not carry an approved result',
            );
        }

        $instance = ApprovalInstance::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $nodeActivation->tenantId)
            ->whereKey($approvalInstanceRef)
            ->first();

        if ($instance === null) {
            throw new WorkflowRejectedException(
                'workflow_resume_conflict',
                'approval_instance_missing',
                'Approval instance does not exist in the activation tenant',
            );
        }

        if ((string) $instance->status !== ApprovalInstance::STATUS_APPROVED) {
            throw new WorkflowRejectedException(
                'workflow_resume_conflict',
                'approval_instance_not_approved',
                'Approval instance is not approved in the Approval ledger',
            );
        }

        // 幂等：同一 activation 只允许一个恢复键消费链接；同键重复为幂等 no-op
        $this->links->markResumed($link, $idempotencyKey);

        $operatorId = $trustedSignal['operator_id'] ?? null;

        return [
            'approval_instance_ref' => (string) $instance->instance_id,
            'approval_result' => ApprovalInstance::OUTCOME_APPROVED,
            'approval_decided_by' => is_numeric($operatorId) ? (int) $operatorId : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $trustedSignal
     */
    private function approvalInstanceRef(array $trustedSignal): ?string
    {
        foreach (WorkflowApprovalWaitLinkService::REFERENCE_KEYS as $key) {
            $value = $trustedSignal[$key] ?? null;

            if (is_int($value)) {
                return $value > 0 ? (string) $value : null;
            }

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }
}
