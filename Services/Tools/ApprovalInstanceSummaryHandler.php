<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Approval\Services\ApprovalQueryService;
use MultiTenantSaas\Modules\Approval\Services\Tools\Concerns\ResolvesOperatorIdentity;

/**
 * AI 工具：审批实例摘要
 *
 * 只读；主体敏感属性不返回，意见只返回当前 operator 本人提交的内容。
 */
class ApprovalInstanceSummaryHandler implements ToolHandlerContract
{
    use ResolvesOperatorIdentity;

    public function __construct(private readonly ApprovalQueryService $queries) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $operatorId = $this->currentOperatorId();

        if ($operatorId === null) {
            return ['error' => '缺少 operator 身份，无法查看审批摘要'];
        }

        $instanceId = $arguments['instance_id'] ?? null;

        if ($instanceId === null || $instanceId === '') {
            return ['error' => '缺少 instance_id'];
        }

        try {
            return $this->queries->summary($tenantId, $instanceId, $operatorId);
        } catch (\Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }
    }
}
