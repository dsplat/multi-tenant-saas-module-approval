<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services\Bridge;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\Approval\Events\ApprovalEvent;
use MultiTenantSaas\Modules\Approval\Events\ApprovalInstanceApproved;
use MultiTenantSaas\Modules\Approval\Events\ApprovalInstanceRejected;
use MultiTenantSaas\Modules\Approval\Models\ApprovalDecision;
use MultiTenantSaas\Modules\Approval\Models\ApprovalInstance;
use MultiTenantSaas\Modules\Workflow\Contracts\WorkflowEngineContract;
use MultiTenantSaas\Modules\Workflow\Models\WorkflowExecution;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowApprovalWaitLinkService;
use MultiTenantSaas\Modules\Workflow\Services\WorkflowApprovalWaitLocator;
use MultiTenantSaas\Scopes\TenantScope;

/**
 * Approval 审批结果 → Workflow 等待恢复的事件桥（P6B / G5）。
 *
 * 只在 Approval 模块内监听自己的领域事件；Workflow 侧不反向依赖 Approval。
 * 事件只提供「租户 + 审批实例 + 结果」，桥接按审批实例定位正在等待的 workflow 执行：
 *
 * - Approved：用审批实例 + approved 结果作为幂等键调用 resumeWithSignal；动作只在
 *   引擎校验通过后由引擎继续执行，重复投递同一结果不会重复推进。
 * - Rejected：不走「继续」，也不假装取消；调用引擎 failWithSignal 把等待激活与执行
 *   落 failed（审批驳回是一次明确失败），后续节点绝不执行。
 * - 没有等待链接的审批实例保持 Approval 独立使用语义：事件被忽略，不影响审批本身。
 * - 链接存在但 Approval 账本状态与事件不一致时，按完整性冲突让执行 failed，
 *   而不是静默留在 waiting。
 *
 * 跨租户：定位器显式传事件 tenant_id；引擎恢复还会再校验当前租户上下文与执行租户一致。
 */
final class ApprovalWorkflowResumeBridge
{
    public const HANDLER_KEY = ApprovalWorkflowResumeHandler::HANDLER_KEY;

    public function __construct(
        private readonly WorkflowApprovalWaitLocator $locator,
        private readonly WorkflowApprovalWaitLinkService $links,
        private readonly WorkflowEngineContract $engine,
    ) {}

    public function handleApproved(ApprovalInstanceApproved $event): void
    {
        $link = $this->locator->locate($event->tenantId, $event->instanceId);

        if ($link === null) {
            // Approval 独立使用：没有 workflow 在等这个实例
            return;
        }

        if (! $this->instanceHasStatus($event->tenantId, $event->instanceId, ApprovalInstance::STATUS_APPROVED)) {
            $this->failWaiting(
                $event,
                $link,
                'approval_instance_mismatch',
                'Approval instance is not approved in the Approval ledger at event delivery time',
            );

            return;
        }

        $execution = $this->execution($event->tenantId, $link['execution_id']);

        if ($execution === null) {
            Log::warning('ApprovalWorkflowResumeBridge: waiting execution not found', [
                'tenant_id' => $event->tenantId,
                'instance_id' => $event->instanceId,
                'execution_id' => $link['execution_id'],
            ]);

            return;
        }

        $this->engine->resumeWithSignal(
            $execution,
            self::HANDLER_KEY,
            $link['activation'],
            $this->idempotencyKey($event, ApprovalInstance::OUTCOME_APPROVED),
            $this->signal($event, $link, ApprovalInstance::OUTCOME_APPROVED),
        );
    }

    public function handleRejected(ApprovalInstanceRejected $event): void
    {
        $link = $this->locator->locate($event->tenantId, $event->instanceId);

        if ($link === null) {
            return;
        }

        if (! $this->instanceHasStatus($event->tenantId, $event->instanceId, ApprovalInstance::STATUS_REJECTED)) {
            $this->failWaiting(
                $event,
                $link,
                'approval_instance_mismatch',
                'Approval instance is not rejected in the Approval ledger at event delivery time',
            );

            return;
        }

        $this->failWaiting(
            $event,
            $link,
            'approval_rejected',
            'Approval instance was rejected; the waiting activation must not continue',
        );
    }

    /**
     * @param  array{execution_id: int, node_key: string, activation: int}  $link
     */
    private function failWaiting(ApprovalEvent $event, array $link, string $detail, string $message): void
    {
        $execution = $this->execution($event->tenantId, $link['execution_id']);

        if ($execution === null) {
            Log::warning('ApprovalWorkflowResumeBridge: waiting execution not found', [
                'tenant_id' => $event->tenantId,
                'instance_id' => $event->instanceId,
                'execution_id' => $link['execution_id'],
                'detail' => $detail,
                'message' => $message,
            ]);

            return;
        }

        $failed = $this->engine->failWithSignal(
            $execution,
            self::HANDLER_KEY,
            $link['activation'],
            'workflow_node_failed',
            $detail,
        );

        // 只有本次失败真正落库才关闭链接；取消/已完成/并发终态不复活、不改链接
        if ($failed->status !== 'failed'
            || (string) ($failed->context['_workflow_reason_detail'] ?? '') !== $detail) {
            return;
        }

        $linkModel = $this->links->find($event->tenantId, $event->instanceId);

        if ($linkModel !== null) {
            $this->links->markRejected($linkModel);
        }
    }

    private function execution(int $tenantId, int $executionId): ?WorkflowExecution
    {
        return WorkflowExecution::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('execution_id', $executionId)
            ->first();
    }

    private function instanceHasStatus(int $tenantId, string $instanceId, string $status): bool
    {
        return ApprovalInstance::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->whereKey($instanceId)
            ->where('status', $status)
            ->exists();
    }

    private function idempotencyKey(ApprovalEvent $event, string $result): string
    {
        return 'approval:' . $event->instanceId . ':' . $result;
    }

    /**
     * @param  array{execution_id: int, node_key: string, activation: int}  $link
     * @return array<string, mixed>
     */
    private function signal(ApprovalEvent $event, array $link, string $result): array
    {
        return [
            'source' => self::HANDLER_KEY,
            'tenant_id' => $event->tenantId,
            'execution_id' => $link['execution_id'],
            'node_key' => $link['node_key'],
            'activation' => $link['activation'],
            'approval_instance_ref' => $event->instanceId,
            'approval_instance_id' => $event->instanceId,
            'approval_result' => $result,
            'result' => $result,
            'operator_id' => $this->decidingOperatorId($event, $result),
            'operator_type' => 'operator',
            'step_no' => $event->stepNo,
        ];
    }

    private function decidingOperatorId(ApprovalEvent $event, string $result): ?int
    {
        $decision = ApprovalDecision::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $event->tenantId)
            ->where('instance_id', $event->instanceId)
            ->where(
                'decision',
                $result === ApprovalInstance::OUTCOME_APPROVED
                    ? ApprovalDecision::DECISION_APPROVE
                    : ApprovalDecision::DECISION_REJECT,
            )
            ->orderByDesc('decided_at')
            ->orderByDesc('decision_id')
            ->first();

        return $decision === null ? null : (int) $decision->operator_id;
    }
}
