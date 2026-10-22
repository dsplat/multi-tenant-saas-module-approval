<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\Approval\Services\Tools;

use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;
use MultiTenantSaas\Modules\Approval\Services\ApprovalQueryService;
use MultiTenantSaas\Modules\Approval\Services\Tools\Concerns\ResolvesOperatorIdentity;

/**
 * AI 工具：我的待办
 *
 * 只读、硬性 self-scope：operator 只取当前步骤快照中包含自己的实例；
 * 不返回审批意见、主体属性快照或审批人列表。
 */
class ApprovalMyPendingHandler implements ToolHandlerContract
{
    use ResolvesOperatorIdentity;

    public function __construct(private readonly ApprovalQueryService $queries) {}

    public function __invoke(array $arguments, int $tenantId): mixed
    {
        $operatorId = $this->currentOperatorId();

        if ($operatorId === null) {
            return ['error' => '缺少 operator 身份，无法查询待办'];
        }

        $perPage = max(1, min(50, (int) ($arguments['per_page'] ?? 10)));
        $page = max(1, (int) ($arguments['page'] ?? 1));

        try {
            $paginator = $this->queries->pendingFor($tenantId, $operatorId, [
                'subject_type' => $arguments['subject_type'] ?? null,
                'definition_key' => $arguments['definition_key'] ?? null,
            ], $perPage);

            $items = [];
            foreach ($paginator->items() as $instance) {
                $snapshot = (array) ($instance->subject_snapshot ?? []);
                $step = $instance->currentStep();

                $items[] = [
                    'instance_id' => $instance->instance_id,
                    'definition_key' => $instance->definition_key,
                    'definition_version' => $instance->definition_version,
                    'subject_type' => $instance->subject_type,
                    'subject_id' => $instance->subject_id,
                    'title' => $snapshot['title'] ?? null,
                    'current_step_no' => $instance->current_step_no,
                    'current_step_name' => $step?->name,
                    'due_at' => $step?->due_at?->format('Y-m-d H:i:s'),
                    'started_at' => $instance->started_at?->format('Y-m-d H:i:s'),
                ];
            }

            return [
                'items' => $items,
                'total' => $paginator->total(),
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
            ];
        } catch (\Throwable $exception) {
            return ['error' => $exception->getMessage()];
        }
    }
}
